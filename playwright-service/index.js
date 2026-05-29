const express = require('express');
const { chromium } = require('playwright');

const app = express();
app.use(express.json());

let browser;
let context;

async function initBrowser() {
    browser = await chromium.launch();
    context = await browser.newContext();
}

app.post('/navigate', async (req, res) => {
    const { url, method = 'GET', headers = {}, mode = 'html' } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'url is required' });
    }

    try {
        const page = await context.newPage();

        if (Object.keys(headers).length > 0) {
            await page.setExtraHTTPHeaders(headers);
        }

        const response = await page.goto(url, { waitUntil: 'networkidle' });

        const status = response ? response.status() : 200;
        const responseHeaders = response ? response.headers() : {};
        const html = mode === 'html' ? await page.content() : null;
        const body = mode === 'body' && response ? await response.text() : null;

        await page.close();

        res.json({ status, headers: responseHeaders, html, body });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/health', (_req, res) => res.json({ ok: true }));

const PORT = process.env.PORT || 3000;

initBrowser().then(() => {
    app.listen(PORT, () => {
        console.log(`Playwright service listening on port ${PORT}`);
    });
}).catch((err) => {
    console.error('Failed to launch browser:', err);
    process.exit(1);
});

process.on('SIGTERM', async () => {
    await browser?.close();
    process.exit(0);
});
