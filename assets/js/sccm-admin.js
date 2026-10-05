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

		// "Remember the choice for": the preset fills the number of days, and typing days picks the matching preset.
		var $preset = $('[data-sccm-expiry-preset]');
		var $days = $('#sccm-consent_expiry_days');
		$preset.on('change', function () {
			if (this.value === 'custom') {
				$days.trigger('focus').trigger('select');
			} else {
				$days.val(this.value);
			}
		});
		$days.on('input', function () {
			var value = String(parseInt(this.value, 10));
			$preset.val($preset.find('option[value="' + value + '"]').length ? value : 'custom');
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
})(jQuery);
