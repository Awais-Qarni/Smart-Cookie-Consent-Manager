// Dev helper: screenshots of the floating widget in every position (not part of `npm run e2e`).
// usage: SCCM_E2E_URL=http://127.0.0.1:8080 SCCM_WP="/path/wp.sh" node tests/e2e/widget-shots.mjs <outdir>
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
const BASE = (process.env.SCCM_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const WP = process.env.SCCM_WP;
const out = process.argv[2] || '.';
const positions = ['bottom-left', 'bottom-right', 'bottom-center', 'left-center', 'right-center'];
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }).catch(() => chromium.launch());
for (const pos of positions) {
	execFileSync(WP, ['option', 'patch', 'update', 'sccm_settings', 'floating_position', pos]);
	for (const [label, vp] of [['desktop', { width: 1000, height: 640 }], ['mobile', { width: 390, height: 700 }]]) {
		const ctx = await browser.newContext({ viewport: vp });
		const page = await ctx.newPage();
		await page.goto(BASE + '/');
		await page.click('.sccm-btn--reject');
		await page.waitForSelector('.sccm-floating:not([hidden])');
		await page.waitForTimeout(350);
		await page.screenshot({ path: `${out}/widget-${pos}-${label}.png` });
		if (label === 'desktop') {
			await page.hover('.sccm-floating');
			await page.waitForTimeout(350);
			await page.screenshot({ path: `${out}/widget-${pos}-hover.png`, clip: { x: 0, y: 0, width: vp.width, height: vp.height } });
		}
		const box = await page.locator('.sccm-floating').boundingBox();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
		console.log(pos, label, 'box', JSON.stringify(box && { x: Math.round(box.x), y: Math.round(box.y), w: box.width, h: box.height }), 'hscroll:', overflow);
		await ctx.close();
	}
}
await browser.close();
