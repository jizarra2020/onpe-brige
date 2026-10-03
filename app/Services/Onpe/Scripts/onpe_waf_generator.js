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
        if (fs.existsSync(p)) return p;
    }
    return 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
}

export async function generateWafToken() {
    const chromePath = findChromePath();
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'onpe-token-'));

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

        await page.goto('https://consultaelectoral.onpe.gob.pe/inicio', {
            waitUntil: 'networkidle2',
            timeout: 20000
        });

        const cookies = await page.cookies();
        const wafCookie = cookies.find(c => c.name === 'aws-waf-token');

        if (wafCookie && wafCookie.value) {
            return {
                success: true,
                token: wafCookie.value
            };
        }

        return {
            success: false,
            message: 'No se pudo obtener la cookie aws-waf-token'
        };
    } catch (err) {
        return {
            success: false,
            message: err.message
        };
    } finally {
        if (browser) {
            try { await browser.close(); } catch(e) {}
        }
        try { fs.rmSync(tempDir, { recursive: true, force: true }); } catch(e) {}
    }
}

if (process.argv[1] && process.argv[1].endsWith('onpe_waf_generator.js')) {
    generateWafToken().then(res => {
        console.log(JSON.stringify(res));
    }).catch(err => {
        console.log(JSON.stringify({ success: false, message: err.message }));
    });
}
