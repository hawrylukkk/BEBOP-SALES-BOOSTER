(function($) {
	'use strict';

	function initWooSelects($scope) {
		if ($.fn.selectWoo) {
			$(document.body).trigger('wc-enhanced-select-init');
		}

		$scope.find('.bebop-sales-booster-category-select').each(function() {
			var $select = $(this);

			if ($.fn.selectWoo && !$select.data('select2')) {
				$select.selectWoo({
					width: '100%',
					placeholder: $select.attr('data-placeholder') || ''
				});
			}
		});
	}

	$(function() {
		initWooSelects($(document));

		$('.bebop-sales-booster-add-rule').on('click', function() {
			var template = document.getElementById('bebop-sales-booster-rule-template');
			var index = Date.now().toString();
			var html = template.innerHTML.replace(/__INDEX__/g, index);
			var $row = $(html);

			$('.bebop-sales-booster-rules__body').append($row);
			initWooSelects($row);
		});

		$(document).on('click', '.bebop-sales-booster-remove-rule', function() {
			$(this).closest('.bebop-sales-booster-rule').remove();
		});
	});
})(jQuery);
