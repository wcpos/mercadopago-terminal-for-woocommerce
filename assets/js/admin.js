(function ($) {
	'use strict';

	$(function () {
		$('.mptfwc-set-pdv').on('click', function () {
			var button = $(this);
			button.prop('disabled', true);
			button.next('.mptfwc-pdv-error').remove();
			$.post(mptfwcAdminData.ajaxUrl, {
				action: 'mptfwc_set_pdv_mode',
				terminal_id: button.attr('data-terminal-id'),
				nonce: button.closest('[data-nonce]').attr('data-nonce')
			}).done(function (response) {
				if (response.success) {
					button.replaceWith(document.createTextNode(response.data.message));
				} else {
					button.prop('disabled', false);
					$('<span class="mptfwc-pdv-error"></span>').text(response.data).insertAfter(button);
				}
			}).fail(function (xhr, textStatus, errorThrown) {
				button.prop('disabled', false);
				$('<span class="mptfwc-pdv-error"></span>')
					.text(xhr.responseJSON ? xhr.responseJSON.data : errorThrown || textStatus).insertAfter(button);
			});
		});
	});
}(jQuery));
