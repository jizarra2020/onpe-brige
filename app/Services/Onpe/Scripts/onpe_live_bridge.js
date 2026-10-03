import puppeteer from 'puppeteer-core';
import fs from 'fs';
import os from 'os';
import path from 'path';

function findChromePath() {
    const candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        process.env.CHROME_BIN,
        process.env.PUPPETEER_EXECUTABLE_PATH,
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium'
    ].filter(Boolean);

    for (const p of candidates) {
        if (fs.existsSync(p)) {
            return p;
        }
    }
    return 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
}

export async function fetchOnpeLive(dni) {
    const cleanDni = String(dni).replace(/[^0-9]/g, '');
    if (cleanDni.length !== 8) {
        return {
            success: false,
            message: 'El DNI debe tener exactamente 8 dígitos numéricos.'
        };
    }

    const chromePath = findChromePath();
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'onpe-browser-'));

    let browser = null;
    try {
        browser = await puppeteer.launch({
            executablePath: chromePath,
            userDataDir: tempDir,
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-blink-features=AutomationControlled',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
                '--window-size=1366,768',
                '--lang=es-PE,es'
            ]
        });

        const page = await browser.newPage();
        await page.setViewport({ width: 1366, height: 768 });
        
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            window.chrome = { runtime: {} };
            Object.defineProperty(navigator, 'languages', { get: () => ['es-PE', 'es', 'en'] });
            Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
        });

        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

        let rawPayload = null;

        page.on('response', async (res) => {
            const url = res.url();
            if (url.includes('/v1/api/consulta/definitiva')) {
                try {
                    const text = await res.text();
                    const json = JSON.parse(text);
                    if (json && json.data) {
                        rawPayload = json.data;
                    }
                } catch(e) {}
            }
        });

        // 1. Navegar a /inicio
        await page.goto('https://consultaelectoral.onpe.gob.pe/inicio', {
            waitUntil: 'networkidle2',
            timeout: 25000
        });

        // 2. Esperar por el input del DNI
        const inputSelector = 'input[placeholder*="DNI"], input#mat-input-0, input[type="tel"]';
        const inputHandle = await page.waitForSelector(inputSelector, { timeout: 12000 });
        if (!inputHandle) {
            return { success: false, message: 'No se encontró el campo DNI en la ONPE.' };
        }

        await inputHandle.click();
        await inputHandle.type(cleanDni, { delay: 40 });

        // Disparar eventos reactivos de Angular
        await page.evaluate((sel, val) => {
            const el = document.querySelector(sel);
            if (el) {
                el.value = val;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }, inputSelector, cleanDni);

        await new Promise(r => setTimeout(r, 400));

        // 3. Clic en botón Consultar
        const btnSelector = 'button.button_consulta, button[name="favorito"], button.button_estilo4';
        const btn = await page.waitForSelector(btnSelector, { timeout: 4000 });
        if (btn) {
            await btn.click();
        }

        // 4. Esperar la respuesta de la API definitiva de ONPE
        for (let i = 0; i < 25; i++) {
            if (rawPayload) break;
            await new Promise(r => setTimeout(r, 400));
        }

        if (!rawPayload) {
            return {
                success: false,
                message: 'No se obtuvo respuesta del padrón electoral de la ONPE.'
            };
        }

        return {
            success: true,
            data: rawPayload
        };
    } catch (err) {
        return {
            success: false,
            message: 'Error durante la consulta a la ONPE: ' + err.message
        };
    } finally {
        if (browser) {
            try {
                await browser.close();
            } catch(e) {}
        }
        try {
            fs.rmSync(tempDir, { recursive: true, force: true });
        } catch(e) {}
    }
}

const targetDni = process.argv[2];
if (targetDni) {
    fetchOnpeLive(targetDni).then(result => {
        console.log(JSON.stringify(result));
    }).catch(err => {
        console.log(JSON.stringify({ success: false, message: err.message }));
    });
}
