(function ($) {
	'use strict';

	function showMessage(message, type) {
		var $box = $('#el_aas_ajax_message');
		$box.removeClass('notice-success notice-error notice-info notice-warning')
			.addClass('notice-' + type)
			.text(message)
			.show();
	}

	function request(action, extraData) {
		var data = $.extend({ action: action, nonce: elAas.nonce }, extraData || {});
		return $.post(elAas.ajaxUrl, data);
	}

	$(document).on('click', '#el_aas_manual_deploy', function (event) {
		event.preventDefault();

		if (!window.confirm(elAas.strings.confirmDeploy)) {
			return;
		}

		var $button = $(this);
		$button.prop('disabled', true);

		request('el_aas_manual_deploy')
			.done(function (response) {
				if (response && response.success && response.data && response.data.message) {
					showMessage(response.data.message, 'success');
				} else {
					showMessage(response && response.data && response.data.message ? response.data.message : elAas.strings.error, 'error');
				}
			})
			.fail(function () {
				showMessage(elAas.strings.error, 'error');
			})
			.always(function () {
				$button.prop('disabled', false);
			});
	});

	$(document).on('click', '#el_aas_generate_secret', function (event) {
		event.preventDefault();

		var $button = $(this);
		$button.prop('disabled', true);

		request('el_aas_generate_secret')
			.done(function (response) {
				if (response && response.success && response.data && response.data.secret) {
					$('#el_aas_webhook_secret').val(response.data.secret);
					showMessage('New secret generated. Save settings to apply it.', 'success');
				} else {
					showMessage(elAas.strings.error, 'error');
				}
			})
			.fail(function () {
				showMessage(elAas.strings.error, 'error');
			})
			.always(function () {
				$button.prop('disabled', false);
			});
	});

	$(document).on('click', '#el_aas_refresh_log', function (event) {
		event.preventDefault();

		var $button = $(this);
		$button.prop('disabled', true);

		request('el_aas_refresh_log')
			.done(function (response) {
				if (response && response.success && response.data) {
					$('#el_aas_log_output').val(response.data.log || '');
				} else {
					showMessage(elAas.strings.error, 'error');
				}
			})
			.fail(function () {
				showMessage(elAas.strings.error, 'error');
			})
			.always(function () {
				$button.prop('disabled', false);
			});
	});

	$(document).on('click', '#el_aas_clear_log', function (event) {
		event.preventDefault();

		if (!window.confirm(elAas.strings.confirmClear)) {
			return;
		}

		var $button = $(this);
		$button.prop('disabled', true);

		request('el_aas_clear_log')
			.done(function (response) {
				if (response && response.success) {
					$('#el_aas_log_output').val('');
					showMessage(elAas.strings.cleared, 'success');
				} else {
					showMessage(response && response.data && response.data.message ? response.data.message : elAas.strings.error, 'error');
				}
			})
			.fail(function () {
				showMessage(elAas.strings.error, 'error');
			})
			.always(function () {
				$button.prop('disabled', false);
			});
	});

	$(document).on('click', '#el_aas_copy_webhook', function (event) {
		event.preventDefault();

		var text = $('#el_aas_webhook_url').text();

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text)
				.then(function () {
					showMessage(elAas.strings.copied, 'success');
				})
				.catch(function () {
					showMessage(elAas.strings.copyFailed, 'error');
				});
			return;
		}

		showMessage(elAas.strings.copyFailed, 'error');
	});
$(document).on('click', '#el_aas_check_self_update', function (event) {
	event.preventDefault();

	var $button = $(this);
	$button.prop('disabled', true);
	showMessage(elAas.strings.checking, 'info');

	request('el_aas_check_self_update')
		.done(function (response) {
			if (response && response.success && response.data) {
				if (response.data.latest) {
					$('#el_aas_self_latest').text(response.data.latest);
				}
				showMessage(response.data.message || elAas.strings.checked, 'success');
			} else {
				showMessage(response && response.data && response.data.message ? response.data.message : elAas.strings.error, 'error');
			}
		})
		.fail(function () {
			showMessage(elAas.strings.error, 'error');
		})
		.always(function () {
			$button.prop('disabled', false);
		});
});
})(jQuery);
