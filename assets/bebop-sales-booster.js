(function($) {
	'use strict';

	function setButtonState($button, state, text) {
		if ($button.hasClass('bebop-sales-booster__add--icon')) {
			$button.prop('disabled', 'ready' !== state).toggleClass('is-loading', 'loading' === state).toggleClass('is-added', 'added' === state).html('<span aria-hidden="true">+</span>');
			return;
		}

		if ('loading' === state) {
			$button.prop('disabled', true).addClass('is-loading').text(text || bebopSalesBooster.addingText);
			return;
		}

		if ('added' === state) {
			$button.prop('disabled', true).removeClass('is-loading').addClass('is-added').text(text || bebopSalesBooster.addedText);
			return;
		}

		$button.prop('disabled', false).removeClass('is-loading is-added').text(text || 'Dorzuć');
	}

	function showNotice($area, message, isError) {
		var $notice = $area.find('.bebop-sales-booster__notice');

		if (!$area.length) {
			return;
		}

		if (!$notice.length) {
			$notice = $('<div class="bebop-sales-booster__notice" role="status" />').prependTo($area);
		}

		$notice.toggleClass('is-error', !!isError).text(message);
	}

	function replaceDeliveryBars(bars) {
		if (!bars) {
			return;
		}

		$.each(bars, function(position, html) {
			var $current = $('.bebop-delivery-bar[data-delivery-bar="' + position + '"]');

			if (!html) {
				$current.remove();
				return;
			}

			if ($current.length) {
				$current.replaceWith(html);
			}
		});

		updateStickyBarState();
	}

	function updateStickyBarState() {
		$('body').toggleClass('bebop-sales-booster-has-sticky-bar', $('.bebop-delivery-bar--sticky').length > 0);
	}

	$(document).on('click', '.bebop-sales-booster__add', function() {
		var $button = $(this);
		var $area = $button.closest('.bebop-sales-booster, .bebop-delivery-bar');
		var originalText = $button.text();

		setButtonState($button, 'loading');

		$.ajax({
			url: bebopSalesBooster.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: bebopSalesBooster.action,
				nonce: bebopSalesBooster.nonce,
				product_id: $button.data('product-id'),
				rule_id: $button.data('rule-id'),
				placement: $button.data('placement')
			}
		}).done(function(response) {
			if (!response || !response.success) {
				var fallback = response && response.data && response.data.message ? response.data.message : bebopSalesBooster.errorText;
				setButtonState($button, 'ready', originalText);
				showNotice($area, fallback, true);
				return;
			}

			setButtonState($button, 'added', response.data.message);
			showNotice($area, response.data.message, false);
			replaceDeliveryBars(response.data.delivery_bars);

			$(document.body).trigger('wc_fragment_refresh');
			$(document.body).trigger('update_checkout');
			$(document.body).trigger('updated_cart_totals');

			if (bebopSalesBooster.isCart) {
				window.setTimeout(function() {
					window.location.reload();
				}, 450);
			}
		}).fail(function(xhr) {
			var message = bebopSalesBooster.errorText;

			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}

			setButtonState($button, 'ready', originalText);
			showNotice($area, message, true);
		});
	});

	$(updateStickyBarState);
	$(document.body).on('wc_fragments_refreshed wc_fragments_loaded updated_wc_div updated_cart_totals', updateStickyBarState);
})(jQuery);
