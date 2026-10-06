/**
 * Admin workflow checks (dev site only; needs WP-CLI):
 *   SCCM_E2E_URL=http://127.0.0.1:8080 SCCM_WP=/path/to/wp-cli-wrapper node tests/e2e/admin.mjs
 * Logs in as admin/admin, seeds cookies waiting for review, and drives the real admin screens.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const BASE = (process.env.SCCM_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const WP = process.env.SCCM_WP;
// SCCM_WP may point straight at wp-cli.phar (Windows cannot exec a .phar or a shell wrapper): run it with PHP.
const [WP_BIN, WP_PRE] = /\.phar$/i.test(WP || '') ? [process.env.SCCM_PHP || 'php', [WP]] : [WP, []];
const wp = (...args) => execFileSync(WP_BIN, [...WP_PRE, ...args], { encoding: 'utf8' }).trim();
const option = () => JSON.parse(wp('option', 'get', 'sccm_settings', '--format=json'));
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`); };

// Capture wp_mail() instead of sending (a must-use plugin that this script adds and removes).
const CONTENT = wp('eval', 'echo WP_CONTENT_DIR;');
const MU = CONTENT + '/mu-plugins/zz-sccm-mailcapture.php';
fs.mkdirSync(CONTENT + '/mu-plugins', { recursive: true });
fs.writeFileSync(MU, `<?php
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	file_put_contents( WP_CONTENT_DIR . '/mail-captured.json', wp_json_encode( array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'headers' => $atts['headers'], 'has_html' => false !== strpos( $atts['message'], '<table' ) ) ) );
	return true;
}, 10, 2 );
`);

// Clean slate: only the plugin cookie, three unknown cookies waiting for review.
wp('eval', `global $wpdb; $wpdb->query("DELETE FROM " . SCCM_Cookies::table() . " WHERE service <> 'sccm'"); SCCM_Settings::update(array_merge(SCCM_Settings::defaults(), array("policy_page_id" => SCCM_Settings::get("policy_page_id")))); foreach (array('shop_session_id','promo_seen','tracking_xyz') as $n) { SCCM_Cookies::record_seen($n, 'cookie', 'scanner'); }`);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }).catch(() => chromium.launch());
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
// Third-party hosts are unreachable in CI; answer them locally so the scanned pages "load" them.
await ctx.route(/googletagmanager\.com|facebook\.net|youtube\.com|fonts\.googleapis\.com|fonts\.gstatic\.com/, (r) => r.fulfill({ status: 200, contentType: r.request().url().includes('youtube') ? 'text/html' : 'application/javascript', body: '' }));
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.goto(BASE + '/wp-login.php');
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
const admin = (tab, extra = '') => `${BASE}/wp-admin/admin.php?page=sccm&tab=${tab}${extra}`;

// Dashboard to-do + Cookies inbox
await page.goto(admin('dashboard'));
const todo = await page.textContent('.sccm-todo');
check('D1 dashboard to-do says 3 cookies need a category', /3 cookies were found and need a category/.test(todo), todo.trim().slice(0, 70));
await page.goto(admin('cookies'));
check('C1 inbox lists the 3 cookies waiting', (await page.locator('.sccm-inbox tbody tr').count()) === 3);
check('C1 the menu badge / tab badge shows 3', (await page.textContent('.sccm-badge-count')).trim() === '3');

// Approve needs a category (HTML validation) and then works
const row = page.locator('.sccm-inbox tbody tr', { hasText: 'shop_session_id' });
await row.locator('button[value=active]').click();
check('C2 Approve without a category is blocked', (await page.locator('.sccm-inbox tbody tr').count()) === 3 && page.url().includes('tab=cookies'));
await row.locator('select').selectOption('functional');
await Promise.all([page.waitForNavigation(), row.locator('button[value=active]').click()]);
check('C3 approved cookie moves to the list under Preferences', /Cookie approved/.test(await page.textContent('.notice')) && (await page.locator('.sccm-catblock', { hasText: 'Preferences' }).textContent()).includes('shop_session_id'));
check('C3 inbox now has 2', (await page.locator('.sccm-inbox tbody tr').count()) === 2);

// Server-side guard: approving without a category is refused even if the browser check is bypassed
const id = wp('eval', `echo SCCM_Cookies::find_matching('promo_seen')['id'];`);
const nonce = await page.locator('.sccm-inbox tbody tr', { hasText: 'promo_seen' }).locator('input[name=_wpnonce]').inputValue();
const res = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: 'sccm_cookie_status', _wpnonce: nonce, id, status: 'active', category: '' }, maxRedirects: 0 }).catch((e) => e);
const stillPending = wp('eval', `echo SCCM_Cookies::find_matching('promo_seen')['status'];`);
check('C4 server refuses Approve without a category', stillPending === 'pending', stillPending);

// Ignore all
await page.goto(admin('cookies'));
page.once('dialog', (d) => d.accept());
await Promise.all([page.waitForNavigation(), page.click('.sccm-panel__head button:has-text("Ignore all")')]);
check('C5 "Ignore all" clears the inbox', (await page.locator('.sccm-inbox').count()) === 0 && /2 cookies ignored/.test(await page.textContent('.notice')));

// Legacy links
await page.goto(admin('scanner'));
check('L1 old tab link (tab=scanner) still opens the Cookies tab', (await page.locator('.nav-tab-active').textContent()).trim().startsWith('Cookies'));

// Settings: expiry preset, recipients, sample email
await page.goto(admin('settings'));
check('S1 the days field is hidden while a preset is chosen', !(await page.isVisible('#sccm-consent_expiry_days')));
await page.selectOption('#sccm-expiry-preset', '90');
check('S1 choosing "3 months" sets 90 days, field stays hidden', (await page.inputValue('#sccm-consent_expiry_days')) === '90' && !(await page.isVisible('#sccm-consent_expiry_days')));
await page.selectOption('#sccm-expiry-preset', 'custom');
check('S1 "Another number of days" shows the days field', await page.isVisible('#sccm-consent_expiry_days'));
await page.selectOption('#sccm-expiry-preset', '0');
check('S1 "Until the browser is closed" sets 0', (await page.inputValue('#sccm-consent_expiry_days')) === '0');
await page.selectOption('#sccm-expiry-preset', '180');
await page.locator('summary', { hasText: 'Cookie scan and email alerts' }).click();
await page.fill('#sccm-alert_email', 'owner@example.com\nteam@example.com\nbroken-address');
await Promise.all([page.waitForNavigation(), page.click('p.submit input[type=submit]')]);
const saved = option();
check('S2 expiry saved (180 days)', saved.consent_expiry_days === 180);
check('S3 two recipients saved, invalid dropped', saved.alert_email === 'owner@example.com, team@example.com', saved.alert_email);
await page.locator('summary', { hasText: 'Cookie scan and email alerts' }).click();
if (fs.existsSync(CONTENT + '/mail-captured.json')) fs.unlinkSync(CONTENT + '/mail-captured.json');
await Promise.all([page.waitForNavigation(), page.click('button:has-text("Send me a sample email")')]);
const mail = JSON.parse(fs.readFileSync(CONTENT + '/mail-captured.json', 'utf8'));
const adminEmail = wp('option', 'get', 'admin_email');
check('E1 sample email goes to the site admin and both listed recipients, as HTML', JSON.stringify(mail.to) === JSON.stringify([adminEmail, 'owner@example.com', 'team@example.com']) && mail.has_html && JSON.stringify(mail.headers).includes('text/html'), JSON.stringify(mail.to));
check('E1 sample email subject is marked as a sample', /^Sample: /.test(mail.subject), mail.subject);
await page.locator('summary', { hasText: 'Cookie scan and email alerts' }).click();
check('E2 Settings shows the result of the last email', /Last email: .*owner@example\.com/.test(await page.textContent('.sccm-mail-status--ok')));

// Banner tab: pick positions
await page.goto(admin('banner'));
await page.click('.sccm-picker--banner .sccm-pos:has(input[value=center])');
await page.click('.sccm-picker--widget .sccm-pos:has(input[value=right-center])');
await page.selectOption('#sccm-button_order', 'reject_accept');
await Promise.all([page.waitForNavigation(), page.click('p.submit input[type=submit]')]);
const b = option();
check('B1 banner position, widget position and button order saved', b.position === 'center' && b.floating_position === 'right-center' && b.button_order === 'reject_accept');
const front = await ctx.newPage();
await front.goto(BASE + '/');
await front.waitForSelector('#sccm-banner');
check('B2 front end uses the chosen position', await front.evaluate(() => document.getElementById('sccm-banner').classList.contains('sccm-pos-center')));
check('B2 front end uses the chosen button order (compact banner)', JSON.stringify(await front.$$eval('#sccm-banner .sccm-actions .sccm-btn', (els) => els.map((e) => e.textContent.trim()))) === JSON.stringify(['Deny', 'Allow all', 'Customize']));
await page.selectOption('#sccm-banner_layout', 'tabs');
await Promise.all([page.waitForNavigation(), page.click('p.submit input[type=submit]')]);
check('B3 banner style "tabs" saved', option().banner_layout === 'tabs');
await front.reload();
await front.waitForSelector('#sccm-banner');
check('B3 tabbed banner shows the tabs and all three buttons in the chosen order', (await front.locator('#sccm-banner .sccm-tab').count()) === 3 && JSON.stringify(await front.$$eval('#sccm-banner .sccm-actions .sccm-btn', (els) => els.map((e) => e.textContent.trim()))) === JSON.stringify(['Deny', 'Allow all', 'Allow selection']));
await front.close();

// Browser scan: finds cookies set by JavaScript and third-party cookies, from a nearly empty list.
wp('eval', `global $wpdb; $wpdb->query("DELETE FROM " . SCCM_Cookies::table() . " WHERE service <> 'sccm'"); delete_option('sccm_last_scan');`);
await page.goto(admin('cookies'));
await page.click('.sccm-scanstrip button');
await page.waitForSelector('.sccm-scan-status');
check('BS1 the scan shows its progress', /Step 1 of 2|Step 2 of 2/.test(await page.textContent('.sccm-scan-status')));
if (fs.existsSync(CONTENT + '/mail-captured.json')) fs.unlinkSync(CONTENT + '/mail-captured.json');
await page.waitForURL(/sccm_notice/, { timeout: 150000 });
const listed = JSON.parse(wp('eval', `echo wp_json_encode( array_map( function ( $r ) { return $r['name'] . '|' . $r['status'] . '|' . $r['category']; }, SCCM_Cookies::all_rows() ) );`));
check('BS2 a cookie set by JavaScript (_ga) is found and sorted as Statistics', listed.includes('_ga|active|analytics'), listed.join(', '));
check('BS2 third-party cookies of an embedded service (YouTube YSC) are listed', listed.includes('YSC|active|marketing'));
check('BS2 an unknown cookie set by a script waits for review', listed.includes('test_unknown_cookie|pending|necessary'));
check('BS2 an unknown local storage key set by a script waits for review', listed.includes('test_unknown_storage|pending|necessary'));
check('BS2 no admin-only cookies are listed', !listed.some((x) => /^wordpress_|^wp-settings/.test(x)));
check('BS3 the result message counts what was found', /Scan finished: \d+ page\(s\) opened in your browser\. [1-9]\d* new cookie/.test(await page.textContent('.notice')), (await page.textContent('.notice')).trim().slice(0, 140));
check('BS4 "Scan now" sends no email itself: the changes wait for the daily check', !fs.existsSync(CONTENT + '/mail-captured.json') && JSON.parse(wp('option', 'get', 'sccm_pending_alert', '--format=json')).length > 0);
wp('eval', 'SCCM_Scanner::daily();');
const daily = fs.existsSync(CONTENT + '/mail-captured.json') ? JSON.parse(fs.readFileSync(CONTENT + '/mail-captured.json', 'utf8')) : null;
check('BS4 the daily check then sends one email about the changes found by the scan (admin + listed addresses)', daily && /need(s)? review|added to your cookie banner/.test(daily.subject) && daily.to.includes(adminEmail) && daily.to.includes('owner@example.com'), daily && daily.subject);
const leftover = await page.evaluate(() => document.querySelectorAll('iframe[sandbox]').length);
check('BS3 the hidden frames are removed afterwards', leftover === 0);

// Dashboard switch off / on
await page.goto(admin('dashboard'));
page.once('dialog', (d) => d.accept());
await Promise.all([page.waitForNavigation(), page.click('.sccm-hero__actions button:has-text("Switch off")')]);
check('D2 dashboard can switch the banner off', option().enabled === 0);
await Promise.all([page.waitForNavigation(), page.click('.sccm-hero__actions button:has-text("Switch on")')]);
check('D2 and on again', option().enabled === 1);

// CSRF + logged-out
const bad = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: 'sccm_ignore_all', _wpnonce: 'nope' }, maxRedirects: 0 });
check('X1 a bad nonce is refused', bad.status() === 403, String(bad.status()));
const anon = await (await browser.newContext()).request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: 'sccm_scan_now' }, maxRedirects: 0 });
check('X2 logged-out requests are refused', anon.status() !== 200 && anon.status() < 500, String(anon.status()));

// Leaving the page during "Scan now": the browser warns, and the scan continues on the next plugin page.
const extra = JSON.parse(wp('eval', `$ids = array(); for ( $i = 1; $i <= 15; $i++ ) { $ids[] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "Leave test $i", 'post_content' => 'x' ) ); } echo wp_json_encode( $ids );`));
await page.goto(admin('cookies'));
await page.click('.sccm-scanstrip button');
await page.waitForFunction(() => /Step 2 of 2: opening page ([4-9]|1\d)/.test((document.querySelector('.sccm-scan-status__text') || {}).textContent || ''), null, { timeout: 120000 });
let warned = false;
page.once('dialog', (d) => { warned = d.type() === 'beforeunload'; d.accept(); });
await Promise.all([page.waitForNavigation(), page.click('.nav-tab-wrapper a[href*="tab=dashboard"]')]);
check('S1 leaving the page during a scan asks first', warned);
const partial = JSON.parse(wp('eval', `$s = get_option( SCCM_Scanner::BROWSER_OPTION ); echo wp_json_encode( $s ? array( count( $s['urls'] ), count( $s['done'] ) ) : null );`));
check('S2 what was found so far is kept, the rest is remembered', partial && partial[1] >= 3 && partial[1] < partial[0], JSON.stringify(partial));
await page.waitForFunction(() => /Cookie scan finished/.test((document.querySelector('.sccm-scan-status__text') || {}).textContent || ''), null, { timeout: 180000 });
const after = JSON.parse(wp('eval', `$r = get_option( SCCM_Scanner::RESULT_OPTION ); echo wp_json_encode( array( (int) $r['browser']['pages'], count( $r['pages'] ), (bool) get_option( SCCM_Scanner::BROWSER_OPTION ), (bool) get_option( SCCM_Scanner::STATE_OPTION ) ) );`));
check('S3 back on a plugin page the scan continues and finishes every page', after[0] === after[1] && after[0] >= 15 && !after[2] && !after[3], JSON.stringify(after));
wp('eval', `foreach ( ${JSON.stringify(extra)} as $id ) { wp_delete_post( $id, true ); }`);

// Spoofed notices: a message in the URL is not shown (notices are kept on the server).
await page.goto(admin('cookies') + '&sccm_msg=' + encodeURIComponent('Your licence expired, visit evil.example') + '&sccm_notice=1');
check('X3 a message put in the URL is not shown', !(await page.evaluate(() => Array.from(document.querySelectorAll('.notice')).map((n) => n.textContent).join(' '))).includes('evil.example'));

// Google Analytics loaded by ID: Strict mode waits for consent, Advanced mode loads at once.
const ga = async (mode) => {
	wp('eval', `SCCM_Settings::update(array('ga4_id' => 'G-TEST12345', 'consent_mode' => '${mode}'));`);
	const ctx2 = await browser.newContext();
	const seen = [];
	await ctx2.route(/googletagmanager\.com|facebook\.net|youtube\.com|fonts\.g/, (route) => {
		seen.push(route.request().url());
		route.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
	});
	const p = await ctx2.newPage();
	await p.goto(BASE + '/');
	await p.waitForTimeout(800);
	const before = seen.some((u) => u.includes('gtag/js?id=G-TEST12345'));
	await p.click('#sccm-banner .sccm-btn--accept');
	await p.waitForTimeout(800);
	const after = seen.some((u) => u.includes('gtag/js?id=G-TEST12345'));
	const config = await p.evaluate(() => (window.dataLayer || []).some((e) => e && e[0] === 'config' && e[1] === 'G-TEST12345'));
	await ctx2.close();
	return { before, after, config };
};
const strict = await ga('basic');
check('G3 GA4 by ID (Strict): not loaded before consent, loaded and configured after Allow all', !strict.before && strict.after && strict.config, JSON.stringify(strict));
const advanced = await ga('advanced');
check('G3 GA4 by ID (Advanced): loaded at once (Consent Mode handles the choice)', advanced.before && advanced.config, JSON.stringify(advanced));
wp('eval', `SCCM_Settings::update(array('ga4_id' => '', 'consent_mode' => 'basic'));`);

check('no JavaScript errors in the admin', errors.length === 0, errors.join(' | '));
wp('eval', `SCCM_Settings::update(array_merge(SCCM_Settings::defaults(), array("policy_page_id" => SCCM_Settings::get("policy_page_id"))));`);
fs.rmSync(MU, { force: true });
fs.rmSync(CONTENT + '/mail-captured.json', { force: true });
await browser.close();
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} admin checks passed`);
process.exit(failed ? 1 : 0);
