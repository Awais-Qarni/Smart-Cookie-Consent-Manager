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

	/*
	 * Timers for the scan run in a small Web Worker: browsers slow down timers of background tabs
	 * (to once a second, later once a minute), which would make a scan crawl when the admin
	 * switches to another tab. Worker timers are not slowed down. Falls back to setTimeout.
	 */
	var timer = null;
	var timerId = 0;
	var timerWaiting = {};

	function wait(ms) {
		if (timer === null) {
			try {
				var code = 'onmessage=function(e){setTimeout(function(){postMessage(e.data.id);},e.data.ms);};';
				timer = new Worker(URL.createObjectURL(new Blob([code], { type: 'text/javascript' })));
				timer.onmessage = function (event) {
					var done = timerWaiting[event.data];
					delete timerWaiting[event.data];
					if (done) {
						done();
					}
				};
			} catch (e) {
				timer = false;
			}
		}
		return new Promise(function (resolve) {
			if (timer) {
				timerId++;
				timerWaiting[timerId] = resolve;
				timer.postMessage({ id: timerId, ms: ms });
			} else {
				setTimeout(resolve, ms);
			}
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
			wait(20000).then(resolve);
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

	/** "Scan now": plan + server part, then the browser part. */
	function browserScan($form) {
		var ui = scanUi($form, $form.find('button'));
		ui.say(A.scanServer);
		$.post(A.ajaxUrl, { action: 'sccm_browser_scan_start', _ajax_nonce: A.scanNonce }).done(function (response) {
			if (!response || !response.success) {
				ui.fail(response && response.data && response.data.message);
				return;
			}
			runScan(response.data, ui, true);
		}).fail(function (xhr) {
			ui.fail(xhr && xhr.status ? 'HTTP ' + xhr.status : '');
		});
	}

	/** Status line under the scan button (or at the top of any plugin page when resuming). */
	function scanUi($after, $button) {
		var $status = $('<p class="sccm-scan-status" role="status"><span class="spinner is-active"></span><span class="sccm-scan-status__text"></span></p>').insertAfter($after);
		$button.prop('disabled', true);
		return {
			say: function (text) {
				$status.find('.sccm-scan-status__text').text(text);
			},
			fail: function (message) {
				stopGuard();
				$status.find('.spinner').remove();
				$status.find('.sccm-scan-status__text').text(A.scanFailed.replace('%s', message || ''));
				$button.prop('disabled', false);
			},
			done: function (text) {
				stopGuard();
				$status.find('.spinner').remove();
				$status.find('.sccm-scan-status__text').text(text);
				$button.prop('disabled', false);
			}
		};
	}

	/* While a scan runs: the browser asks before leaving the page, and what was found so far is
	 * sent even when the page is left anyway, so the scan can continue later. */
	var guard = null;

	function onLeaveWarn(event) {
		event.preventDefault();
		event.returnValue = '';
	}

	function stopGuard() {
		if (guard) {
			window.removeEventListener('beforeunload', onLeaveWarn);
			window.removeEventListener('pagehide', guard);
			guard = null;
		}
	}

	/**
	 * Run (or continue) a scan.
	 *
	 * @param {Object}  data    token, urls (pages still to open), total, opened, done/finished (server part)
	 * @param {Object}  ui      scanUi()
	 * @param {boolean} onPage  Started on this page with "Scan now" (go to the result when finished).
	 */
	function runScan(data, ui, onPage) {
		var urls = data.urls || [];
		var total = data.total || urls.length;
		var opened = data.opened || 0;
		var next = 0;
		var finished = false;
		// Storage keys the browser already has (from the dashboard or extensions) are "old".
		var baseline = {
			localStorage: storageKeys(window, 'localStorage'),
			sessionStorage: storageKeys(window, 'sessionStorage')
		};
		var batch;

		function newBatch() {
			batch = { pages: 0, blocked: 0, cookies: {}, storage: {}, resources: {}, done: [] };
		}
		newBatch();

		function payload() {
			return JSON.stringify({
				pages: batch.pages,
				blocked: batch.blocked,
				cookies: Object.keys(batch.cookies),
				storage: Object.keys(batch.storage).map(function (key) {
					return batch.storage[key];
				}),
				resources: Object.keys(batch.resources).slice(0, 500),
				done: batch.done
			});
		}

		/** Send what was found since the last report. */
		function report(final) {
			var body = { action: 'sccm_browser_scan_report', _ajax_nonce: A.scanNonce, token: data.token, data: payload(), final: final ? 1 : 0 };
			newBatch();
			return new Promise(function (resolve, reject) {
				$.post(A.ajaxUrl, body).done(function (result) {
					if (result && result.success) {
						resolve(result.data);
					} else {
						reject(result && result.data && result.data.message);
					}
				}).fail(function (xhr) {
					reject(xhr && xhr.status ? 'HTTP ' + xhr.status : '');
				});
			});
		}

		// Leaving the page: warn first; if left anyway, send the rest with sendBeacon (no reply needed).
		stopGuard();
		guard = function () {
			if (!finished && navigator.sendBeacon) {
				var form = new FormData();
				form.append('action', 'sccm_browser_scan_report');
				form.append('_ajax_nonce', A.scanNonce);
				form.append('token', data.token);
				form.append('data', payload());
				form.append('final', '0');
				form.append('leaving', '1'); // The next plugin page may continue right away.
				navigator.sendBeacon(A.ajaxUrl, form);
			}
		};
		window.addEventListener('beforeunload', onLeaveWarn);
		window.addEventListener('pagehide', guard);

		/** Server part: one request per step until every planned page was checked. */
		function serverSteps(step) {
			if (step.total && !step.finished) {
				ui.say(A.scanServerProgress.replace('%1$d', step.done).replace('%2$d', step.total));
			}
			if (step.finished) {
				return Promise.resolve();
			}
			var before = step.done;
			return new Promise(function (resolve, reject) {
				$.post(A.ajaxUrl, { action: 'sccm_browser_scan_server', _ajax_nonce: A.scanNonce }).done(function (response) {
					if (response && response.success) {
						// No progress means the background part is busy with a step: ask again shortly.
						var pause = response.data.done === before && !response.data.finished ? wait(3000) : Promise.resolve();
						pause.then(function () {
							resolve(serverSteps(response.data));
						});
					} else {
						reject(response && response.data && response.data.message);
					}
				}).fail(function (xhr) {
					reject(xhr && xhr.status ? 'HTTP ' + xhr.status : '');
				});
			});
		}

		function collect(url, result) {
			batch.pages++;
			batch.done.push(url);
			if (!result) {
				batch.blocked++;
				return;
			}
			result.cookies.forEach(function (name) {
				batch.cookies[name] = true;
			});
			// A key is the site's own when the page wrote it during the scan, or when the
			// browser did not have it before the scan started.
			[['localStorage', result.local], ['sessionStorage', result.session]].forEach(function (pair) {
				pair[1].forEach(function (name) {
					var own = !!result.written[pair[0]][name] || baseline[pair[0]].indexOf(name) === -1;
					var key = pair[0] + ':' + name;
					batch.storage[key] = { n: name, t: pair[0], new: own || !!(batch.storage[key] && batch.storage[key].new) };
				});
			});
			result.resources.forEach(function (resource) {
				if (/^https?:/.test(resource)) {
					batch.resources[resource.split('#')[0].slice(0, 500)] = true;
				}
			});
		}

		// Three pages at a time; what was found is reported every three pages, so nothing is lost.
		var reporting = Promise.resolve();
		function worker() {
			if (next >= urls.length) {
				return Promise.resolve();
			}
			var url = urls[next++];
			return scanPage(url).then(function (result) {
				opened++;
				ui.say(A.scanPage.replace('%1$d', opened).replace('%2$d', total));
				collect(url, result);
				if (batch.done.length >= 3) {
					reporting = reporting.then(function () {
						return report(false);
					});
				}
				return worker();
			});
		}

		serverSteps(data).then(function () {
			ui.say(A.scanPage.replace('%1$d', Math.min(opened + 1, total)).replace('%2$d', total));
			return Promise.all([worker(), worker(), worker()]);
		}).then(function () {
			return reporting;
		}).then(function () {
			ui.say(A.scanSaving);
			return report(true);
		}).then(function (result) {
			finished = true;
			stopGuard();
			if (onPage || /[?&]tab=cookies\b/.test(window.location.search)) {
				window.location.href = result.redirect;
			} else {
				ui.done(A.scanDone);
			}
		}, function (message) {
			ui.fail(message);
		});
	}

	/** Any plugin page: continue a scan whose page was left (unless another tab is working on it). */
	function resumeScan() {
		if (!A.scanResume || !window.Promise || !A.ajaxUrl) {
			return;
		}
		var $form = $('form[data-sccm-browser-scan]').first();
		var $anchor = $form.length ? $form : $('.sccm-admin .nav-tab-wrapper').first();
		if (!$anchor.length) {
			return;
		}
		var ui = null;
		(function ask() {
			$.post(A.ajaxUrl, { action: 'sccm_browser_scan_resume', _ajax_nonce: A.scanNonce }).done(function (response) {
				if (!response || !response.success || !response.data.active) {
					if (ui) {
						ui.done(A.scanDone);
					}
					return;
				}
				ui = ui || scanUi($anchor, $form.find('button'));
				if (response.data.busy) {
					// Another tab is scanning; take over if it stops reporting (closed, crashed).
					ui.say(A.scanBusy);
					wait(15000).then(ask);
					return;
				}
				ui.say(A.scanResuming);
				runScan(response.data, ui, false);
			});
		})();
	}

	$(resumeScan);
})(jQuery);
