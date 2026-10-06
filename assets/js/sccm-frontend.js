/*!
 * Smart Cookie Consent Manager — front end.
 *
 * Consent dialog (banner + reopened window), releasing blocked content, cookie clean-up, GPC,
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

	var app = null;

	/**
	 * <div id="sccm-app"> at the end of <body>: holds the banner, the settings window and the
	 * widget. Its id scopes the CSS so theme styles for buttons, links… do not leak in.
	 */
	function mount(node) {
		if (!app || !app.parentNode) {
			app = d.getElementById('sccm-app') || el('div', { id: 'sccm-app' });
			if (!app.parentNode) {
				d.body.appendChild(app);
			}
		}
		app.appendChild(node);
		return node;
	}

	/**
	 * Buttons never break their text over two lines. When three do not fit side by side
	 * (narrow box, long translation, big theme font) they are stacked, all equally wide.
	 */
	function fitButtons(root) {
		if (!root || root.hidden) {
			return;
		}
		root.querySelectorAll('.sccm-actions').forEach(function (row) {
			row.classList.remove('sccm-actions--stack');
			var tooWide = Array.prototype.some.call(row.children, function (button) {
				return button.scrollWidth > button.clientWidth + 1;
			});
			if (tooWide) {
				row.classList.add('sccm-actions--stack');
			}
		});
	}

	var resizeTimer = null;
	w.addEventListener('resize', function () {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function () {
			fitButtons(banner);
			fitButtons(modal);
		}, 150);
	});

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

	var patternCache = {};

	/** Wildcard match: '*' = any characters. Compiled patterns are cached. */
	function nameMatches(pattern, name) {
		if (pattern.indexOf('*') === -1) {
			return pattern === name;
		}
		var re = patternCache[pattern];
		if (!re) {
			re = patternCache[pattern] = new RegExp('^' + pattern.split('*').map(function (part) {
				return part.replace(/[.+?^${}()|[\]\\]/g, '\\$&');
			}).join('.*') + '$');
		}
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

	/*
	 * The stylesheet is loaded without blocking the first paint (see SCCM_Frontend::style_tag).
	 * Anything we draw waits until it is applied, so nothing ever appears unstyled.
	 */
	var stylesReady = false;
	var styleQueue = [];

	function flushStyles() {
		if (stylesReady) {
			return;
		}
		stylesReady = true;
		var link = d.getElementById('sccm-frontend-css');
		if (link && link.media && link.media !== 'all') {
			link.media = 'all';
		}
		styleQueue.splice(0).forEach(function (fn) {
			fn();
		});
	}

	function afterStyles(fn) {
		if (stylesReady) {
			fn();
		} else {
			styleQueue.push(fn);
		}
	}

	(function watchStyles() {
		var link = d.getElementById('sccm-frontend-css');
		if (!link || !link.media || link.media === 'all') {
			stylesReady = true;
			return;
		}
		link.addEventListener('load', flushStyles);
		setTimeout(flushStyles, 1500);
	})();

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
		// C.days = 0 means "session only": the cookie has no expiry date.
		var expires = C.days > 0 ? '; expires=' + new Date(Date.now() + C.days * 86400000).toUTCString() : '';
		var secure = w.location.protocol === 'https:' ? '; Secure' : '';
		d.cookie = C.cookie + '=' + value + expires + '; path=/; SameSite=Lax' + secure;
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

	function removeStorage(type, patterns, keys) {
		(patterns || []).forEach(function (pattern) {
			keys.forEach(function (key) {
				if (nameMatches(pattern, key)) {
					try {
						w[type].removeItem(key);
					} catch (e) { /* ignore */ }
				}
			});
		});
	}

	/**
	 * Delete known cookies / storage keys of categories that are not granted.
	 * C.cleanup = { category: { c: [cookie names], l: [localStorage keys], s: [sessionStorage keys] } }
	 */
	function cleanup() {
		var map = C.cleanup || {};
		var refused = Object.keys(map).filter(function (category) {
			return !granted(category);
		});
		if (!refused.length) {
			return;
		}
		var names = cookieNames();
		var local = storageKeys('localStorage');
		var session = storageKeys('sessionStorage');
		refused.forEach(function (category) {
			var slots = map[category] || {};
			(slots.c || []).forEach(function (pattern) {
				names.forEach(function (name) {
					if (nameMatches(pattern, name)) {
						deleteCookie(name);
					}
				});
			});
			removeStorage('localStorage', slots.l, local);
			removeStorage('sessionStorage', slots.s, session);
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
		if ((prev && prev.classList.contains('sccm-placeholder')) || frame.hasAttribute('data-sccm-ph')) {
			return;
		}
		frame.setAttribute('data-sccm-ph', '1');
		var category = String(frame.getAttribute('data-sccm-category')).split(/[\s,]+/)[0];
		var label = categoryLabel(category);
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
		afterStyles(function () {
			// Allowed in the meantime: nothing to show.
			if (frame.hasAttribute('data-sccm-category') && frame.parentNode) {
				frame.parentNode.insertBefore(box, frame);
			}
		});
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
		if (C.scanMode) {
			return;
		}
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
		previewing = false;

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
			w.dataLayer.push({ event: 'sccm_consent_update', sccm_categories: OPTIONAL.filter(function (key) { return grants[key]; }), sccm_method: method, sccm_consent_id: consent.id });
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

	/** "Allow selection": whatever is switched on in the dialog the button belongs to. */
	function saveSelection(dialog) {
		var input = {};
		dialog.querySelectorAll('input[data-sccm-cat]').forEach(function (box) {
			input[box.getAttribute('data-sccm-cat')] = box.checked;
		});
		save('custom', input);
	}

	/* ------------------------------------------------------------------ UI: the consent dialog
	 *
	 * One dialog, modelled on the common CMP layout: a header, three tabs (Consent, Details,
	 * About) and three equal buttons. It is used twice:
	 *   - #sccm-banner: first visit, in the position chosen by the site owner;
	 *   - #sccm-prefs:  reopened later (widget, link, shortcode), always centred with a close button.
	 * Details are built only when the Details tab is first opened, so first-time visitors who
	 * click Allow/Deny right away never pay for building the cookie list.
	 */

	function categoryLabel(key) {
		var label = key;
		C.categories.forEach(function (cat) {
			if (cat.key === key) {
				label = cat.label;
			}
		});
		return label;
	}

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

	/**
	 * Whether a dialog shows the visitor's stored choice. The banner opened as a preview
	 * (#sccm-banner, e.g. by the site owner who already chose) looks exactly like a first visit,
	 * and so does the window its "Customize" button opens.
	 *
	 * @param {string} prefix Dialog id prefix ('sccm-b' = banner, 'sccm-' = reopened window).
	 */
	function showsChoice(prefix) {
		if (!previewing) {
			return true;
		}
		return prefix === 'sccm-b' ? false : !bannerAway;
	}

	/** On/off switch for one category. Several switches for the same category stay in sync. */
	function categorySwitch(cat, id, labelled, prefix) {
		var locked = cat.locked || isGpcBlocked(cat.key);
		return el('span', { 'class': 'sccm-switch' }, [
			el('input', {
				type: 'checkbox',
				role: 'switch',
				id: id,
				'data-sccm-cat': cat.locked ? null : cat.key,
				checked: cat.locked || (!isGpcBlocked(cat.key) && showsChoice(prefix) && state.grants[cat.key] === true),
				disabled: locked,
				'aria-label': labelled ? null : cat.label
			}),
			el('span', { 'class': 'sccm-slider', 'aria-hidden': 'true' })
		]);
	}

	/** Button order per setting; 'more' = "Allow selection" in the dialog, "Customize" in the compact banner. */
	var ORDERS = {
		accept_first: ['accept', 'more', 'reject'],
		reject_first: ['reject', 'more', 'accept'],
		accept_reject: ['accept', 'reject', 'more'],
		reject_accept: ['reject', 'accept', 'more']
	};

	/**
	 * The three buttons, in the order chosen in the settings. Allow all, Deny and Allow selection
	 * always share one style.
	 *
	 * @param {HTMLElement} more The third button (Allow selection or Customize).
	 */
	function actionButtons(more) {
		var buttons = {
			accept: el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--accept', 'data-sccm-action': 'accept', text: T.btn_accept }),
			reject: el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--reject', 'data-sccm-action': 'reject', text: T.btn_reject }),
			more: more || el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--selection', 'data-sccm-action': 'selection', text: T.btn_selection })
		};
		return (ORDERS[C.order] || ORDERS.accept_reject).map(function (key) {
			return buttons[key];
		});
	}

	/** Consent tab: the text, then one switch per category across the full width. */
	function consentPanel(prefix) {
		var toggles = el('div', { 'class': 'sccm-toggles', style: '--sccm-n:' + C.categories.length }, C.categories.map(function (cat) {
			var id = prefix + 'cat-' + cat.key;
			return el('label', { 'class': 'sccm-toggle', 'for': id }, [
				el('span', { 'class': 'sccm-toggle__label', text: cat.label }),
				categorySwitch(cat, id, true, prefix)
			]);
		}));
		return [
			el('div', { 'class': 'sccm-text', id: prefix + 'text', html: T.banner_text }),
			linksRow(),
			toggles
		];
	}

	/** Accordion header + panel. */
	function accordion(className, headChildren, panelChildren, extra) {
		var panel = el('div', { 'class': className + '__panel', hidden: true }, panelChildren);
		var toggle = el('button', { type: 'button', 'class': className + '__toggle', 'aria-expanded': 'false', 'data-sccm-acc': '' }, headChildren);
		return el('div', { 'class': className }, [el('div', { 'class': className + '__head' }, [toggle, extra || null]), panel]);
	}

	/** One cookie, as a card: name, purpose, maximum storage duration, type. */
	function cookieCard(c) {
		return el('div', { 'class': 'sccm-ck' }, [
			el('p', { 'class': 'sccm-ck__name', text: c.n }),
			c.u ? el('p', { 'class': 'sccm-ck__purpose', text: c.u }) : null,
			el('p', { 'class': 'sccm-ck__meta' }, [
				el('span', null, [el('strong', { text: T.col_duration + ': ' }), c.d || '—']),
				el('span', null, [el('strong', { text: T.col_type + ': ' }), T['type_' + (c.t || 'cookie')] || c.t])
			])
		]);
	}

	/** Details tab: category accordions; inside each, one accordion per provider with cookie cards. */
	function detailsPanel(prefix) {
		var nodes = [];
		if (state.gpc && state.gpcBlocked.length) {
			nodes.push(el('p', { 'class': 'sccm-notice', role: 'status', text: String(T.gpc_notice || '').replace('%s', state.gpcBlocked.filter(categoryEnabled).map(categoryLabel).join(', ')) }));
		}
		C.categories.forEach(function (cat) {
			var providers = {};
			var order = [];
			cat.cookies.forEach(function (c) {
				var name = c.p || T.provider_site;
				if (!providers[name]) {
					providers[name] = [];
					order.push(name);
				}
				providers[name].push(c);
			});
			var inner = [el('p', { 'class': 'sccm-acc__desc', text: cat.description })];
			if (!order.length) {
				inner.push(el('p', { 'class': 'sccm-muted', text: T.no_cookies }));
			}
			order.forEach(function (name) {
				inner.push(accordion('sccm-prov', [
					el('span', { 'class': 'sccm-chev', 'aria-hidden': 'true' }),
					el('span', { 'class': 'sccm-prov__name', text: name }),
					el('span', { 'class': 'sccm-count', text: String(providers[name].length) })
				], providers[name].map(cookieCard)));
			});
			nodes.push(accordion('sccm-acc', [
				el('span', { 'class': 'sccm-chev', 'aria-hidden': 'true' }),
				el('span', { 'class': 'sccm-acc__name', text: cat.label }),
				el('span', { 'class': 'sccm-count', text: String(cat.cookies.length) })
			], inner, categorySwitch(cat, prefix + 'dcat-' + cat.key, false, prefix)));
		});
		return nodes;
	}

	/** About tab: what cookies are, links, and the visitor's own consent (choice, date, ID). */
	function aboutPanel() {
		return [
			el('div', { 'class': 'sccm-text', html: T.about_text }),
			el('div', { 'class': 'sccm-status', 'aria-live': 'polite' }),
			linksRow()
		];
	}

	function renderStatus(dialog) {
		var box = dialog.querySelector('.sccm-status');
		if (!box) {
			return;
		}
		box.textContent = '';
		var consent = showsChoice(dialog.getAttribute('data-sccm-prefix')) ? state.consent : null;
		if (!consent) {
			box.appendChild(el('p', { 'class': 'sccm-muted', text: T.no_choice }));
			return;
		}
		var when = new Date(consent.t * 1000);
		var whenText;
		try {
			whenText = when.toLocaleString();
		} catch (e) {
			whenText = when.toISOString();
		}
		var copy = el('button', { type: 'button', 'class': 'sccm-copy', text: T.copy });
		copy.addEventListener('click', function () {
			copyText(consent.id, function () {
				copy.textContent = T.copied;
				setTimeout(function () {
					copy.textContent = T.copy;
				}, 1600);
			});
		});
		box.appendChild(el('p', { 'class': 'sccm-status__row' }, [el('strong', { text: T.consent_status + ': ' }), T['choice_' + consent.m] || '']));
		box.appendChild(el('p', { 'class': 'sccm-status__row' }, [el('strong', { text: T.consent_date + ': ' }), whenText]));
		box.appendChild(el('p', { 'class': 'sccm-status__row sccm-consent-id' }, [el('strong', { text: T.consent_id + ': ' }), el('code', { text: consent.id }), copy]));
	}

	function copyText(text, done) {
		function fallback() {
			var area = el('textarea', { readonly: true, style: 'position:fixed;opacity:0;top:0;left:0' });
			area.value = text;
			d.body.appendChild(area);
			area.select();
			try {
				if (d.execCommand('copy')) {
					done();
				}
			} catch (e) { /* ignore */ }
			d.body.removeChild(area);
		}
		if (w.navigator.clipboard && w.navigator.clipboard.writeText) {
			w.navigator.clipboard.writeText(text).then(done, fallback);
		} else {
			fallback();
		}
	}

	/**
	 * Compact first layer: title, text, links and three buttons. "Customize" opens the full
	 * dialog (tabs, switches, every cookie). Allow all and Deny always look the same.
	 */
	function buildCompactBanner() {
		var isModal = C.position === 'center';
		var buttons = actionButtons(el('button', { type: 'button', 'class': 'sccm-btn sccm-btn--customize', 'data-sccm-action': 'customize', text: T.btn_customize }));
		var root = el('div', {
			id: 'sccm-banner',
			'class': 'sccm-root sccm-dialog sccm-compact sccm-pos-' + C.position,
			role: isModal ? 'dialog' : 'region',
			'aria-modal': isModal ? 'true' : null,
			'aria-labelledby': 'sccm-btitle',
			'aria-describedby': 'sccm-btext'
		}, [
			isModal ? el('div', { 'class': 'sccm-overlay' }) : null,
			el('div', { 'class': 'sccm-dialog__box' }, [
				el('div', { 'class': 'sccm-compact__content' }, [
					el('p', { 'class': 'sccm-title', id: 'sccm-btitle', role: 'heading', 'aria-level': '2', text: T.banner_title }),
					el('div', { 'class': 'sccm-text', id: 'sccm-btext', html: T.banner_text }),
					linksRow()
				]),
				el('div', { 'class': 'sccm-actions sccm-compact__actions' }, buttons)
			])
		]);
		return mount(root);
	}

	/**
	 * Build a dialog.
	 *
	 * @param {string} kind 'banner' (first visit) or 'prefs' (reopened later)
	 */
	function buildDialog(kind) {
		var prefix = kind === 'banner' ? 'sccm-b' : 'sccm-';
		var position = kind === 'banner' ? C.position : 'center';
		var isModal = position === 'center';
		var tabs = ['consent', 'details', 'about'];
		var panels = {
			consent: consentPanel(prefix),
			details: [],
			about: aboutPanel()
		};
		var tabList = el('div', { 'class': 'sccm-tabs', role: 'tablist' }, tabs.map(function (tab, i) {
			return el('button', {
				type: 'button',
				role: 'tab',
				'class': 'sccm-tab',
				id: prefix + 'tab-' + tab,
				'data-sccm-tab': tab,
				'aria-selected': i === 0 ? 'true' : 'false',
				'aria-controls': prefix + 'panel-' + tab,
				tabindex: i === 0 ? '0' : '-1',
				text: T['tab_' + tab]
			});
		}));
		var body = el('div', { 'class': 'sccm-dialog__body' }, tabs.map(function (tab, i) {
			return el('div', {
				'class': 'sccm-panel sccm-panel--' + tab,
				role: 'tabpanel',
				id: prefix + 'panel-' + tab,
				'aria-labelledby': prefix + 'tab-' + tab,
				hidden: i !== 0
			}, panels[tab]);
		}));
		var head = el('div', { 'class': 'sccm-dialog__head' }, [
			el('p', { 'class': 'sccm-title', id: prefix + 'title', role: 'heading', 'aria-level': '2', text: T.banner_title }),
			kind === 'prefs' ? el('button', { type: 'button', 'class': 'sccm-close', 'data-sccm-action': 'close', 'aria-label': T.close, html: '&times;' }) : null
		]);
		var root = el('div', {
			id: kind === 'banner' ? 'sccm-banner' : 'sccm-prefs',
			'class': 'sccm-root sccm-dialog sccm-pos-' + position,
			role: isModal ? 'dialog' : 'region',
			'aria-modal': isModal ? 'true' : null,
			'aria-labelledby': prefix + 'title',
			'aria-describedby': prefix + 'text',
			hidden: kind === 'prefs'
		}, [
			isModal ? el('div', { 'class': 'sccm-overlay', 'data-sccm-action': kind === 'prefs' ? 'close' : null }) : null,
			el('div', { 'class': 'sccm-dialog__box', tabindex: '-1' }, [
				head,
				tabList,
				body,
				el('div', { 'class': 'sccm-dialog__foot' }, [el('div', { 'class': 'sccm-actions' }, actionButtons())])
			])
		]);
		root.setAttribute('data-sccm-prefix', prefix);
		root.addEventListener('change', syncSwitches);
		root.addEventListener('keydown', onDialogKey);
		return mount(root);
	}

	/** Keep the Consent-tab and Details-tab switches of one category in step. */
	function syncSwitches(event) {
		var box = event.target;
		var key = box && box.getAttribute && box.getAttribute('data-sccm-cat');
		if (!key) {
			return;
		}
		event.currentTarget.querySelectorAll('input[data-sccm-cat="' + key + '"]').forEach(function (other) {
			other.checked = box.checked;
		});
	}

	function showTab(dialog, tab) {
		var prefix = dialog.getAttribute('data-sccm-prefix');
		if (tab === 'details') {
			var panel = dialog.querySelector('.sccm-panel--details');
			if (!panel.hasChildNodes()) {
				detailsPanel(prefix).forEach(function (node) {
					panel.appendChild(node);
				});
			}
		}
		if (tab === 'about') {
			renderStatus(dialog);
		}
		dialog.querySelectorAll('.sccm-tab').forEach(function (button) {
			var on = button.getAttribute('data-sccm-tab') === tab;
			button.setAttribute('aria-selected', on ? 'true' : 'false');
			button.setAttribute('tabindex', on ? '0' : '-1');
		});
		dialog.querySelectorAll('.sccm-panel').forEach(function (panel) {
			panel.hidden = !panel.classList.contains('sccm-panel--' + tab);
		});
		dialog.querySelector('.sccm-dialog__body').scrollTop = 0;
	}

	function syncDialog(dialog) {
		var shown = showsChoice(dialog.getAttribute('data-sccm-prefix'));
		dialog.querySelectorAll('input[data-sccm-cat]').forEach(function (box) {
			var key = box.getAttribute('data-sccm-cat');
			box.checked = shown && !isGpcBlocked(key) && state.grants[key] === true;
		});
		renderStatus(dialog);
	}

	var bannerQueued = false;
	var bannerAway = false;
	var previewing = false;

	/**
	 * Show the banner. Nothing is drawn before the stylesheet is applied.
	 *
	 * @param {boolean} force Show even when the visitor has already chosen (admin preview).
	 */
	function showBanner(force) {
		if (banner) {
			banner.hidden = false;
			if (floating) {
				floating.hidden = true;
			}
			return;
		}
		if (bannerQueued) {
			return;
		}
		bannerQueued = true;
		afterStyles(function () {
			bannerQueued = false;
			if (state.needsChoice || force === true) {
				previewing = !state.needsChoice;
				banner = C.layout === 'tabs' ? buildDialog('banner') : buildCompactBanner();
				fitButtons(banner);
				if (floating) {
					floating.hidden = true;
				}
			}
		});
	}

	function hideBanner() {
		if (banner) {
			banner.hidden = true;
		}
	}

	function focusables(root) {
		return Array.prototype.filter.call(root.querySelectorAll('button, [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'), function (node) {
			return node.offsetParent !== null;
		});
	}

	/** Keyboard: arrows move between tabs; in the reopened window, Tab stays inside and Esc closes. */
	function onDialogKey(event) {
		var dialog = event.currentTarget;
		var tab = event.target.closest && event.target.closest('.sccm-tab');
		if (tab && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
			var list = Array.prototype.slice.call(dialog.querySelectorAll('.sccm-tab'));
			var next = list[(list.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
			showTab(dialog, next.getAttribute('data-sccm-tab'));
			next.focus();
			event.preventDefault();
			return;
		}
		if (dialog !== modal) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			closePreferences();
			return;
		}
		if (event.key !== 'Tab') {
			return;
		}
		var items = focusables(dialog);
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

	function openPreferences(tab) {
		afterStyles(function () {
			if (!modal) {
				modal = buildDialog('prefs');
			}
			syncDialog(modal);
			showTab(modal, typeof tab === 'string' ? tab : 'consent');
			lastFocus = d.activeElement;
			modal.hidden = false;
			fitButtons(modal);
			d.documentElement.classList.add('sccm-noscroll');
			modal.querySelector('.sccm-dialog__box').focus();
		});
	}

	function closePreferences() {
		if (!modal || modal.hidden) {
			return;
		}
		modal.hidden = true;
		d.documentElement.classList.remove('sccm-noscroll');
		// Closed without choosing after "Customize": the banner comes back (closing is never consent).
		if (bannerAway && (state.needsChoice || previewing) && banner) {
			banner.hidden = false;
		}
		bannerAway = false;
		if (lastFocus && typeof lastFocus.focus === 'function') {
			lastFocus.focus();
		}
	}

	/* ------------------------------------------------------------------ UI: floating button */

	/**
	 * The "Cookie settings" widget, shown after a choice. In a corner it is a round icon button;
	 * at the bottom centre or the middle of a side it is a slim tab with a text label attached to
	 * the edge of the screen (see the CSS).
	 */
	function renderFloating() {
		if (!C.floating || C.scanMode) {
			return;
		}
		afterStyles(function () {
			if (!floating) {
				var tab = /center/.test(C.floatingPos);
				floating = el('button', {
					type: 'button',
					'class': 'sccm-root sccm-floating sccm-floating--' + C.floatingPos + (tab ? ' sccm-floating--tab' : ''),
					'data-sccm-action': 'manage',
					'aria-label': T.settings_button,
					title: T.settings_button
				}, [
					el('span', { 'class': 'sccm-floating__icon', 'aria-hidden': 'true', html: '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5zm-4.5 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm2 5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm5 1a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/></svg>' }),
					tab ? el('span', { 'class': 'sccm-floating__label', text: T.widget_label }) : null
				]);
				mount(floating);
			}
			floating.hidden = !!(banner && !banner.hidden);
		});
	}

	/* ------------------------------------------------------------------ Scanner (names only) */

	/**
	 * Tell the server about cookie NAMES (never values) that are not in the list yet.
	 * Cookies only, and never from logged-in users (their browsers carry extensions and admin
	 * tools that are not part of the website). The server lists a cookie only after several
	 * different visitors reported it.
	 */
	function reportUnknown() {
		if (!C.scan || !C.rest.report) {
			return;
		}
		var cls = d.body ? d.body.classList : null;
		if (cls && (cls.contains('logged-in') || cls.contains('admin-bar'))) {
			return;
		}
		var known = C.known || [];
		var items = [];
		cookieNames().forEach(function (name) {
			if (name === C.cookie || items.length >= 30) {
				return;
			}
			var isKnown = known.some(function (pattern) {
				return nameMatches(pattern, name);
			});
			if (!isKnown) {
				items.push({ n: name, t: 'cookie' });
			}
		});
		if (!items.length) {
			return;
		}
		var signature = items.map(function (i) {
			return i.n;
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
		var dialog = target.closest('.sccm-dialog');
		var tab = target.closest('[data-sccm-tab]');
		if (tab && dialog) {
			showTab(dialog, tab.getAttribute('data-sccm-tab'));
			return;
		}
		var acc = target.closest('[data-sccm-acc]');
		if (acc) {
			var open = acc.getAttribute('aria-expanded') !== 'true';
			acc.setAttribute('aria-expanded', open ? 'true' : 'false');
			acc.parentNode.nextElementSibling.hidden = !open;
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
			case 'selection':
				saveSelection(dialog);
				break;
			case 'manage':
				openPreferences();
				break;
			case 'customize':
				hideBanner();
				bannerAway = true;
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

		if (C.scanMode) {
			// Admin cookie scan: everything is released, nothing is shown, stored or reported.
			emit('sccm:ready', { grants: state.grants, consent: null });
			return;
		}

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
			// Preview the banner (e.g. from the admin Banner tab).
			showBanner(true);
		}
		if (C.scan) {
			// Low priority: wait until the page is idle so it never competes with the visitor.
			setTimeout(function () {
				if (w.requestIdleCallback) {
					w.requestIdleCallback(reportUnknown, { timeout: 3000 });
				} else {
					reportUnknown();
				}
			}, 4000);
		}
		emit('sccm:ready', { grants: state.grants, consent: state.consent });
	}

	w.SCCM = {
		version: C.v,
		getConsent: function () {
			return state.consent;
		},
		getConsentId: function () {
			return state.consent ? state.consent.id : null;
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
		showBanner: showBanner
	};

	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
