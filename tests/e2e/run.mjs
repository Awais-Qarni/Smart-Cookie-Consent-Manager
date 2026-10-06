/**
 * End-to-end checks for the 12 features against a running WordPress site that has the
 * plugin active and the test fixture from tests/e2e/fixture-trackers.php installed as a
 * must-use plugin. See tests/e2e/README.md.
 *
 *   SCCM_E2E_URL=http://127.0.0.1:8080 npm run e2e
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const BASE = (process.env.SCCM_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = fileURLToPath(new URL('./output/', import.meta.url));
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

/**
 * New browser context. layout / order / position / texts: the same page with other banner
 * settings (as if chosen under Cookie Consent → Banner). css: extra page CSS, e.g. a hostile theme.
 */
async function newPage(browser, { gpc = false, viewport, layout, order, position, texts, css, path = '/' } = {}) {
	const context = await browser.newContext({ viewport: viewport || { width: 1280, height: 900 } });
	if (layout || order || position || texts || css) {
		await context.route(BASE + path, async (route) => {
			const response = await route.fetch();
			let body = await response.text();
			if (layout) {
				body = body.replace(/"layout":"[a-z]+"/, '"layout":"' + layout + '"');
			}
			if (order) {
				body = body.replace(/"order":"[a-z_]+"/, '"order":"' + order + '"');
			}
			if (position) {
				body = body.replace(/"position":"[a-z-]+"/, '"position":"' + position + '"');
			}
			for (const [key, value] of Object.entries(texts || {})) {
				body = body.replace(new RegExp('"' + key + '":"[^"]*"'), '"' + key + '":' + JSON.stringify(value));
			}
			if (css) {
				body = body.replace('</head>', '<style id="hostile-theme">' + css + '</style></head>');
			}
			await route.fulfill({ response, body });
		});
	}
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

/* ---------------------------------------------------------------- A/B: first visit + Allow all */
{
	const { context, page, requests, errors } = await newPage(browser);
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	check('A1 banner shown on first visit', await page.isVisible('#sccm-banner'));

	const styles = await page.evaluate(() => {
		const s = (sel) => {
			const cs = getComputedStyle(document.querySelector('#sccm-banner ' + sel));
			return [cs.backgroundColor, cs.color, cs.fontSize, cs.fontWeight, cs.borderColor, cs.paddingTop, cs.height].join('|');
		};
		return { accept: s('.sccm-btn--accept'), reject: s('.sccm-btn--reject') };
	});
	check('A1 Allow all and Deny look identical', styles.accept === styles.reject, styles.accept);
	const compactButtons = await page.$$eval('#sccm-banner .sccm-actions .sccm-btn', (els) => els.map((e) => e.textContent.trim()));
	check('A1 default (compact) banner: Allow all, Deny, Customize', JSON.stringify(compactButtons) === JSON.stringify(['Allow all', 'Deny', 'Customize']), compactButtons.join(','));
	check('A1 compact banner shows the title, text and policy link', /This website uses cookies/.test(await page.textContent('#sccm-banner .sccm-title')) && (await page.locator('#sccm-banner .sccm-text').count()) === 1);
	check('A1 stylesheet applied before the banner is drawn', await page.evaluate(() => document.getElementById('sccm-frontend-css').media === 'all'));

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

	await page.click('#sccm-banner .sccm-btn--accept');
	await page.waitForFunction(() => window.__gaRan && window.__fbRan && window.__manualFunctionalRan, null, { timeout: 5000 }).catch(() => {});
	const after = await page.evaluate(() => ({
		ga: !!window.__gaRan, fb: !!window.__fbRan, manual: !!window.__manualFunctionalRan,
		banner: document.getElementById('sccm-banner').hidden,
		iframe: document.getElementById('yt').getAttribute('src'),
		font: document.getElementById('test-fonts').getAttribute('href'),
		update: (window.dataLayer || []).filter((e) => e && e[0] === 'consent' && e[1] === 'update').pop()
	}));
	check('B1 Allow all: banner closes', after.banner);
	check('B1 Allow all: scripts released without reload', after.ga && after.fb && after.manual);
	check('B1 Allow all: iframe and fonts released', !!after.iframe && !!after.font);
	check('G4 Consent Mode update sent (granted)', after.update && after.update[2].analytics_storage === 'granted' && after.update[2].ad_storage === 'granted');
	check('H1 dataLayer event carries the consent ID', await page.evaluate(() => (window.dataLayer || []).some((e) => e && e.event === 'sccm_consent_update' && e.sccm_consent_id)));
	const c1 = await consentCookie(context);
	check('B1 consent cookie stored (accept_all)', c1 && c1.m === 'accept_all' && c1.c.analytics === 1 && c1.c.marketing === 1);

	await page.reload();
	const returning = await page.evaluate(() => ({ banner: !!document.querySelector('#sccm-banner:not([hidden])'), ga: !!window.__gaRan }));
	check('F1 returning visitor: no banner, choice applied', !returning.banner && returning.ga);

	// E/H: reopen with the cookie settings button, read the consent ID, withdraw Statistics.
	await page.click('.sccm-floating');
	await page.waitForSelector('#sccm-prefs:not([hidden])');
	check('E1 reopened window has a close button and starts on the Consent tab', await page.isVisible('#sccm-prefs .sccm-close') && (await page.getAttribute('#sccm-tab-consent', 'aria-selected')) === 'true');
	await page.click('#sccm-tab-about');
	const status = await page.textContent('#sccm-prefs .sccm-status');
	check('H1 About tab shows the consent ID, choice and date', status.includes(c1.id) && /Allowed all cookies/.test(status) && /Date:/.test(status), status.trim().slice(0, 90));
	check('H1 consent ID can be copied', await page.isVisible('#sccm-prefs .sccm-copy'));
	check('H1 consent ID exposed to developers (SCCM.getConsentId)', (await page.evaluate(() => window.SCCM.getConsentId())) === c1.id);
	await page.click('#sccm-tab-consent');
	await page.uncheck('#sccm-cat-analytics');
	await Promise.all([page.waitForNavigation(), page.click('#sccm-prefs .sccm-btn--selection')]);
	await page.waitForLoadState('load');
	const withdrawn = await page.evaluate(() => ({ ga: !!window.__gaRan, fb: !!window.__fbRan }));
	const names = (await context.cookies()).map((c) => c.name);
	check('E2 withdraw: page reloaded, statistics stopped, marketing kept', !withdrawn.ga && withdrawn.fb);
	check('E2 withdraw: _ga cookie deleted', !names.includes('_ga'), names.join(','));
	const c2 = await consentCookie(context);
	check('E2 withdraw: same consent ID kept, choice custom', c2.id === c1.id && c2.m === 'custom' && c2.c.analytics === 0);

	check('no JavaScript errors (accept flow)', errors.length === 0, errors.join(' | '));
	await context.close();
}

/* ---------------------------------------------------------------- M: compact banner → Customize */
{
	const { context, page, errors } = await newPage(browser);
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	await page.click('#sccm-banner .sccm-btn--customize');
	await page.waitForSelector('#sccm-prefs:not([hidden])');
	check('M1 Customize opens the full window (Consent, Details, About) and hides the banner', await page.isVisible('#sccm-prefs .sccm-tab >> nth=2') && await page.isHidden('#sccm-banner'));
	const prefsButtons = await page.$$eval('#sccm-prefs .sccm-actions .sccm-btn', (els) => els.map((e) => e.textContent.trim()));
	check('M1 the window has Allow all, Deny and Allow selection', JSON.stringify(prefsButtons) === JSON.stringify(['Allow all', 'Deny', 'Allow selection']), prefsButtons.join(','));
	await page.keyboard.press('Escape');
	check('M2 closing the window without choosing brings the banner back (no consent stored)', await page.isVisible('#sccm-banner') && !(await consentCookie(context)));
	await page.click('#sccm-banner .sccm-btn--customize');
	await page.check('#sccm-cat-functional');
	await page.click('#sccm-prefs .sccm-btn--selection');
	await page.waitForTimeout(400);
	const c = await consentCookie(context);
	check('M3 a selection made after Customize is stored and the banner stays closed', c && c.m === 'custom' && c.c.functional === 1 && c.c.analytics === 0 && await page.isHidden('#sccm-banner'));
	check('no JavaScript errors (compact banner)', errors.length === 0, errors.join(' | '));
	await context.close();
}

/* ---------------------------------------------------------------- O: button order setting */
{
	const expected = {
		accept_reject: ['accept', 'reject', 'more'],
		reject_accept: ['reject', 'accept', 'more'],
		accept_first: ['accept', 'more', 'reject'],
		reject_first: ['reject', 'more', 'accept']
	};
	const name = (cls) => (/--accept/.test(cls) ? 'accept' : /--reject/.test(cls) ? 'reject' : 'more');
	for (const [order, want] of Object.entries(expected)) {
		for (const layout of ['compact', 'tabs']) {
			const { context, page } = await newPage(browser, { order, layout });
			await page.goto(BASE + '/');
			await page.waitForSelector('#sccm-banner:not([hidden])');
			const got = await page.$$eval('#sccm-banner .sccm-actions .sccm-btn', (els) => els.map((e) => e.className));
			check(`O1 order ${order} (${layout})`, JSON.stringify(got.map(name)) === JSON.stringify(want), got.join(' / '));
			await context.close();
		}
	}
}

/* ---------------------------------------------------------------- P: preview looks like a first visit */
{
	const { context, page } = await newPage(browser, { layout: 'tabs' });
	await page.goto(BASE + '/');
	await page.click('#sccm-banner .sccm-btn--accept');
	await page.waitForTimeout(300);
	await page.goto(BASE + '/#sccm-banner');
	await page.reload();
	await page.waitForSelector('#sccm-banner:not([hidden])');
	const switches = await page.evaluate(() => ['functional', 'analytics', 'marketing'].map((k) => document.getElementById('sccm-bcat-' + k).checked));
	check('P1 banner preview after "Allow all": no category is switched on', switches.every((v) => v === false), switches.join(','));
	await page.click('#sccm-btab-about');
	const status = await page.textContent('#sccm-banner .sccm-status');
	check('P1 banner preview: About tab says no choice was made', /not made a choice/.test(status), status.trim());
	await page.click('#sccm-btab-details');
	const detail = await page.evaluate(() => ['functional', 'analytics', 'marketing'].map((k) => document.getElementById('sccm-bdcat-' + k).checked));
	check('P1 banner preview: Details tab switches are off too', detail.every((v) => v === false));
	check('P1 the stored choice is untouched by the preview', (await consentCookie(context)).m === 'accept_all');
	await context.close();
}

/* ---------------------------------------------------------------- T: theme styles do not leak into the banner */
{
	// What page builders and themes typically ship (e.g. Elementor kit / Hello theme rules).
	const css = 'body.home button, .wp-site-blocks ~ * button, html body button { font-size: 22px; padding: 30px 60px; text-transform: uppercase; letter-spacing: 3px; border-radius: 0; min-height: 90px; font-weight: 300; background: #c36; color: #fff; box-shadow: 0 0 0 5px red; }'
		+ ' html body button:hover, html body button:focus { background: #c36; color: #fff; } html body a { color: #c36; } html body p { margin: 40px 0; font-size: 22px; }';
	const { context, page } = await newPage(browser, { css });
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	const read = () => page.evaluate(() => {
		const b = getComputedStyle(document.querySelector('#sccm-banner .sccm-btn--accept'));
		const c = getComputedStyle(document.querySelector('#sccm-banner .sccm-btn--customize'));
		const t = getComputedStyle(document.querySelector('#sccm-banner .sccm-text'));
		return { fs: b.fontSize, pad: b.paddingTop, tt: b.textTransform, fw: b.fontWeight, bg: b.backgroundColor, sh: b.boxShadow, cbg: c.backgroundColor, tfs: t.fontSize };
	});
	const s = await read();
	check('T1 hostile theme button styles do not change the banner buttons', s.fs === '15px' && s.pad === '10px' && s.tt === 'none' && s.fw === '600' && s.bg === 'rgb(31, 41, 55)' && s.sh === 'none', JSON.stringify(s));
	await page.hover('#sccm-banner .sccm-btn--customize');
	const h = await read();
	check('T1 theme hover colours do not change the buttons', h.cbg === 'rgba(0, 0, 0, 0)', h.cbg);
	check('T1 theme paragraph styles do not change the banner text', s.tfs === '14.25px', s.tfs);
	await context.close();
}

/* ---------------------------------------------------------------- B: buttons never break their text */
{
	for (const [position, label] of [['bottom-left', 'corner'], ['center', 'centre window'], ['bottom', 'bottom bar']]) {
		const { context, page } = await newPage(browser, { layout: 'tabs', position });
		await page.goto(BASE + '/');
		await page.waitForSelector('#sccm-banner:not([hidden])');
		const m = await page.evaluate(() => Array.from(document.querySelectorAll('#sccm-banner .sccm-actions .sccm-btn')).map((b) => ({ w: Math.round(b.getBoundingClientRect().width), h: Math.round(b.getBoundingClientRect().height), fits: b.scrollWidth <= b.clientWidth + 1 })));
		check(`B1 ${label}: three equal one-line buttons`, m.every((b) => b.fits && b.h < 52) && new Set(m.map((b) => b.w)).size === 1, JSON.stringify(m));
		if (position === 'bottom') {
			check('B2 bottom bar (detailed): buttons keep a natural width, not a third of the bar', m.every((b) => b.w < 300), JSON.stringify(m));
		}
		await context.close();
	}
	// A long translation: the buttons stack, all equally wide, instead of wrapping their text.
	const { context, page } = await newPage(browser, { layout: 'tabs', position: 'bottom-right', texts: { btn_selection: 'Only allow the cookies I selected above' } });
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	const st = await page.evaluate(() => {
		const row = document.querySelector('#sccm-banner .sccm-actions');
		const bs = Array.from(row.children);
		return { stacked: row.classList.contains('sccm-actions--stack'), widths: bs.map((b) => Math.round(b.getBoundingClientRect().width)), fits: bs.every((b) => b.scrollWidth <= b.clientWidth + 1) };
	});
	check('B3 long button text: buttons stack with equal width, text on one line', st.stacked && st.fits && new Set(st.widths).size === 1, JSON.stringify(st));
	await context.close();
}

/* ---------------------------------------------------------------- C: Deny */
{
	const { context, page, requests } = await newPage(browser);
	await page.goto(BASE + '/');
	await page.click('#sccm-banner .sccm-btn--reject');
	await page.waitForTimeout(500);
	const state = await page.evaluate(() => ({ ga: !!window.__gaRan, fb: !!window.__fbRan, banner: document.getElementById('sccm-banner').hidden }));
	check('C1 Deny: banner closes, nothing non-essential runs', state.banner && !state.ga && !state.fb);
	check('C1 Deny: no third-party requests', requests.length === 0, requests.join(', '));
	const c = await consentCookie(context);
	check('C2 Deny: consent cookie reject_all', c && c.m === 'reject_all' && !c.c.analytics && !c.c.marketing && !c.c.functional);
	await context.close();
}

/* ---------------------------------------------------------------- D: the tabbed banner style (tabs, switches, details) */
{
	const { context, page, requests, errors } = await newPage(browser, { layout: 'tabs' });
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	const tabs = await page.$$eval('#sccm-banner .sccm-tab', (els) => els.map((e) => e.textContent.trim()));
	check('D0 dialog has Consent, Details and About tabs', JSON.stringify(tabs) === JSON.stringify(['Consent', 'Details', 'About']), tabs.join(','));
	const labels = await page.$$eval('#sccm-banner .sccm-toggle__label', (els) => els.map((e) => e.textContent.trim()));
	check('D1 Consent tab shows the four categories (Cookiebot names)', JSON.stringify(labels) === JSON.stringify(['Necessary', 'Preferences', 'Statistics', 'Marketing']), labels.join(','));
	const first = await page.evaluate(() => ({
		necessary: [document.getElementById('sccm-bcat-necessary').checked, document.getElementById('sccm-bcat-necessary').disabled],
		others: ['functional', 'analytics', 'marketing'].map((k) => document.getElementById('sccm-bcat-' + k).checked)
	}));
	check('D1 Necessary locked on, nothing else pre-ticked', first.necessary[0] && first.necessary[1] && first.others.every((v) => v === false));
	const order = await page.$$eval('#sccm-banner .sccm-actions .sccm-btn', (els) => els.map((e) => e.textContent.trim()));
	check('D1 default button order: Allow all, Deny, Allow selection', JSON.stringify(order) === JSON.stringify(['Allow all', 'Deny', 'Allow selection']), order.join(','));
	const toggles = await page.evaluate(() => {
		const row = document.querySelector('#sccm-banner .sccm-toggles').getBoundingClientRect();
		const text = document.querySelector('#sccm-banner .sccm-text').getBoundingClientRect();
		return Math.abs(row.width - text.width) < 2;
	});
	check('D1 category switches span the full width', toggles);

	// Details tab: built on demand; category accordion → provider accordion → cookie cards.
	check('D2 details are not built until the Details tab is opened', (await page.locator('#sccm-banner .sccm-acc').count()) === 0);
	check('D2 no separate "Show details" link: the Details tab is the only way in', (await page.locator('#sccm-banner [data-sccm-tab]:not(.sccm-tab)').count()) === 0);
	await page.click('#sccm-btab-details');
	check('D2 the Details tab opens', (await page.getAttribute('#sccm-btab-details', 'aria-selected')) === 'true');
	const accs = await page.$$eval('#sccm-banner .sccm-acc__name', (els) => els.map((e) => e.textContent.trim()));
	check('D2 Details tab lists one accordion per category', accs.length === 4, accs.join(','));
	check('D2 accordions start closed', (await page.locator('#sccm-banner .sccm-acc__panel:not([hidden])').count()) === 0);
	await page.click('#sccm-banner .sccm-acc__toggle >> nth=0');
	await page.click('#sccm-banner .sccm-acc__panel:not([hidden]) .sccm-prov__toggle >> nth=0');
	const card = await page.textContent('#sccm-banner .sccm-prov__panel:not([hidden]) .sccm-ck');
	check('D2 cookie card shows name, purpose, maximum storage duration and type', /sccm_consent/.test(card) && /Maximum storage duration/.test(card) && /HTTP Cookie/.test(card), card.trim().slice(0, 100));
	await page.screenshot({ path: OUT + 'banner-details.png' });

	// Switches in both tabs stay in sync, and "Allow selection" uses them.
	await page.check('#sccm-bdcat-analytics');
	await page.click('#sccm-btab-consent');
	check('D3 Details and Consent switches stay in sync', await page.isChecked('#sccm-bcat-analytics'));
	await page.click('#sccm-banner .sccm-btn--selection');
	await page.waitForTimeout(500);
	const s = await page.evaluate(() => ({ ga: !!window.__gaRan, fb: !!window.__fbRan, manual: !!window.__manualFunctionalRan, hidden: document.getElementById('sccm-banner').hidden }));
	check('D3 Allow selection: only Statistics is released', s.hidden && s.ga && !s.fb && !s.manual);
	const c = await consentCookie(context);
	check('D3 stored as custom with statistics only', c && c.m === 'custom' && c.c.analytics === 1 && c.c.marketing === 0 && c.c.functional === 0);
	check('D3 no requests for refused categories', !requests.some((u) => /facebook\.net|youtube\.com/.test(u)), requests.join(', '));

	await page.click('.sccm-floating');
	await page.waitForSelector('#sccm-prefs:not([hidden])');
	await page.keyboard.press('Escape');
	check('D4 Escape closes the reopened window without changes', await page.isHidden('#sccm-prefs') && (await consentCookie(context)).m === 'custom');
	await page.evaluate(() => { document.getElementById('menu-cookie-link').scrollIntoView(); });
	await page.click('#menu-cookie-link');
	check('E1 menu link #sccm-preferences opens the window', await page.isVisible('#sccm-prefs'));
	await page.keyboard.press('Escape');
	await page.click('a.sccm-open-preferences[data-sccm-open]:not(#menu-cookie-link)');
	check('E1 [sccm_cookie_settings] shortcode opens the window', await page.isVisible('#sccm-prefs'));
	check('no JavaScript errors (dialog)', errors.length === 0, errors.join(' | '));
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
	await page.click('#sccm-tab-details');
	const notice = await page.textContent('#sccm-prefs .sccm-notice').catch(() => '');
	check('J2 GPC notice shown in the Details tab', /Global Privacy Control/.test(notice), notice);
	const locked = await page.evaluate(() => ['analytics', 'marketing'].every((k) => document.getElementById('sccm-cat-' + k).disabled && document.getElementById('sccm-dcat-' + k).disabled));
	check('J3 GPC-covered switches cannot be enabled', locked);
	await page.click('#sccm-prefs .sccm-btn--accept');
	await page.waitForTimeout(300);
	const c2 = await consentCookie(context);
	check('J3 Allow all with GPC keeps covered categories off', c2.c.analytics === 0 && c2.c.marketing === 0);
	await context.close();
}

/* ---------------------------------------------------------------- K3: mobile */
{
	const { context, page } = await newPage(browser, { viewport: { width: 375, height: 740 } });
	await page.goto(BASE + '/');
	await page.waitForSelector('#sccm-banner:not([hidden])');
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
	await page.screenshot({ path: OUT + 'banner-mobile.png' });
	const box = await page.locator('#sccm-banner .sccm-dialog__box').boundingBox();
	check('K3 mobile: dialog fits the screen', box && box.width <= 375 && box.x >= 0 && box.y >= 0);
	check('K3 mobile: no horizontal scroll from the banner', !overflow);
	await page.click('#sccm-banner .sccm-btn--customize');
	await page.click('#sccm-tab-details');
	await page.screenshot({ path: OUT + 'details-mobile.png' });
	await context.close();
}

/* ---------------------------------------------------------------- Cookie policy page */
{
	const { context, page } = await newPage(browser);
	await page.goto(BASE + '/cookie-policy/');
	const rows = await page.locator('.sccm-policy .sccm-table tbody tr').count();
	check('10 Cookie Policy page lists cookies', rows > 0, rows + ' rows');
	check('10 Cookie Policy page has the sccm-policy-page body class', await page.evaluate(() => document.body.classList.contains('sccm-policy-page')));
	await context.close();
}
{
	// A dark theme: the list must use the theme's colours and fonts (it used to force dark text).
	const css = 'body, body .wp-site-blocks { background: #111 !important; color: rgb(230, 230, 230) !important; font-size: 19px !important; }';
	const { context, page } = await newPage(browser, { css, path: '/cookie-policy/' });
	await page.goto(BASE + '/cookie-policy/');
	const s = await page.evaluate(() => {
		const td = getComputedStyle(document.querySelector('.sccm-policy .sccm-table td'));
		const p = getComputedStyle(document.querySelector('.sccm-policy p'));
		return { td: td.color, p: p.color, fs: p.fontSize };
	});
	check('10 Cookie Policy list follows the theme colours (readable on dark themes)', s.td === 'rgb(230, 230, 230)' && s.p === 'rgb(230, 230, 230)' && s.fs === '19px', JSON.stringify(s));
	await page.screenshot({ path: OUT + 'policy-dark-theme.png', fullPage: true });
	await context.close();
}

/* ---------------------------------------------------------------- Floating widget: round in corners, text tab on edges */
{
	const { context, page } = await newPage(browser, { viewport: { width: 1000, height: 640 } });
	await page.goto(BASE + '/');
	await page.click('#sccm-banner .sccm-btn--reject');
	await page.waitForSelector('.sccm-floating:not([hidden])');
	await page.mouse.move(500, 5);
	const measure = async (position) => {
		await page.evaluate((pos) => {
			const f = document.querySelector('.sccm-floating');
			f.className = 'sccm-root sccm-floating sccm-floating--' + pos + (/center/.test(pos) ? ' sccm-floating--tab' : '');
		}, position);
		await page.waitForTimeout(300);
		return page.evaluate(() => {
			const r = document.querySelector('.sccm-floating').getBoundingClientRect();
			return { left: r.left, right: innerWidth - r.right, bottom: innerHeight - r.bottom, w: r.width, h: r.height, hscroll: document.documentElement.scrollWidth > innerWidth };
		});
	};
	for (const corner of ['bottom-left', 'bottom-right']) {
		const m = await measure(corner);
		check(`W1 ${corner}: round button inside the window`, m.left >= 0 && m.right >= 0 && m.bottom > 0 && m.w === m.h && !m.hscroll, JSON.stringify(m));
	}
	const edges = { 'bottom-center': (m) => m.bottom <= 0 && m.w > m.h, 'left-center': (m) => m.left <= 1 && m.h > m.w, 'right-center': (m) => m.right <= 1 && m.h > m.w };
	for (const [edge, ok] of Object.entries(edges)) {
		const m = await measure(edge);
		check(`W2 ${edge}: text tab attached to the edge`, ok(m) && !m.hscroll, JSON.stringify(m));
	}
	await context.close();
}
{
	// A site configured with an edge position gets a text tab.
	const { context, page } = await newPage(browser);
	await page.route(BASE + '/', async (route) => {
		const response = await route.fetch();
		await route.fulfill({ response, body: (await response.text()).replace(/"floatingPos":"[a-z-]+"/, '"floatingPos":"left-center"') });
	});
	await page.goto(BASE + '/');
	await page.click('#sccm-banner .sccm-btn--reject');
	await page.waitForSelector('.sccm-floating:not([hidden])');
	check('W2 the edge tab shows a text label', (await page.textContent('.sccm-floating .sccm-floating__label')).trim() === 'Cookies');
	await page.click('.sccm-floating');
	check('W3 the tab opens the cookie settings', await page.isVisible('#sccm-prefs'));
	await context.close();
}

/* ---------------------------------------------------------------- Consent expiry: session only */
{
	const { context, page } = await newPage(browser);
	await page.route(BASE + '/', async (route) => {
		const response = await route.fetch();
		const body = (await response.text()).replace('"days":365', '"days":0');
		await route.fulfill({ response, body });
	});
	await page.goto(BASE + '/');
	await page.click('#sccm-banner .sccm-btn--accept');
	await page.waitForTimeout(400);
	const cookie = (await context.cookies()).find((x) => x.name === 'sccm_consent');
	check('X1 session-only expiry: consent cookie has no expiry date', cookie && cookie.expires === -1, cookie && String(cookie.expires));
	await page.reload();
	check('X1 session-only expiry: choice still applies on the next page view', !(await page.isVisible('#sccm-banner')) && (await page.evaluate(() => !!window.__gaRan)));
	await context.close();
}

/* ---------------------------------------------------------------- Scan mode cannot be triggered by visitors */
{
	const { context, page } = await newPage(browser);
	await page.goto(BASE + '/?sccm_scan=abcdefghijklmnopqrstuvwx');
	await page.waitForTimeout(500);
	const s = await page.evaluate(() => ({ scan: window.SCCM_CONFIG.scanMode, ga: !!window.__gaRan, banner: !!document.querySelector('#sccm-banner:not([hidden])') }));
	check('S1 a made-up scan token does nothing for visitors', s.scan === false && !s.ga && s.banner);
	await context.close();
}

/* ---------------------------------------------------------------- Scanner reporting: quiet by design */
{
	const run = async (loggedIn) => {
		const { context, page } = await newPage(browser);
		await context.addCookies([{ name: 'some_unknown_cookie', value: '1', url: BASE }]);
		if (loggedIn) {
			await page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => document.body.classList.add('logged-in')));
		}
		let posted = null;
		page.on('request', (r) => { if (r.url().includes('/sccm/v1/report')) posted = r.postDataJSON(); });
		await page.goto(BASE + '/');
		await page.evaluate(() => localStorage.setItem('some_extension_key', '1'));
		await page.waitForTimeout(5500);
		await context.close();
		return posted;
	};
	const visitor = await run(false);
	check('R1 a visitor reports unknown cookie NAMES only', visitor && visitor.items.some((i) => i.n === 'some_unknown_cookie') && !JSON.stringify(visitor).includes('"1"'), JSON.stringify(visitor));
	check('R1 local storage keys are never reported', visitor && !visitor.items.some((i) => i.n === 'some_extension_key' || i.t !== 'cookie'));
	const admin = await run(true);
	check('R2 logged-in users never report', admin === null);
}

await browser.close();

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
fs.writeFileSync(OUT + 'results.json', JSON.stringify(results, null, 2));
process.exit(failed.length ? 1 : 0);
