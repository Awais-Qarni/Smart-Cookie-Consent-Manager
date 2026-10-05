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
const wp = (...args) => execFileSync(WP, args, { encoding: 'utf8' }).trim();
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
wp('eval', `global $wpdb; $wpdb->query("DELETE FROM " . SCCM_Cookies::table() . " WHERE service <> 'sccm'"); SCCM_Settings::update(SCCM_Settings::defaults()); foreach (array('shop_session_id','promo_seen','tracking_xyz') as $n) { SCCM_Cookies::record_seen($n, 'cookie', 'scanner'); }`);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }).catch(() => chromium.launch());
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
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
await page.selectOption('#sccm-expiry-preset', '90');
check('S1 choosing "3 months" fills 90 days', (await page.inputValue('#sccm-consent_expiry_days')) === '90');
await page.fill('#sccm-consent_expiry_days', '120');
check('S1 typing days picks "Another number of days"', (await page.inputValue('#sccm-expiry-preset')) === 'custom');
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
check('E1 sample email goes to both recipients as HTML', JSON.stringify(mail.to) === JSON.stringify(['owner@example.com', 'team@example.com']) && mail.has_html && JSON.stringify(mail.headers).includes('text/html'), JSON.stringify(mail.to));
check('E1 sample email subject is marked as a sample', /^Sample: /.test(mail.subject), mail.subject);

// Banner tab: pick positions
await page.goto(admin('banner'));
await page.click('.sccm-picker--banner .sccm-pos:has(input[value=center])');
await page.click('.sccm-picker--widget .sccm-pos:has(input[value=right-center])');
await Promise.all([page.waitForNavigation(), page.click('p.submit input[type=submit]')]);
const b = option();
check('B1 banner and widget positions saved', b.position === 'center' && b.floating_position === 'right-center');
const front = await ctx.newPage();
await front.goto(BASE + '/');
await front.waitForSelector('#sccm-banner');
check('B2 front end uses the chosen position', await front.evaluate(() => document.getElementById('sccm-banner').classList.contains('sccm-pos-center')));
await front.close();

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

check('no JavaScript errors in the admin', errors.length === 0, errors.join(' | '));
wp('eval', `SCCM_Settings::update(SCCM_Settings::defaults());`);
fs.rmSync(MU, { force: true });
fs.rmSync(CONTENT + '/mail-captured.json', { force: true });
await browser.close();
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length - failed}/${results.length} admin checks passed`);
process.exit(failed ? 1 : 0);
