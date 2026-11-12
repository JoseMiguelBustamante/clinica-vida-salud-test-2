// Usage: CLINIC_PLAYWRIGHT_MODULE=/path/to/playwright node tests/Browser/mobile-reports.cjs FIXTURE_DIR
// Fixtures come from ReportScreenTest with CLINIC_BROWSER_FIXTURE_DIR set; no live server or data is used.
const { chromium } = require(process.env.CLINIC_PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = process.argv[2];
    assert(directory, 'Supply the generated fixture directory.');
    const browser = await chromium.launch({ headless: true });
    let failures = 0;
    try {
        for (const width of [320, 390, 430, 768, 1280]) {
            const page = await browser.newPage({ viewport: { width, height: 844 } });
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.hostname === 'clinic.test' && url.pathname === '/css/clinic.css') {
                    return route.fulfill({ contentType: 'text/css', path: path.resolve('public/css/clinic.css') });
                }
                if (url.hostname === 'clinic.test' && ['initial', 'generated'].includes(url.pathname.slice(1))) {
                    return route.fulfill({ contentType: 'text/html', path: path.join(directory, url.pathname.slice(1) + '.html') });
                }
                return route.abort();
            });
            for (const state of ['initial', 'generated']) {
                await page.goto('http://clinic.test/' + state);
                const check = async (name, run) => {
                    try { await run(); console.log(`PASS ${width} ${state}: ${name}`); }
                    catch (error) { failures++; console.error(`FAIL ${width} ${state}: ${name}: ${error.message}`); }
                };
                await check('no horizontal page overflow', async () => {
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
                });
                await check('compact mobile menu and visible desktop navigation', async () => {
                    const box = await page.locator('.app-sidebar').boundingBox();
                    if (width < 832) {
                        assert(box.height < 200, `menu consumes ${Math.round(box.height)}px before the page`);
                        const toggle = page.getByRole('checkbox', { name: 'Menú de navegación' });
                        await toggle.focus();
                        await page.keyboard.press('Space');
                        assert(await page.locator('.app-nav').isVisible());
                        assert(await page.getByRole('link', { name: 'Reportes', exact: true }).isVisible());
                        await page.keyboard.press('Space');
                        assert(!(await page.locator('.app-nav').isVisible()));
                    } else {
                        assert(await page.locator('.app-nav').isVisible());
                        assert(!(await page.locator('.app-menu-toggle').isVisible()));
                    }
                });
                await check('assistant shortcut and question are discoverable', async () => {
                    assert(await page.locator('a[href="#asistente-reporte"]').isVisible());
                    assert.equal(await page.locator('#pregunta').count(), 1);
                    await page.locator('a[href="#asistente-reporte"]').click();
                    await page.locator('#pregunta').scrollIntoViewIfNeeded();
                    assert(await page.locator('#pregunta').isVisible());
                    assert.equal(await page.locator('#pregunta').isDisabled(), false);
                    await page.locator('#pregunta').fill('¿Cuántas citas ficticias aparecen?');
                    assert.equal(await page.locator('#pregunta').inputValue(), '¿Cuántas citas ficticias aparecen?');
                    if (state === 'generated') {
                        assert(await page.evaluate(() => document.querySelector('#asistente-reporte').compareDocumentPosition(document.querySelector('.reports-result-card')) & Node.DOCUMENT_POSITION_FOLLOWING));
                    }
                });
                await check('report controls fit their container', async () => {
                    const columns = await page.locator('#reports-filter-form').evaluate(form => getComputedStyle(form).gridTemplateColumns.split(' ').length);
                    assert.equal(columns, width < 832 ? 1 : 3);
                    for (const control of await page.locator('#reports-filter-form input:not([type="hidden"]), #reports-filter-form select, .reports-ai-form textarea').all()) {
                        const bounds = await control.boundingBox();
                        assert(bounds && bounds.x >= 0 && bounds.x + bounds.width <= width + 1);
                    }
                });
                if (width === 1280) {
                    await check('navigation adapts when crossing the desktop breakpoint', async () => {
                        await page.setViewportSize({ width: 390, height: 844 });
                        assert(!(await page.locator('.app-nav').isVisible()));
                        await page.setViewportSize({ width: 1280, height: 844 });
                        assert(await page.locator('.app-nav').isVisible());
                    });
                }
                await page.screenshot({ path: path.join(directory, `${state}-${width}.png`), fullPage: state === 'initial' });
            }
            await page.close();
        }
    } finally {
        await browser.close();
    }
    console.log(`Browser failures: ${failures}`);
    process.exitCode = failures ? 1 : 0;
})().catch(error => { console.error(error.message); process.exitCode = 1; });
