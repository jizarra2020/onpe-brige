import puppeteer from 'puppeteer-core';
import fs from 'fs';
import os from 'os';
import path from 'path';
import https from 'https';
import http from 'http';

// Configuración por defecto
const DEFAULT_TARGET_URL = process.env.ONPE_TARGET_URL || 'https://bautista.edparallel.org.pe/api/onpe/sync-waf-token';
const DEFAULT_SYNC_KEY = process.env.ONPE_SYNC_KEY || 'onpe_bridge_secret_key_2026';
const INTERVAL_MINUTES = parseInt(process.env.SYNC_INTERVAL_MINUTES || '5', 10);

function findChromePath() {
    const candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        process.env.CHROME_BIN,
        process.env.PUPPETEER_EXECUTABLE_PATH,
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium'
    ].filter(Boolean);

    for (const p of candidates) {
        if (fs.existsSync(p)) return p;
    }
    return candidates[0] || 'google-chrome';
}

async function extractWafToken() {
    const chromePath = findChromePath();
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'onpe-sync-'));

    let browser = null;
    try {
        console.log(`[ONPE-SYNC] Lanzando navegador (${chromePath})...`);
        browser = await puppeteer.launch({
            executablePath: chromePath,
            userDataDir: tempDir,
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-blink-features=AutomationControlled',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-extensions',
                '--no-first-run',
                '--window-size=1024,600',
                '--lang=es-PE,es'
            ]
        });

        const page = await browser.newPage();
        await page.setViewport({ width: 1024, height: 600 });

        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            window.chrome = { runtime: {} };
            Object.defineProperty(navigator, 'languages', { get: () => ['es-PE', 'es', 'en'] });
            Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
        });

        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

        console.log('[ONPE-SYNC] Navegando a portal oficial ONPE...');
        await page.goto('https://consultaelectoral.onpe.gob.pe/inicio', {
            waitUntil: 'networkidle2',
            timeout: 25000
        });

        // Espera activa y sondeo del cookie AWS WAF
        let wafCookie = null;
        for (let i = 0; i < 15; i++) {
            const cookies = await page.cookies();
            wafCookie = cookies.find(c => c.name === 'aws-waf-token');
            if (wafCookie && wafCookie.value && wafCookie.value.length > 20) {
                break;
            }
            await new Promise(r => setTimeout(r, 600));
        }

        if (wafCookie && wafCookie.value) {
            console.log('[ONPE-SYNC] Token AWS WAF obtenido exitosamente de ONPE.');
            return wafCookie.value;
        }

        throw new Error('No se encontró la cookie aws-waf-token en la respuesta de ONPE.');
    } finally {
        if (browser) {
            try { await browser.close(); } catch (e) {}
        }
        try { fs.rmSync(tempDir, { recursive: true, force: true }); } catch (e) {}
    }
}

function postTokenToLaravel(targetUrl, syncKey, wafToken) {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({
            sync_key: syncKey,
            waf_token: wafToken,
            ttl_minutes: 30
        });

        const urlObj = new URL(targetUrl);
        const isHttps = urlObj.protocol === 'https:';
        const client = isHttps ? https : http;

        const options = {
            hostname: urlObj.hostname,
            port: urlObj.port || (isHttps ? 443 : 80),
            path: urlObj.pathname + urlObj.search,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(payload),
                'Accept': 'application/json',
                'User-Agent': 'OnpeSyncDaemon/1.0'
            },
            timeout: 10000
        };

        const req = client.request(options, (res) => {
            let body = '';
            res.on('data', chunk => body += chunk);
            res.on('end', () => {
                try {
                    const json = JSON.parse(body);
                    resolve({ statusCode: res.statusCode, body: json });
                } catch (e) {
                    resolve({ statusCode: res.statusCode, rawBody: body });
                }
            });
        });

        req.on('error', (err) => reject(err));
        req.on('timeout', () => {
            req.destroy();
            reject(new Error('Timeout enviando token a Laravel'));
        });

        req.write(payload);
        req.end();
    });
}

async function performSync(targetUrl, syncKey) {
    const startTime = Date.now();
    console.log(`[ONPE-SYNC] [${new Date().toISOString()}] Iniciando ciclo de sincronización hacia ${targetUrl}...`);

    try {
        const token = await extractWafToken();
        console.log(`[ONPE-SYNC] Enviando token WAF a cPanel...`);

        const response = await postTokenToLaravel(targetUrl, syncKey, token);
        const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);

        if (response.statusCode === 200 && response.body?.success) {
            console.log(`[ONPE-SYNC] ¡ÉXITO! Token sincronizado en cPanel en ${elapsed}s. Expira en ${response.body.expires_in_minutes} min.`);
            return true;
        } else {
            console.error(`[ONPE-SYNC] Error del servidor (${response.statusCode}):`, response.body || response.rawBody);
            return false;
        }
    } catch (err) {
        const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
        console.error(`[ONPE-SYNC] Fallo en sincronización (${elapsed}s):`, err.message);
        return false;
    }
}

async function main() {
    const args = process.argv.slice(2);
    const isDaemon = args.includes('--daemon') || args.includes('-d');
    const targetUrl = process.env.ONPE_TARGET_URL || DEFAULT_TARGET_URL;
    const syncKey = process.env.ONPE_SYNC_KEY || DEFAULT_SYNC_KEY;

    if (isDaemon) {
        console.log(`[ONPE-SYNC] Modo Daemon continuo activado. Renovando token cada ${INTERVAL_MINUTES} minutos.`);
        await performSync(targetUrl, syncKey);
        setInterval(() => {
            performSync(targetUrl, syncKey);
        }, INTERVAL_MINUTES * 60 * 1000);
    } else {
        const success = await performSync(targetUrl, syncKey);
        process.exit(success ? 0 : 1);
    }
}

main();
