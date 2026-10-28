// Dev helper: log in to wp-admin and screenshot every plugin tab (not part of `npm run e2e`).
// usage: SCCM_E2E_URL=http://127.0.0.1:8080 node tests/e2e/admin-shots.mjs <outdir>
import { chromium } from 'playwright';
const BASE = (process.env.SCCM_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const out = process.argv[2] || '.';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }).catch(() => chromium.launch());
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
await page.goto(BASE + '/wp-login.php');
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
for (const tab of ['dashboard', 'cookies', 'banner', 'settings', 'records', 'tools', 'help']) {
	const res = await page.goto(`${BASE}/wp-admin/admin.php?page=sccm&tab=${tab}`);
	await page.waitForLoadState('load');
	console.log(tab, res.status());
	await page.screenshot({ path: `${out}/admin-${tab}.png`, fullPage: true });
}
// The Compliance menu opens a flyout with the tabs when hovered on another admin page.
await page.goto(`${BASE}/wp-admin/index.php`);
await page.hover('#toplevel_page_sccm > a');
await page.waitForTimeout(400);
await page.screenshot({ path: `${out}/admin-menu.png`, clip: { x: 0, y: 0, width: 520, height: 700 } });
console.log('errors:', JSON.stringify(errors));
await browser.close();
