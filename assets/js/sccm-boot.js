/*!
 * Smart Cookie Consent Manager — boot script.
 *
 * Printed inline as the first script in <head> (after window.SCCM_CONFIG).
 * 1. Sets Google Consent Mode v2 defaults (everything denied except security).
 * 2. Reads the stored choice (sccm_consent cookie) and applies GPC.
 * 3. For returning visitors, sends the consent update before any tag fires.
 * Result is exposed as window.SCCM_STATE for sccm-frontend.js.
 *
 * Keep this file small and ES5-compatible: it runs before everything else.
 */
(function (w, d) {
	'use strict';
	var C = w.SCCM_CONFIG;
	if (!C) {
		return;
	}

	function readCookie(name) {
		var parts = d.cookie ? d.cookie.split('; ') : [];
		for (var i = 0; i < parts.length; i++) {
			var eq = parts[i].indexOf('=');
			if (eq > 0 && parts[i].substring(0, eq) === name) {
				return parts[i].substring(eq + 1);
			}
		}
		return null;
	}

	function parseConsent() {
		var raw = readCookie(C.cookie);
		if (!raw) {
			return null;
		}
		try {
			var o = JSON.parse(decodeURIComponent(raw));
			return o && typeof o === 'object' && o.id && o.c && typeof o.c === 'object' ? o : null;
		} catch (e) {
			return null;
		}
	}

	// Scan mode (admin's cookie scan): note every storage key the page's scripts write, so the
	// scan can tell the site's own keys from ones the admin's browser already had.
	if (C.scanMode) {
		var written = w.__sccmScanKeys = { localStorage: {}, sessionStorage: {} };
		try {
			var setItem = w.Storage.prototype.setItem;
			w.Storage.prototype.setItem = function (key) {
				try {
					written[this === w.sessionStorage ? 'sessionStorage' : 'localStorage'][String(key)] = true;
				} catch (e) { /* ignore */ }
				return setItem.apply(this, arguments);
			};
		} catch (e) { /* ignore */ }
	}

	var now = Math.floor(Date.now() / 1000);
	var stored = parseConsent();
	// C.days = 0 means "session only": the cookie itself disappears when the browser closes.
	var valid = !!(stored && stored.v === C.v && (C.days === 0 || now - stored.t < C.days * 86400) && stored.t <= now + 300);

	// Optional grace period: after a "Reject", do not ask again for N days even if the version changed.
	if (!valid && stored && stored.m === 'reject_all' && C.graceDays > 0 && now - stored.t < C.graceDays * 86400) {
		valid = true;
	}

	var gpc = !!(C.gpc && C.gpc.on && w.navigator && w.navigator.globalPrivacyControl === true);
	var gpcBlocked = [];
	var grants = { necessary: true };
	var keys = [];
	for (var i = 0; i < C.categories.length; i++) {
		keys.push(C.categories[i].key);
	}
	// Categories from the full list (hidden categories stay denied).
	var optional = ['functional', 'analytics', 'marketing'];
	for (var j = 0; j < optional.length; j++) {
		var k = optional[j];
		// Scan mode (admin's cookie scan): everything runs so every cookie can be found.
		grants[k] = !!(C.scanMode || (valid && stored.c[k] && keys.indexOf(k) !== -1));
		if (gpc && !C.scanMode && (C.gpc.scope === 'all' || k === 'marketing')) {
			grants[k] = false;
			gpcBlocked.push(k);
		}
	}

	w.SCCM_STATE = {
		consent: valid ? stored : null,
		grants: grants,
		gpc: gpc,
		gpcBlocked: gpcBlocked,
		needsChoice: !valid && !C.scanMode
	};

	if (C.cm && C.cm.mode !== 'off') {
		w.dataLayer = w.dataLayer || [];
		if (typeof w.gtag !== 'function') {
			w.gtag = function () {
				w.dataLayer.push(arguments);
			};
		}
		var map = C.cm.map;
		var defaults = { security_storage: 'granted', wait_for_update: 500 };
		var update = {};
		for (var cat in map) {
			if (Object.prototype.hasOwnProperty.call(map, cat)) {
				for (var t = 0; t < map[cat].length; t++) {
					defaults[map[cat][t]] = 'denied';
					update[map[cat][t]] = grants[cat] ? 'granted' : 'denied';
				}
			}
		}
		w.gtag('consent', 'default', defaults);
		if (C.cm.redact) {
			w.gtag('set', 'ads_data_redaction', true);
		}
		if (C.cm.passthrough) {
			w.gtag('set', 'url_passthrough', true);
		}
		if (valid || gpc || C.scanMode) {
			w.gtag('consent', 'update', update);
		}
	}
})(window, document);
