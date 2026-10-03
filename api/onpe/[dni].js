import chromium from '@sparticuz/chromium-min';
import puppeteer from 'puppeteer-core';

export const config = {
  maxDuration: 30,
};

let cachedWafToken = null;
let tokenGeneratedAt = 0;
const WAF_TOKEN_TTL_MS = 20 * 60 * 1000;

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  const queryDni = req.query.dni || req.body?.dni || req.body?.numero || '';
  const cleanDni = String(queryDni).replace(/[^0-9]/g, '');

  if (cleanDni.length !== 8) {
    return res.status(400).json({
      success: false,
      message: 'El DNI debe tener exactamente 8 dígitos numéricos.'
    });
  }

  // 1. Intentar HTTP directo con WAF token en caché
  if (cachedWafToken && (Date.now() - tokenGeneratedAt < WAF_TOKEN_TTL_MS)) {
    try {
      const onpeData = await queryOnpeDirectHttp(cleanDni, cachedWafToken);
      if (onpeData) {
        return res.status(200).json({
          success: true,
          dni: cleanDni,
          data: onpeData,
          source: 'onpe_live_bridge'
        });
      }
    } catch (e) {
      cachedWafToken = null;
    }
  }

  let debugError = null;

  // 2. Consulta ONPE con Puppeteer en Vercel
  try {
    const executablePath = await chromium.executablePath(
      'https://github.com/Sparticuz/chromium/releases/download/v131.0.1/chromium-v131.0.1-pack.tar'
    );

    const browser = await puppeteer.launch({
      args: [
        ...chromium.args,
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-blink-features=AutomationControlled',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--lang=es-PE,es'
      ],
      defaultViewport: chromium.defaultViewport || { width: 1280, height: 720 },
      executablePath: executablePath,
      headless: chromium.headless ?? true,
    });

    try {
      const page = await browser.newPage();
      await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

      let definitiveData = null;
      page.on('response', async (response) => {
        if (response.url().includes('/v1/api/consulta/definitiva')) {
          try {
            const j = await response.json();
            if (j?.data) definitiveData = j.data;
          } catch (e) {}
        }
      });

      await page.goto('https://consultaelectoral.onpe.gob.pe/inicio', {
        waitUntil: 'networkidle2',
        timeout: 22000,
      });

      const cookies = await page.cookies();
      const wafCookie = cookies.find(c => c.name === 'aws-waf-token');
      if (wafCookie?.value) {
        cachedWafToken = wafCookie.value;
        tokenGeneratedAt = Date.now();
      }

      const inputSelector = 'input[placeholder*="DNI"], input#mat-input-0, input[type="tel"]';
      const input = await page.waitForSelector(inputSelector, { timeout: 8000 });
      if (input) {
        await input.click();
        await input.type(cleanDni, { delay: 30 });
        await page.evaluate((sel, val) => {
          const el = document.querySelector(sel);
          if (el) {
            el.value = val;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }, inputSelector, cleanDni);

        const btnSelector = 'button.button_consulta, button[name="favorito"], button.button_estilo4';
        const btn = await page.waitForSelector(btnSelector, { timeout: 4000 });
        if (btn) await btn.click();

        for (let i = 0; i < 25; i++) {
          if (definitiveData) break;
          await new Promise(r => setTimeout(r, 300));
        }
      }

      if (definitiveData && (definitiveData.nombres || definitiveData.localVotacion)) {
        return res.status(200).json({
          success: true,
          dni: cleanDni,
          data: formatOnpePayload(cleanDni, definitiveData),
          source: 'onpe_live_bridge'
        });
      }
    } finally {
      await browser.close().catch(() => {});
    }
  } catch (err) {
    debugError = err.message;
    console.error('Puppeteer error:', err);
  }

  // 3. Fallback de Identidad Garantizado (Reniec / APIs)
  try {
    let fallbackResp = await fetch(`https://api.apis.net.pe/v2/reniec/dni?numero=${cleanDni}`, {
      headers: { 'Accept': 'application/json', 'User-Agent': 'Mozilla/5.0' }
    });

    if (!fallbackResp.ok) {
      fallbackResp = await fetch(`https://api.apis.net.pe/v1/dni?numero=${cleanDni}`, {
        headers: { 'Accept': 'application/json', 'User-Agent': 'Mozilla/5.0' }
      });
    }

    if (fallbackResp.ok) {
      const fbJson = await fallbackResp.json();
      const nombres = (fbJson.nombres || fbJson.nombre || '').trim();
      const apPaterno = (fbJson.apellidoPaterno || fbJson.apellido_paterno || '').trim();
      const apMaterno = (fbJson.apellidoMaterno || fbJson.apellido_materno || '').trim();
      const nombreCompleto = fbJson.nombreCompleto || fbJson.nombre || `${nombres} ${apPaterno} ${apMaterno}`.trim();

      if (nombreCompleto) {
        return res.status(200).json({
          success: true,
          dni: cleanDni,
          data: {
            dni: cleanDni,
            nombre_completo: nombreCompleto,
            nombres: nombres,
            apellido_paterno: apPaterno,
            apellido_materno: apMaterno,
            departamento: fbJson.departamento || 'LIMA',
            provincia: fbJson.provincia || 'LIMA',
            distrito: fbJson.distrito || null,
            raw_payload: fbJson
          },
          source: 'onpe_identity_bridge',
          debug_puppeteer_error: debugError
        });
      }
    }
  } catch (fbErr) {}

  return res.status(200).json({
    success: false,
    dni: cleanDni,
    message: 'No se encontraron datos.',
    source: 'onpe_fallback',
    debug_puppeteer_error: debugError
  });
}

async function queryOnpeDirectHttp(dni, wafToken) {
  const headers = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept': 'application/json, text/plain, */*',
    'Content-Type': 'application/json',
    'Origin': 'https://consultaelectoral.onpe.gob.pe',
    'Referer': 'https://consultaelectoral.onpe.gob.pe/inicio',
    'Cookie': `aws-waf-token=${wafToken}`
  };

  const r1 = await fetch('https://consultaelectoral.onpe.gob.pe/v1/api/busqueda/dni', {
    method: 'POST',
    headers: headers,
    body: JSON.stringify({ numeroDocumento: dni })
  });

  if (!r1.ok) return null;
  const j1 = await r1.json();
  const token = j1?.data?.token;
  if (!token) return null;

  const r2 = await fetch('https://consultaelectoral.onpe.gob.pe/v1/api/consulta/definitiva', {
    method: 'POST',
    headers: { ...headers, 'Authorization': `Bearer ${token}` },
    body: JSON.stringify({})
  });

  if (!r2.ok) return null;
  const j2 = await r2.json();
  const data = j2?.data;
  if (!data) return null;

  return formatOnpePayload(dni, data);
}

function formatOnpePayload(dni, rawData) {
  const nombres = (rawData.nombres || '').trim();
  const apellidos = (rawData.apellidos || '').trim();
  const nombreCompleto = `${nombres} ${apellidos}`.trim();

  return {
    dni: dni,
    nombre_completo: nombreCompleto,
    nombres: nombres,
    apellidos: apellidos,
    ubigeo: rawData.ubigeo || null,
    localVotacion: rawData.localVotacion || rawData.txtCenter || null,
    direccion: rawData.direccion || null,
    referencia: rawData.referencia || null,
    mesaSufragio: rawData.mesaSufragio || null,
    orden: rawData.orden || null,
    pabellon: rawData.pabellon || null,
    piso: rawData.piso || null,
    aula: rawData.aula || null,
    cargo: rawData.cargo || null,
    miembroMesa: rawData.miembroMesa || false,
    raw_payload: rawData
  };
}
