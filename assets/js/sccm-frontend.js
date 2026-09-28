/*!
 * Smart Cookie Consent Manager — front end.
 *
 * Banner, preferences window, releasing blocked content, cookie clean-up, GPC,
 * consent records and the visitor-side cookie scanner.
 *
 * Public API: window.SCCM (see docs/HOOKS.md).
 * Events on document: 'sccm:ready', 'sccm:consent' (detail: { grants, method, consent }).
 */
(function (w, d) {
	'use strict';

	var C = w.SCCM_CONFIG;
	if (!C || w.SCCM) {
		return;
	}

	var OPTIONAL = ['functional', 'analytics', 'marketing'];
	var T = C.texts || {};
	var state = w.SCCM_STATE || { consent: null, grants: { necessary: true }, gpc: false, gpcBlocked: [], needsChoice: true };
	var banner = null;
	var modal = null;
	var floating = null;
	var lastFocus = null;

	/* ------------------------------------------------------------------ Helpers */

	function el(tag, attrs, children) {
		var node = d.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) {
				var value = attrs[key];
				if (value === null || value === undefined || value === false) {
					return;
				}
				if (key === 'text') {
					node.textContent = value;
				} else if (key === 'html') {
					node.innerHTML = value;
				} else {
					node.setAttribute(key, value === true ? '' : value);
				}
			});
		}
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? d.createTextNode(child) : child);
			}
		});
		return node;
	}

	function uuid() {
		if (w.crypto && typeof w.crypto.randomUUID === 'function') {
			return w.crypto.randomUUID();
		}
		var bytes = new Uint8Array(16);
		if (w.crypto && w.crypto.getRandomValues) {
			w.crypto.getRandomValues(bytes);
		} else {
			for (var i = 0; i < 16; i++) {
				bytes[i] = Math.floor(Math.random() * 256);
			}
		}
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		var hex = Array.prototype.map.call(bytes, function (b) {
			return ('0' + b.toString(16)).slice(-2);
		}).join('');
		return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
	}

	/** Wildcard match: '*' = any characters. */
	function nameMatches(pattern, name) {
		if (pattern.indexOf('*') === -1) {
			return pattern === name;
		}
		var re = new RegExp('^' + pattern.split('*').map(function (part) {
			return part.replace(/[.+?^${}()|[\]\\]/g, '\\$&');
		}).join('.*') + '$');
		return re.test(name);
	}

	function cookieNames() {
		if (!d.cookie) {
			return [];
		}
		return d.cookie.split(';').map(function (part) {
			return part.split('=')[0].trim();
		}).filter(Boolean);
	}

	function storageKeys(type) {
		try {
			var store = w[type];
			var keys = [];
			for (var i = 0; i < store.length; i++) {
				keys.push(store.key(i));
			}
			return keys;
		} catch (e) {
			return [];
		}
	}

	function categoryEnabled(key) {
		return C.categories.some(function (cat) {
			return cat.key === key;
		});
	}

	function isGpcBlocked(key) {
		return state.gpcBlocked.indexOf(key) !== -1;
	}

	function granted(categories) {
		return String(categories || '').split(/[\s,]+/).filter(Boolean).every(function (key) {
			return key === 'necessary' || state.grants[key] === true;
		});
	}

	function cleanGrants(input) {
		var out = { necessary: true };
		OPTIONAL.forEach(function (key) {
			out[key] = !!input[key] && categoryEnabled(key) && !isGpcBlocked(key);
		});
		return out;
	}

	function post(url, body) {
		if (!url) {
			return;
		}
		try {
			w.fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body),
				credentials: 'omit',
				keepalive: true
			}).catch(function () {});
		} catch (e) {
			/* Logging must never break the page. */
		}
	}

	function emit(name, detail) {
		var event;
		try {
			event = new CustomEvent(name, { detail: detail });
		} catch (e) {
			event = d.createEvent('CustomEvent');
			event.initCustomEvent(name, false, false, detail);
		}
		d.dispatchEvent(event);
	}

	/* ------------------------------------------------------------------ Storage & clean-up */

	function writeConsent(consent) {
		var value = encodeURIComponent(JSON.stringify(consent));
		var expires = new Date(Date.now() + C.days * 86400000).toUTCString();
		var secure = w.location.protocol === 'https:' ? '; Secure' : '';
		d.cookie = C.cookie + '=' + value + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
	}

	function deleteCookie(name) {
		var host = w.location.hostname;
		var parts = host.split('.');
		var domains = ['', host, '.' + host];
		for (var i = 1; i < parts.length - 1; i++) {
			domains.push('.' + parts.slice(i).join('.'));
		}
		var paths = ['/', w.location.pathname];
		domains.forEach(function (domain) {
			paths.forEach(function (path) {
				d.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=' + path + (domain ? '; domain=' + domain : '');
			});
		});
	}

	/** Delete known cookies / storage keys of categories that are not granted. */
	function cleanup() {
		var names = cookieNames();
		var local = storageKeys('localStorage');
		var session = storageKeys('sessionStorage');
		(C.cleanup || []).forEach(function (item) {
			if (granted(item.c)) {
				return;
			}
			if (item.t === 'localStorage' || item.t === 'sessionStorage') {
				(item.t === 'localStorage' ? local : session).forEach(function (key) {
					if (nameMatches(item.n, key)) {
						try {
							w[item.t].removeItem(key);
						} catch (e) { /* ignore */ }
					}
				});
				return;
			}
			names.forEach(function (name) {
				if (nameMatches(item.n, name)) {
					deleteCookie(name);
				}
			});
		});
	}

	/* ------------------------------------------------------------------ Releasing blocked content */

	function loadScript(original) {
		return new Promise(function (resolve) {
			var script = d.createElement('script');
			Array.prototype.forEach.call(original.attributes, function (attr) {
				if (attr.name === 'type' || attr.name.indexOf('data-sccm-') === 0) {
					return;
				}
				script.setAttribute(attr.name, attr.value);
			});
			var type = original.getAttribute('data-sccm-type');
			if (type) {
				script.type = type;
			}
			var src = original.getAttribute('data-sccm-src');
			if (src) {
				// Scripts that were not async keep document order: wait for each to load.
				var wait = !original.hasAttribute('async');
				script.async = !wait;
				if (wait) {
					var done = false;
					var finish = function () {
						if (!done) {
							done = true;
							resolve();
						}
					};
					script.onload = finish;
					script.onerror = finish;
					setTimeout(finish, 4000);
				}
				script.src = src;
				original.parentNode.replaceChild(script, original);
				if (!wait) {
					resolve();
				}
				return;
			}
			script.text = original.text;
			original.parentNode.replaceChild(script, original);
			resolve();
		});
	}

	var releasing = Promise.resolve();

	/** Release everything the current grants allow, keeping document order for scripts. */
	function release() {
		d.querySelectorAll('link[data-sccm-href][data-sccm-category]').forEach(function (link) {
			if (granted(link.getAttribute('data-sccm-category'))) {
				link.setAttribute('href', link.getAttribute('data-sccm-href'));
				link.removeAttribute('data-sccm-href');
			}
		});

		d.querySelectorAll('iframe[data-sccm-category]').forEach(function (frame) {
			if (granted(frame.getAttribute('data-sccm-category'))) {
				var placeholder = frame.previousElementSibling;
				if (placeholder && placeholder.classList.contains('sccm-placeholder')) {
					placeholder.parentNode.removeChild(placeholder);
				}
				if (frame.hasAttribute('data-sccm-datasrc')) {
					frame.setAttribute('data-src', frame.getAttribute('data-sccm-datasrc'));
					frame.removeAttribute('data-sccm-datasrc');
				}
				if (frame.hasAttribute('data-sccm-src')) {
					frame.setAttribute('src', frame.getAttribute('data-sccm-src'));
					frame.removeAttribute('data-sccm-src');
				}
				frame.removeAttribute('data-sccm-category');
				frame.style.display = '';
			} else if (C.placeholder) {
				addPlaceholder(frame);
			}
		});

		var scripts = Array.prototype.slice.call(d.querySelectorAll('script[type="text/plain"][data-sccm-category]:not([data-sccm-releasing])')).filter(function (script) {
			return granted(script.getAttribute('data-sccm-category'));
		});
		scripts.forEach(function (script) {
			script.setAttribute('data-sccm-releasing', '1');
		});
		releasing = releasing.then(function () {
			return scripts.reduce(function (chain, script) {
				return chain.then(function () {
					return script.parentNode ? loadScript(script) : null;
				});
			}, Promise.resolve());
		});
		return releasing;
	}

	function addPlaceholder(frame) {
		var prev = frame.previousElementSibling;
		if (prev && prev.classList.contains('sccm-placeholder')) {
			return;
		}
		var category = String(frame.getAttribute('data-sccm-category')).split(/[\s,]+/)[0];
		var label = category;
		C.categories.forEach(function (cat) {
			if (cat.key === category) {
				label = cat.label;
			}
		});
		var width = frame.getAttribute('width');
		var height = frame.getAttribute('height');
		var style = [];
		if (width && /^\d+$/.test(width)) {
			style.push('max-width:' + width + 'px');
		}
		if (height && /^\d+$/.test(height)) {
			style.push('min-height:' + height + 'px');
		}
		var button = el('button', { type: 'button', 'class': 'sccm-btn', text: T.placeholder_btn });
		button.addEventListener('click', function () {
			var next = {};
			OPTIONAL.forEach(function (key) {
				next[key] = state.grants[key];
			});
			next[category] = true;
			save('custom', next);
		});
		var box = el('div', { 'class': 'sccm-root sccm-placeholder', style: style.join(';') }, [
			el('p', { text: String(T.placeholder_text || '').replace('%s', label) }),
			button
		]);
		frame.style.display = 'none';
		frame.parentNode.insertBefore(box, frame);
	}

	/* ------------------------------------------------------------------ Consent actions */

	function applyConsentMode() {
		if (!C.cm || C.cm.mode === 'off' || typeof w.gtag !== 'function') {
			return;
		}
		var update = {};
		Object.keys(C.cm.map).forEach(function (cat) {
			C.cm.map[cat].forEach(function (type) {
				update[type] = state.grants[cat] ? 'granted' : 'denied';
			});
		});
		w.gtag('consent', 'update', update);
	}

	/**
	 * Store a choice.
	 *
	 * @param {string} method accept_all | reject_all | custom | gpc
	 * @param {Object} input  category => boolean
	 */
	function save(method, input) {
		var previous = state.consent;
		var grants = cleanGrants(input);
		var consent = {
			id: previous && previous.id ? previous.id : uuid(),
			v: C.v,
			t: Math.floor(Date.now() / 1000),
			c: { functional: grants.functional ? 1 : 0, analytics: grants.analytics ? 1 : 0, marketing: grants.marketing ? 1 : 0 },
			m: method,
			g: state.gpc ? 1 : 0
		};
		var withdrawn = !!previous && OPTIONAL.some(function (key) {
			return previous.c && previous.c[key] && !grants[key];
		});

		writeConsent(consent);
		state.consent = consent;
		state.grants = grants;
		state.needsChoice = false;

		applyConsentMode();

		if (C.log) {
			post(C.rest.consent, {
				consent_id: consent.id,
				choice: method,
				categories: OPTIONAL.filter(function (key) {
					return grants[key];
				}),
				gpc: state.gpc ? 1 : 0,
				version: C.v,
				url: w.location.href.split('#')[0]
			});
		}

		if (w.dataLayer && typeof w.dataLayer.push === 'function') {
			w.dataLayer.push({ event: 'sccm_consent_update', sccm_categories: OPTIONAL.filter(function (key) { return grants[key]; }), sccm_method: method });
		}

		hideBanner();
		closePreferences();
		renderFloating();
		cleanup();
		emit('sccm:consent', { grants: grants, method: method, consent: consent });

		if (withdrawn && C.reload) {
			setTimeout(function () {
				w.location.reload();
			}, 300);
			return;
		}
		release();
	}

	function acceptAll() {
		save('accept_all', { functional: true, analytics: true, marketing: true });
	}

	function rejectAll() {
		save('reject_all', {});
	}

	function saveFromModal() {
		var input = {};
		modal.querySelectorAll('input[data-sccm-cat]').forEach(function (box) {
			input[box.getAttribute('data-sccm-cat')] = box.checked;
		});
		save('custom', input);
	}

	/* ------------------------------------------------------------------ UI: banner */

	function linksRow() {
		var links = [];
		if (C.links.policy) {
			links.push(el('a', { href: C.links.policy, text: T.policy_link }));
		}
		if (C.links.privacy) {
			links.push(el('a', { href: C.links.privacy, text: T.privacy_link }));
		}
		return links.length ? el('p', { 'class': 'sccm-links' }, links) : null;
	}

	function actionButtons(withSave) {
		var buttons = [
			el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--reject', 'data-sccm-action': 'reject', text: T.btn_reject }),
			el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--accept', 'data-sccm-action': 'accept', text: T.btn_accept })
		];
		if (withSave) {
			buttons.push(el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--save', 'data-sccm-action': 'save', text: T.btn_save }));
		}
		return buttons;
	}

	function showBanner() {
		if (banner) {
			banner.hidden = false;
			return;
		}
		var isModal = C.position === 'center';
		banner = el('div', {
			id: 'sccm-banner',
			'class': 'sccm-root sccm-banner sccm-pos-' + C.position,
			role: isModal ? 'dialog' : 'region',
			'aria-modal': isModal ? 'true' : null,
			'aria-labelledby': 'sccm-banner-title',
			'aria-describedby': 'sccm-banner-text'
		}, [
			isModal ? el('div', { 'class': 'sccm-overlay' }) : null,
			el('div', { 'class': 'sccm-banner__inner' }, [
				el('div', { 'class': 'sccm-banner__content' }, [
					el('p', { 'class': 'sccm-title', id: 'sccm-banner-title', role: 'heading', 'aria-level': '2', text: T.banner_title }),
					el('div', { 'class': 'sccm-text', id: 'sccm-banner-text', html: T.banner_text }),
					linksRow()
				]),
				el('div', { 'class': 'sccm-actions' }, actionButtons(false).concat([
					el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--manage', 'data-sccm-action': 'manage', text: T.btn_manage })
				]))
			])
		]);
		d.body.appendChild(banner);
		if (floating) {
			floating.hidden = true;
		}
	}

	function hideBanner() {
		if (banner) {
			banner.hidden = true;
		}
	}

	/* ------------------------------------------------------------------ UI: preferences */

	function cookieTable(cookies) {
		if (!cookies.length) {
			return el('p', { 'class': 'sccm-muted', text: T.no_cookies });
		}
		var rows = cookies.map(function (c) {
			return el('tr', null, [
				el('td', { 'data-label': T.col_name, text: c.n }),
				el('td', { 'data-label': T.col_provider, text: c.p || '' }),
				el('td', { 'data-label': T.col_purpose, text: c.u || '' }),
				el('td', { 'data-label': T.col_duration, text: c.d || '' })
			]);
		});
		return el('table', { 'class': 'sccm-table' }, [
			el('thead', null, [el('tr', null, [
				el('th', { scope: 'col', text: T.col_name }),
				el('th', { scope: 'col', text: T.col_provider }),
				el('th', { scope: 'col', text: T.col_purpose }),
				el('th', { scope: 'col', text: T.col_duration })
			])]),
			el('tbody', null, rows)
		]);
	}

	function buildModal() {
		var body = el('div', { 'class': 'sccm-modal__body' }, [
			el('div', { 'class': 'sccm-text', html: T.prefs_text })
		]);

		if (state.gpc && state.gpcBlocked.length) {
			var names = state.gpcBlocked.filter(categoryEnabled).map(function (key) {
				var found = key;
				C.categories.forEach(function (cat) {
					if (cat.key === key) {
						found = cat.label;
					}
				});
				return found;
			}).join(', ');
			body.appendChild(el('p', { 'class': 'sccm-notice', role: 'status', text: String(T.gpc_notice || '').replace('%s', names) }));
		}

		C.categories.forEach(function (cat) {
			var id = 'sccm-cat-' + cat.key;
			var locked = cat.locked || isGpcBlocked(cat.key);
			var control = el('label', { 'class': 'sccm-switch', 'for': id }, [
				el('input', {
					type: 'checkbox',
					role: 'switch',
					id: id,
					'data-sccm-cat': cat.locked ? null : cat.key,
					checked: cat.locked || (!isGpcBlocked(cat.key) && state.grants[cat.key] === true),
					disabled: locked,
					'aria-describedby': id + '-desc'
				}),
				el('span', { 'class': 'sccm-slider', 'aria-hidden': 'true' }),
				el('span', { 'class': 'sccm-sr', text: cat.label })
			]);
			var head = el('div', { 'class': 'sccm-cat__head' }, [
				el('div', { 'class': 'sccm-cat__title' }, [
					el('p', { 'class': 'sccm-cat__label', role: 'heading', 'aria-level': '3', text: cat.label }),
					cat.locked ? el('span', { 'class': 'sccm-badge', text: T.always_on }) : null
				]),
				control
			]);
			var details = el('details', { 'class': 'sccm-cookies' }, [
				el('summary', { text: T.show_cookies + ' (' + cat.cookies.length + ')' }),
				cookieTable(cat.cookies)
			]);
			body.appendChild(el('div', { 'class': 'sccm-cat' }, [
				head,
				el('p', { 'class': 'sccm-cat__desc', id: id + '-desc', text: cat.description }),
				details
			]));
		});

		var idLine = el('p', { 'class': 'sccm-consent-id sccm-muted' });
		body.appendChild(idLine);
		var links = linksRow();
		if (links) {
			body.appendChild(links);
		}

		modal = el('div', { id: 'sccm-prefs', 'class': 'sccm-root sccm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'sccm-prefs-title', hidden: true }, [
			el('div', { 'class': 'sccm-overlay', 'data-sccm-action': 'close' }),
			el('div', { 'class': 'sccm-modal__dialog', tabindex: '-1' }, [
				el('div', { 'class': 'sccm-modal__header' }, [
					el('p', { 'class': 'sccm-title', id: 'sccm-prefs-title', role: 'heading', 'aria-level': '2', text: T.prefs_title }),
					el('button', { type: 'button', 'class': 'sccm-close', 'data-sccm-action': 'close', 'aria-label': T.close, html: '&times;' })
				]),
				body,
				el('div', { 'class': 'sccm-modal__footer sccm-actions' }, actionButtons(true))
			])
		]);
		modal.addEventListener('keydown', trapFocus);
		d.body.appendChild(modal);
	}

	function syncModal() {
		modal.querySelectorAll('input[data-sccm-cat]').forEach(function (box) {
			var key = box.getAttribute('data-sccm-cat');
			box.checked = !isGpcBlocked(key) && state.grants[key] === true;
		});
		var idLine = modal.querySelector('.sccm-consent-id');
		idLine.textContent = state.consent ? T.consent_id + ': ' + state.consent.id : '';
	}

	function focusables(root) {
		return Array.prototype.filter.call(root.querySelectorAll('button, [href], input:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'), function (node) {
			return node.offsetParent !== null;
		});
	}

	function trapFocus(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			closePreferences();
			return;
		}
		if (event.key !== 'Tab') {
			return;
		}
		var items = focusables(modal);
		if (!items.length) {
			return;
		}
		var first = items[0];
		var last = items[items.length - 1];
		if (event.shiftKey && d.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && d.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function openPreferences() {
		if (!modal) {
			buildModal();
		}
		syncModal();
		lastFocus = d.activeElement;
		modal.hidden = false;
		d.documentElement.classList.add('sccm-noscroll');
		var dialog = modal.querySelector('.sccm-modal__dialog');
		var items = focusables(dialog);
		(items[0] || dialog).focus();
	}

	function closePreferences() {
		if (!modal || modal.hidden) {
			return;
		}
		modal.hidden = true;
		d.documentElement.classList.remove('sccm-noscroll');
		if (lastFocus && typeof lastFocus.focus === 'function') {
			lastFocus.focus();
		}
	}

	/* ------------------------------------------------------------------ UI: floating button */

	function renderFloating() {
		if (!C.floating) {
			return;
		}
		if (!floating) {
			floating = el('button', {
				type: 'button',
				'class': 'sccm-root sccm-floating sccm-floating--' + C.floatingPos,
				'data-sccm-action': 'manage',
				'aria-label': T.settings_button,
				title: T.settings_button,
				html: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5zm-4.5 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm2 5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm5 1a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/></svg>'
			});
			d.body.appendChild(floating);
		}
		floating.hidden = !!(banner && !banner.hidden);
	}

	/* ------------------------------------------------------------------ Scanner (names only) */

	function reportUnknown() {
		if (!C.scan || !C.rest.report) {
			return;
		}
		var known = C.known || [];
		var items = [];
		function check(name, type) {
			if (name === C.cookie) {
				return;
			}
			var isKnown = known.some(function (k) {
				return k.t === type && nameMatches(k.n, name);
			});
			if (!isKnown && items.length < 30) {
				items.push({ n: name, t: type });
			}
		}
		cookieNames().forEach(function (name) {
			check(name, 'cookie');
		});
		storageKeys('localStorage').forEach(function (name) {
			check(name, 'localStorage');
		});
		if (!items.length) {
			return;
		}
		var signature = items.map(function (i) {
			return i.t + ':' + i.n;
		}).sort().join('|');
		try {
			if (w.sessionStorage.getItem('sccm_reported') === signature) {
				return;
			}
			w.sessionStorage.setItem('sccm_reported', signature);
		} catch (e) { /* ignore */ }
		post(C.rest.report, { items: items, url: w.location.href.split('#')[0] });
	}

	/* ------------------------------------------------------------------ Wiring */

	function onClick(event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}
		var opener = target.closest('[data-sccm-open], .sccm-open-preferences, a[href$="#sccm-preferences"]');
		if (opener) {
			event.preventDefault();
			openPreferences();
			return;
		}
		var action = target.closest('[data-sccm-action]');
		if (!action) {
			return;
		}
		switch (action.getAttribute('data-sccm-action')) {
			case 'accept':
				acceptAll();
				break;
			case 'reject':
				rejectAll();
				break;
			case 'save':
				saveFromModal();
				break;
			case 'manage':
				openPreferences();
				break;
			case 'close':
				closePreferences();
				break;
		}
	}

	function init() {
		d.addEventListener('click', onClick);
		cleanup();
		release();

		var gpcCoversAll = state.gpc && OPTIONAL.filter(categoryEnabled).every(isGpcBlocked);
		if (state.needsChoice && gpcCoversAll) {
			// GPC already refuses every optional category: record it, no banner needed.
			save('gpc', {});
		} else if (state.needsChoice) {
			showBanner();
		}
		renderFloating();

		if (w.location.hash === '#sccm-preferences') {
			openPreferences();
		} else if (w.location.hash === '#sccm-banner') {
			// Preview the banner (e.g. from the admin Appearance tab).
			showBanner();
		}
		if (C.scan) {
			setTimeout(reportUnknown, 4000);
		}
		emit('sccm:ready', { grants: state.grants, consent: state.consent });
	}

	w.SCCM = {
		version: C.v,
		getConsent: function () {
			return state.consent;
		},
		hasConsent: function (category) {
			return category === 'necessary' || state.grants[category] === true;
		},
		acceptAll: acceptAll,
		rejectAll: rejectAll,
		setConsent: function (grants) {
			save('custom', grants || {});
		},
		openPreferences: openPreferences,
		showBanner: showBanner,
		_test: { nameMatches: nameMatches, cleanGrants: cleanGrants, granted: granted, state: state }
	};

	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
