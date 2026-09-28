/**
 * End-to-end checks for the 12 features against a running WordPress site that has the
 * plugin active and the test fixture from tests/e2e/fixture-trackers.php installed as a
 * must-use plugin. See tests/e2e/README.md.
 *
 *   SCCM_E2E_URL=http://127.0.0.1:8080 npm run e2e
 */
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = (process.env.SCCM_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = new URL('./output/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });

const TRACKERS = /googletagmanager\.com|facebook\.net|youtube\.com|fonts\.googleapis\.com|fonts\.gstatic\.com/;
const results = [];

function check(name, ok, detail = '') {
	results.push({ name, ok: !!ok, detail });
	console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

async function launch() {
	const opts = { headless: true };
	if (fs.existsSync('/opt/pw-browsers/chromium')) {
		try {
			return await chromium.launch(opts);
		} catch (e) {
			return chromium.launch({ ...opts, executablePath: '/opt/pw-browsers/chromium' });
		}
	}
	return chromium.launch(opts);
}

async function newPage(browser, { gpc = false, viewport } = {}) {
	const context = await browser.newContext({ viewport: viewport || { width: 1280, height: 900 } });
	if (gpc) {
		await context.addInitScript(() => {
			Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true, configurable: true });
		});
	}
	// Third-party hosts are unreachable in CI; answer them locally so scripts "load".
	const requests = [];
	await context.route(TRACKERS, (route) => {
		requests.push(route.request().url());
		const url = route.request().url();
		const type = url.includes('fonts.googleapis') ? 'text/css' : url.includes('youtube') ? 'text/html' : 'application/javascript';
		route.fulfill({ status: 200, contentType: type, body: type === 'text/html' ? '<p>video</p>' : '' });
	});
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', (err) => errors.push(err.message));
	return { context, page, requests, errors };
}

async function consentCookie(context) {
	const cookies = await context.cookies();
	const c = cookies.find((x) => x.name === 'sccm_consent');
	return c ? JSON.parse(decodeURIComponent(c.value)) : null;
}

const browser = await launch();

/* ---------------------------------------------------------------- A/B: first visit + Accept all */
{
	const { context, page, requests, errors } = await newPage(browser);
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	check('A1 banner shown on first visit', await page.isVisible('#sccm-banner'));

	const styles = await page.evaluate(() => {
		const s = (sel) => {
			const cs = getComputedStyle(document.querySelector(sel));
			return [cs.backgroundColor, cs.color, cs.fontSize, cs.fontWeight, cs.borderColor, cs.paddingTop].join('|');
		};
		return { accept: s('.sccm-btn--accept'), reject: s('.sccm-btn--reject') };
	});
	check('A1 Accept and Reject buttons have identical styling', styles.accept === styles.reject, styles.accept);

	const before = await page.evaluate(() => ({
		ga: !!window.__gaRan, fb: !!window.__fbRan, manual: !!window.__manualFunctionalRan, necessary: !!window.__necessaryRan,
		fontHref: document.getElementById('test-fonts').getAttribute('href'),
		placeholder: !!document.querySelector('.sccm-placeholder'),
		firstConsent: (window.dataLayer || []).find((e) => e && e[0] === 'consent')
	}));
	check('A2 no analytics/marketing script ran before consent', !before.ga && !before.fb && !before.manual);
	check('A2 necessary scripts still run', before.necessary);
	check('A2 no third-party requests before consent', requests.length === 0, requests.join(', '));
	check('A3 Google Fonts stylesheet blocked', before.fontHref === null);
	check('A3 YouTube iframe shows placeholder', before.placeholder);
	check('G4 Consent Mode default (denied) is the first consent command',
		before.firstConsent && before.firstConsent[1] === 'default' && before.firstConsent[2].analytics_storage === 'denied' && before.firstConsent[2].ad_storage === 'denied');
	const cookiesBefore = (await context.cookies()).map((c) => c.name);
	check('A2 no _ga/_fbp cookies before consent', !cookiesBefore.includes('_ga') && !cookiesBefore.includes('_fbp'));
	await page.screenshot({ path: OUT + 'banner-desktop.png' });

	await page.reload();
	check('A4 banner still shown after reload without a choice', await page.isVisible('#sccm-banner'));

	await page.click('.sccm-btn--accept');
	await page.waitForFunction(() => window.__gaRan && window.__fbRan && window.__manualFunctionalRan, null, { timeout: 5000 }).catch(() => {});
	const after = await page.evaluate(() => ({
		ga: !!window.__gaRan, fb: !!window.__fbRan, manual: !!window.__manualFunctionalRan,
		banner: document.getElementById('sccm-banner').hidden,
		iframe: document.getElementById('yt').getAttribute('src'),
		font: document.getElementById('test-fonts').getAttribute('href'),
		update: (window.dataLayer || []).filter((e) => e && e[0] === 'consent' && e[1] === 'update').pop()
	}));
	check('B1 Accept all: banner closes', after.banner);
	check('B1 Accept all: scripts released without reload', after.ga && after.fb && after.manual);
	check('B1 Accept all: iframe and fonts released', !!after.iframe && !!after.font);
	check('G4 Consent Mode update sent (granted)', after.update && after.update[2].analytics_storage === 'granted' && after.update[2].ad_storage === 'granted');
	const c1 = await consentCookie(context);
	check('B1 consent cookie stored (accept_all)', c1 && c1.m === 'accept_all' && c1.c.analytics === 1 && c1.c.marketing === 1);

	await page.reload();
	const returning = await page.evaluate(() => ({ banner: !!document.querySelector('#sccm-banner:not([hidden])'), ga: !!window.__gaRan }));
	check('F1 returning visitor: no banner, choice applied', !returning.banner && returning.ga);

	// E: withdraw analytics via the floating button.
	await page.click('.sccm-floating');
	await page.waitForSelector('#sccm-prefs:not([hidden])');
	const idText = await page.textContent('.sccm-consent-id');
	check('H1 consent ID shown in preferences', idText.includes(c1.id), idText);
	await page.uncheck('#sccm-cat-analytics');
	await Promise.all([page.waitForNavigation(), page.click('#sccm-prefs .sccm-btn--save')]);
	await page.waitForLoadState('load');
	const withdrawn = await page.evaluate(() => ({ ga: !!window.__gaRan, fb: !!window.__fbRan }));
	const names = (await context.cookies()).map((c) => c.name);
	check('E2 withdraw: page reloaded, analytics stopped, marketing kept', !withdrawn.ga && withdrawn.fb);
	check('E2 withdraw: _ga cookie deleted', !names.includes('_ga'), names.join(','));
	const c2 = await consentCookie(context);
	check('E2 withdraw: same consent ID kept, choice custom', c2.id === c1.id && c2.m === 'custom' && c2.c.analytics === 0);

	check('no JavaScript errors (accept flow)', errors.length === 0, errors.join(' | '));
	await context.close();
}

/* ---------------------------------------------------------------- C: Reject */
{
	const { context, page, requests } = await newPage(browser);
	await page.goto(BASE + '/');
	await page.click('.sccm-btn--reject');
	await page.waitForTimeout(500);
	const state = await page.evaluate(() => ({ ga: !!window.__gaRan, fb: !!window.__fbRan, banner: document.getElementById('sccm-banner').hidden }));
	check('C1 Reject: banner closes, nothing non-essential runs', state.banner && !state.ga && !state.fb);
	check('C1 Reject: no third-party requests', requests.length === 0, requests.join(', '));
	const c = await consentCookie(context);
	check('C2 Reject: consent cookie reject_all', c && c.m === 'reject_all' && !c.c.analytics && !c.c.marketing && !c.c.functional);
	await context.close();
}

/* ---------------------------------------------------------------- D: Manage preferences + keyboard */
{
	const { context, page } = await newPage(browser);
	await page.goto(BASE + '/');
	await page.click('.sccm-btn--manage');
	await page.waitForSelector('#sccm-prefs:not([hidden])');
	const toggles = await page.evaluate(() => ({
		necessary: [document.getElementById('sccm-cat-necessary').checked, document.getElementById('sccm-cat-necessary').disabled],
		others: ['functional', 'analytics', 'marketing'].map((k) => document.getElementById('sccm-cat-' + k).checked)
	}));
	check('D1 necessary on + locked', toggles.necessary[0] && toggles.necessary[1]);
	check('D1 other categories off by default', toggles.others.every((v) => v === false));
	const listed = await page.evaluate(() => document.querySelectorAll('#sccm-prefs .sccm-table tbody tr').length);
	check('D2 cookie lists shown per category', listed > 0, listed + ' rows');
	await page.keyboard.press('Escape');
	check('D4 Escape closes without saving', await page.isHidden('#sccm-prefs') && !(await consentCookie(context)));
	await page.click('.sccm-btn--manage');
	await page.check('#sccm-cat-analytics');
	await page.click('#sccm-prefs .sccm-btn--save');
	await page.waitForTimeout(500);
	const s = await page.evaluate(() => ({ ga: !!window.__gaRan, fb: !!window.__fbRan, manual: !!window.__manualFunctionalRan }));
	check('D3 only analytics released', s.ga && !s.fb && !s.manual);

	await page.evaluate(() => { document.getElementById('menu-cookie-link').scrollIntoView(); });
	await page.click('#menu-cookie-link');
	check('E1 menu link #sccm-preferences opens preferences', await page.isVisible('#sccm-prefs'));
	await page.keyboard.press('Escape');
	await page.click('a.sccm-open-preferences[data-sccm-open]:not(#menu-cookie-link)');
	check('E1 [sccm_cookie_settings] shortcode opens preferences', await page.isVisible('#sccm-prefs'));
	await context.close();
}

/* ---------------------------------------------------------------- Iframe placeholder button */
{
	const { context, page } = await newPage(browser);
	await page.goto(BASE + '/');
	// The fixed banner can cover the footer video in this test page; click the button directly.
	await page.locator('.sccm-placeholder .sccm-btn').evaluate((el) => el.click());
	await page.waitForTimeout(500);
	const s = await page.evaluate(() => ({ src: document.getElementById('yt').getAttribute('src'), fb: !!window.__fbRan, ga: !!window.__gaRan }));
	check('G placeholder "Allow and load" grants only that category', !!s.src && s.fb && !s.ga);
	await context.close();
}

/* ---------------------------------------------------------------- J: GPC */
{
	const { context, page, requests } = await newPage(browser, { gpc: true });
	await page.goto(BASE + '/');
	await page.waitForTimeout(800);
	const s = await page.evaluate(() => ({
		gpc: navigator.globalPrivacyControl,
		banner: !!document.querySelector('#sccm-banner:not([hidden])'),
		ga: !!window.__gaRan
	}));
	const c = await consentCookie(context);
	check('J2 GPC (scope all): no banner, nothing loads', s.gpc === true && !s.banner && !s.ga && requests.length === 0);
	check('J2 GPC recorded as choice "gpc"', c && c.m === 'gpc' && c.g === 1);
	await page.click('.sccm-floating');
	const notice = await page.textContent('#sccm-prefs .sccm-notice').catch(() => '');
	check('J2 GPC notice shown in preferences', /Global Privacy Control/.test(notice), notice);
	const locked = await page.evaluate(() => ['analytics', 'marketing'].every((k) => document.getElementById('sccm-cat-' + k).disabled));
	check('J3 GPC-covered switches cannot be enabled', locked);
	await page.click('#sccm-prefs .sccm-btn--accept');
	await page.waitForTimeout(300);
	const c2 = await consentCookie(context);
	check('J3 Accept all with GPC keeps covered categories off', c2.c.analytics === 0 && c2.c.marketing === 0);
	await context.close();
}

/* ---------------------------------------------------------------- K3: mobile */
{
	const { context, page } = await newPage(browser, { viewport: { width: 375, height: 740 } });
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
	await page.screenshot({ path: OUT + 'banner-mobile.png' });
	await page.click('.sccm-btn--manage');
	await page.screenshot({ path: OUT + 'preferences-mobile.png' });
	const box = await page.locator('.sccm-modal__dialog').boundingBox();
	check('K3 mobile: modal fits the screen', box && box.width <= 375 && box.x >= 0);
	check('K3 mobile: no horizontal scroll from the banner', !overflow);
	await context.close();
}

/* ---------------------------------------------------------------- Cookie policy page */
{
	const { context, page } = await newPage(browser);
	await page.goto(BASE + '/cookie-policy/');
	const rows = await page.locator('.sccm-policy .sccm-table tbody tr').count();
	check('10 Cookie Policy page lists cookies', rows > 0, rows + ' rows');
	await page.click('.sccm-btn--manage');
	await page.screenshot({ path: OUT + 'preferences-desktop.png' });
	await context.close();
}

await browser.close();

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
fs.writeFileSync(OUT + 'results.json', JSON.stringify(results, null, 2));
process.exit(failed.length ? 1 : 0);
