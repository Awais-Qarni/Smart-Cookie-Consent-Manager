/* Smart Cookie Consent Manager — admin helpers. */
(function ($) {
	'use strict';

	$(function () {
		// Colour pickers.
		if ($.fn.wpColorPicker) {
			$('.sccm-color').wpColorPicker();
		}

		// Confirm destructive actions.
		$(document).on('click', '[data-sccm-confirm]', function (event) {
			if (!window.confirm((window.SCCM_ADMIN && window.SCCM_ADMIN.confirm) || 'Are you sure?')) {
				event.preventDefault();
			}
		});

		// Help panel: open from the header button, the dashboard button, or the #help link.
		var $help = $('#sccm-help');
		var $toggle = $('#sccm-help-toggle');
		function setHelp(open) {
			$help.prop('hidden', !open);
			$toggle.attr('aria-expanded', open ? 'true' : 'false');
			if (open) {
				$help[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}
		$toggle.on('click', function () {
			setHelp($help.prop('hidden'));
		});
		$(document).on('click', '[data-sccm-open-help]', function () {
			setHelp(true);
		});
		$(document).on('click', '[data-sccm-close-help]', function () {
			setHelp(false);
			$toggle.trigger('focus');
		});
		if (window.location.hash === '#help') {
			setHelp(true);
		}

		// "Remember the choice for": a preset sets the (hidden) number of days; "custom" shows the days field.
		var $preset = $('[data-sccm-expiry-preset]');
		var $days = $('#sccm-consent_expiry_days');
		$preset.on('change', function () {
			var custom = this.value === 'custom';
			$days.closest('.sccm-expiry-days').prop('hidden', !custom);
			if (custom) {
				$days.trigger('focus').trigger('select');
			} else {
				$days.val(this.value);
			}
		});

		// "Scan now": server scan, then the pages in a hidden frame in this browser (see browserScan below).
		$(document).on('submit', 'form[data-sccm-browser-scan]', function (event) {
			if (!window.Promise || !window.SCCM_ADMIN || !window.SCCM_ADMIN.ajaxUrl) {
				return; // Old browser: the form posts normally and only the server scan runs.
			}
			event.preventDefault();
			browserScan($(this));
		});

		// Custom blocking rules: add / remove rows.
		var $rules = $('#sccm-rules tbody');
		$('#sccm-add-rule').on('click', function () {
			var $last = $rules.find('tr').last();
			var $row = $last.clone();
			var index = $rules.find('tr').length;
			$row.find('input, select').each(function () {
				this.name = this.name.replace(/\[rules\]\[\d+\]/, '[rules][' + index + ']');
				if (this.tagName === 'INPUT') {
					this.value = '';
				}
			});
			$rules.append($row);
			$row.find('input').trigger('focus');
		});
		$(document).on('click', '.sccm-remove-row', function () {
			var $row = $(this).closest('tr');
			if ($rules.find('tr').length > 1) {
				$row.remove();
			} else {
				$row.find('input').val('');
			}
		});
	});

	/* ------------------------------------------------------------------ Browser scan
	 *
	 * The server cannot run JavaScript, so it cannot see cookies set by scripts (Google
	 * Analytics, HubSpot, chat widgets…). Here each page is opened in a hidden, sandboxed frame
	 * in scan mode (every category allowed, nothing stored, see SCCM_Frontend::is_scan_mode()),
	 * and the cookie names, storage keys and third-party addresses it used are sent back.
	 */

	var A = window.SCCM_ADMIN || {};

	function wait(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	function cookieNames(doc) {
		return (doc.cookie || '').split(';').map(function (part) {
			return part.split('=')[0].trim();
		}).filter(Boolean);
	}

	function storageKeys(win, type) {
		try {
			var store = win[type];
			var keys = [];
			for (var i = 0; i < store.length; i++) {
				keys.push(store.key(i));
			}
			return keys;
		} catch (e) {
			return [];
		}
	}

	/** Open one page in a hidden frame and collect what it set and loaded. Null when blocked. */
	function scanPage(url) {
		var frame = document.createElement('iframe');
		frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
		frame.setAttribute('aria-hidden', 'true');
		frame.tabIndex = -1;
		frame.style.cssText = 'position:fixed;left:-12000px;top:0;width:1280px;height:900px;border:0;opacity:0;pointer-events:none';
		var loaded = new Promise(function (resolve) {
			frame.onload = resolve;
			setTimeout(resolve, 20000);
		});
		frame.src = url;
		document.body.appendChild(frame);

		return loaded.then(function () {
			var win = frame.contentWindow;
			var doc;
			try {
				doc = frame.contentDocument;
				if (!doc || !doc.body || !win.SCCM_CONFIG) {
					return null; // Blocked (security header), redirected, or not our page.
				}
			} catch (e) {
				return null;
			}
			// Wait until the page stops loading new files (2–9 s), scrolling to wake lazy content.
			var last = -1;
			var stable = 0;
			var elapsed = 0;
			function settle() {
				return wait(500).then(function () {
					elapsed += 500;
					try {
						win.scrollTo(0, elapsed * 1.5);
					} catch (e) { /* ignore */ }
					var count = win.performance.getEntriesByType('resource').length;
					stable = count === last ? stable + 500 : 0;
					last = count;
					if (elapsed < 9000 && !(stable >= 2000 && elapsed >= 2500)) {
						return settle();
					}
				});
			}
			return settle().then(function () {
				var resources = win.performance.getEntriesByType('resource').map(function (entry) {
					return entry.name;
				});
				Array.prototype.forEach.call(doc.querySelectorAll('iframe[src], script[src]'), function (node) {
					resources.push(node.src);
				});
				return {
					cookies: cookieNames(doc),
					local: storageKeys(win, 'localStorage'),
					session: storageKeys(win, 'sessionStorage'),
					written: win.__sccmScanKeys || { localStorage: {}, sessionStorage: {} },
					resources: resources
				};
			});
		}).catch(function () {
			return null;
		}).then(function (result) {
			frame.parentNode.removeChild(frame);
			return result;
		});
	}

	function browserScan($form) {
		var $button = $form.find('button').prop('disabled', true);
		var $status = $('<p class="sccm-scan-status" role="status"><span class="spinner is-active"></span><span class="sccm-scan-status__text"></span></p>').insertAfter($form);
		var say = function (text) {
			$status.find('.sccm-scan-status__text').text(text);
		};
		var fail = function (message) {
			$status.find('.spinner').remove();
			say(A.scanFailed.replace('%s', message || ''));
			$button.prop('disabled', false);
		};
		// Storage keys the browser already has (from the dashboard or extensions) are "old".
		var baseline = {
			localStorage: storageKeys(window, 'localStorage'),
			sessionStorage: storageKeys(window, 'sessionStorage')
		};
		say(A.scanServer);

		/** Server part: one request per step until every planned page was checked. */
		function serverSteps(data) {
			if (data.total) {
				say(A.scanServerProgress.replace('%1$d', data.done).replace('%2$d', data.total));
			}
			if (data.finished) {
				return Promise.resolve();
			}
			return new Promise(function (resolve, reject) {
				$.post(A.ajaxUrl, { action: 'sccm_browser_scan_server', _ajax_nonce: A.scanNonce }).done(function (response) {
					if (response && response.success) {
						resolve(serverSteps(response.data));
					} else {
						reject(response && response.data && response.data.message);
					}
				}).fail(function (xhr) {
					reject(xhr && xhr.status ? 'HTTP ' + xhr.status : '');
				});
			});
		}

		$.post(A.ajaxUrl, { action: 'sccm_browser_scan_start', _ajax_nonce: A.scanNonce }).done(function (response) {
			if (!response || !response.success) {
				fail(response && response.data && response.data.message);
				return;
			}
			var urls = response.data.urls;
			var found = { cookies: {}, storage: {}, resources: {} };
			var pages = 0;
			var blocked = 0;
			var next = 0;
			// Three pages at a time: a big scan takes a minute or two instead of several.
			function worker() {
				if (next >= urls.length) {
					return Promise.resolve();
				}
				var url = urls[next++];
				return scanPage(url).then(function (result) {
					pages++;
					say(A.scanPage.replace('%1$d', pages).replace('%2$d', urls.length));
					collect(result);
					return worker();
				});
			}
			function collect(result) {
				if (!result) {
					blocked++;
					return;
				}
				result.cookies.forEach(function (name) {
					found.cookies[name] = true;
				});
				// A key is the site's own when the page wrote it during the scan, or when the
				// browser did not have it before the scan started.
				[['localStorage', result.local], ['sessionStorage', result.session]].forEach(function (pair) {
					pair[1].forEach(function (name) {
						var own = !!result.written[pair[0]][name] || baseline[pair[0]].indexOf(name) === -1;
						var key = pair[0] + ':' + name;
						found.storage[key] = { n: name, t: pair[0], new: own || !!(found.storage[key] && found.storage[key].new) };
					});
				});
				result.resources.forEach(function (resource) {
					if (/^https?:/.test(resource)) {
						found.resources[resource.split('#')[0].slice(0, 500)] = true;
					}
				});
			}
			serverSteps(response.data).then(function () {
				say(A.scanPage.replace('%1$d', 1).replace('%2$d', urls.length));
				return Promise.all([worker(), worker(), worker()]);
			}).then(function () {
				say(A.scanSaving);
				var data = {
					pages: pages,
					blocked: blocked,
					cookies: Object.keys(found.cookies),
					storage: Object.keys(found.storage).map(function (key) {
						return found.storage[key];
					}),
					resources: Object.keys(found.resources).slice(0, 500)
				};
				$.post(A.ajaxUrl, { action: 'sccm_browser_scan_report', _ajax_nonce: A.scanNonce, token: response.data.token, data: JSON.stringify(data) }).done(function (result) {
					if (result && result.success) {
						window.location.href = result.data.redirect;
					} else {
						fail(result && result.data && result.data.message);
					}
				}).fail(function () {
					fail('');
				});
			}, function (message) {
				fail(message);
			});
		}).fail(function (xhr) {
			fail(xhr && xhr.status ? 'HTTP ' + xhr.status : '');
		});
	}
})(jQuery);
