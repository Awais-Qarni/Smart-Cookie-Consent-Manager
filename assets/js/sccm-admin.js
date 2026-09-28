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
