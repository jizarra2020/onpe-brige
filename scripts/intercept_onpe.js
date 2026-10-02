import puppeteer from 'puppeteer-core';
import fs from 'fs';
import os from 'os';
import path from 'path';

function findChromePath() {
    return 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
}

async function run() {
    const chromePath = findChromePath();
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'onpe-inter-'));
    const browser = await puppeteer.launch({
        executablePath: chromePath,
        userDataDir: tempDir,
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-blink-features=AutomationControlled',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--lang=es-PE,es'
        ]
    });

    const page = await browser.newPage();
    await page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        window.chrome = { runtime: {} };
        Object.defineProperty(navigator, 'languages', { get: () => ['es-PE', 'es', 'en'] });
        Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
    });
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

    page.on('request', req => {
        if (req.url().includes('api') || req.url().includes('consulta') || req.url().includes('busqueda')) {
            console.log('--> REQUEST:', req.method(), req.url());
            if (req.postData()) {
                console.log('    PAYLOAD:', req.postData());
            }
            console.log('    HEADERS:', JSON.stringify(req.headers()));
        }
    });

    page.on('response', async res => {
        if (res.url().includes('api') || res.url().includes('consulta') || res.url().includes('busqueda')) {
            console.log('<-- RESPONSE:', res.status(), res.url(), res.headers()['content-type']);
            try {
                const text = await res.text();
                console.log('    RESPONSE BODY:', text.substring(0, 300));
            } catch(e) {}
        }
    });

    await page.goto('https://consultaelectoral.onpe.gob.pe/inicio', { waitUntil: 'networkidle2' });
    const inputSelector = 'input[placeholder*="DNI"], input#mat-input-0, input[type="tel"]';
    const input = await page.waitForSelector(inputSelector, { timeout: 12000 });
    await input.type('43567890', { delay: 40 });
    
    await page.evaluate((sel, val) => {
        const el = document.querySelector(sel);
        if (el) {
            el.value = val;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, inputSelector, '43567890');

    const btnSelector = 'button.button_consulta, button[name="favorito"], button.button_estilo4';
    const btn = await page.waitForSelector(btnSelector, { timeout: 4000 });
    if (btn) await btn.click();

    await new Promise(r => setTimeout(r, 6000));
    await browser.close();
    fs.rmSync(tempDir, { recursive: true, force: true });
}

run();
