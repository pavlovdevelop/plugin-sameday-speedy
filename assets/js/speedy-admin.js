(function ($) {
	'use strict';

	if (typeof speedyAdmin === 'undefined') {
		return;
	}

	var i18n = speedyAdmin.i18n || {};

	function setStatus($node, message, isError) {
		if (!$node || !$node.length) {
			return;
		}
		$node.text(message || '').css('color', isError ? '#b32d2e' : '#2e7d32');
	}

	function gatherFormData($box) {
		var data = {
			action: 'speedy_create_shipment',
			nonce: speedyAdmin.nonce,
			order_id: $box.data('order-id'),
			parcel_rows: {}
		};

		$box.find('input[name], select[name], textarea[name]').each(function () {
			var $el = $(this);
			var name = $el.attr('name');
			var value;

			if ($el.is(':checkbox')) {
				value = $el.is(':checked') ? $el.val() : '';
			} else {
				value = $el.val();
			}

			if (name.indexOf('parcel_rows[') === 0) {
				var match = name.match(/^parcel_rows\[(\d+)\]\[(\w+)\]$/);
				if (match) {
					var idx = match[1];
					var key = match[2];
					if (!data.parcel_rows[idx]) {
						data.parcel_rows[idx] = {};
					}
					data.parcel_rows[idx][key] = value;
				}
				return;
			}

			data[name] = value;
		});

		return data;
	}

	function rebuildParcelRows($box, count, defaults) {
		var $tbody = $box.find('#speedy-parcels-table tbody');
		if (!$tbody.length) {
			return;
		}

		var existingRows = [];
		$tbody.find('tr').each(function () {
			var $tr = $(this);
			existingRows.push({
				weight: $tr.find('input[name$="[weight]"]').val(),
				length: $tr.find('input[name$="[length]"]').val(),
				width: $tr.find('input[name$="[width]"]').val(),
				height: $tr.find('input[name$="[height]"]').val()
			});
		});

		$tbody.empty();

		for (var i = 0; i < count; i++) {
			var row = existingRows[i] || {};
			var $tr = $('<tr>');
			$tr.append($('<td>').text(i + 1));
			$tr.append($('<td>').append(
				$('<input>').attr({
					type: 'number', step: '0.01', min: '0',
					name: 'parcel_rows[' + i + '][weight]'
				}).val(row.weight !== undefined ? row.weight : (defaults.weight || ''))
			));
			$tr.append($('<td>').append(
				$('<input>').attr({
					type: 'number', min: '0',
					name: 'parcel_rows[' + i + '][length]'
				}).val(row.length !== undefined ? row.length : (defaults.length || ''))
			));
			$tr.append($('<td>').append(
				$('<input>').attr({
					type: 'number', min: '0',
					name: 'parcel_rows[' + i + '][width]'
				}).val(row.width !== undefined ? row.width : (defaults.width || ''))
			));
			$tr.append($('<td>').append(
				$('<input>').attr({
					type: 'number', min: '0',
					name: 'parcel_rows[' + i + '][height]'
				}).val(row.height !== undefined ? row.height : (defaults.height || ''))
			));
			$tbody.append($tr);
		}
	}

	function repopulateServiceSelects(services) {
		$('select.speedy-service-select').each(function () {
			var $select = $(this);
			var current = String($select.data('current') || $select.val() || '');
			var placeholder = i18n.choosePlaceholder || '— Изберете услуга —';

			$select.empty();
			$select.append($('<option>').val('').text(placeholder));

			var matched = false;

			$.each(services, function (_, service) {
				var id = String(service.id);
				var $opt = $('<option>').val(id).text('#' + id + ' — ' + service.name);

				if (id === current) {
					$opt.prop('selected', true);
					matched = true;
				}

				$select.append($opt);
			});

			if (!matched && current !== '') {
				$select.append($('<option>').val(current).text('#' + current + ' (запазен)').prop('selected', true));
			}

			$select.data('current', current);
		});
	}

	function repopulateOfficeSelects(offices) {
		$('select.speedy-office-select').each(function () {
			var $select = $(this);
			var current = String($select.data('current') || $select.val() || '');

			$select.empty();
			$select.append($('<option>').val('').text('— Изберете офис —'));

			var matched = false;

			$.each(offices, function (_, office) {
				var id = String(office.id);
				var $opt = $('<option>').val(id).text(office.label);

				if (id === current) {
					$opt.prop('selected', true);
					matched = true;
				}

				$select.append($opt);
			});

			if (!matched && current !== '') {
				$select.append($('<option>').val(current).text('#' + current + ' (запазен)').prop('selected', true));
			}

			$select.data('current', current);
		});
	}

	$(function () {
		// --- Settings page ---

		$('#speedy-test-connection').on('click', function () {
			var $btn = $(this);
			var $res = $('#speedy-test-result');

			$btn.prop('disabled', true);
			setStatus($res, i18n.testing, false);

			$.post(speedyAdmin.ajaxUrl, {
				action: 'speedy_test_connection',
				nonce: speedyAdmin.nonce
			}).done(function (resp) {
				if (resp && resp.success) {
					setStatus($res, (resp.data && resp.data.message) || i18n.connectionOk, false);
				} else {
					setStatus($res, (resp && resp.data && resp.data.message) || i18n.connectionFail, true);
				}
			}).fail(function () {
				setStatus($res, i18n.connectionFail, true);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$('#speedy-load-services').on('click', function () {
			var $btn = $(this);
			var $res = $('#speedy-services-status');

			$btn.prop('disabled', true);
			setStatus($res, i18n.loadingServices, false);

			$.post(speedyAdmin.ajaxUrl, {
				action: 'speedy_load_services',
				nonce: speedyAdmin.nonce
			}).done(function (resp) {
				if (resp && resp.success) {
					var services = (resp.data && resp.data.services) || [];

					if (services.length === 0) {
						setStatus($res, i18n.servicesEmpty, true);
					} else {
						repopulateServiceSelects(services);
						setStatus($res, (resp.data && resp.data.message) || i18n.servicesLoaded, false);
					}
				} else {
					setStatus($res, (resp && resp.data && resp.data.message) || i18n.connectionFail, true);
				}
			}).fail(function () {
				setStatus($res, i18n.connectionFail, true);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$('#speedy-load-offices').on('click', function () {
			var $btn = $(this);
			var $res = $('#speedy-offices-status');

			$btn.prop('disabled', true);
			setStatus($res, i18n.loadingOffices, false);

			$.post(speedyAdmin.ajaxUrl, {
				action: 'speedy_load_offices',
				nonce: speedyAdmin.nonce
			}).done(function (resp) {
				if (resp && resp.success) {
					var offices = (resp.data && resp.data.offices) || [];

					if (offices.length === 0) {
						setStatus($res, i18n.officesEmpty, true);
					} else {
						repopulateOfficeSelects(offices);
						setStatus($res, (resp.data && resp.data.message) || i18n.officesLoaded, false);
					}
				} else {
					setStatus($res, (resp && resp.data && resp.data.message) || i18n.connectionFail, true);
				}
			}).fail(function () {
				setStatus($res, i18n.connectionFail, true);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		// --- Order meta box ---

		var $box = $('#speedy-shipment-box');

		if (!$box.length) {
			return;
		}

		var $status = $box.find('#speedy-mb-status');
		var orderId = $box.data('order-id');

		// Toggle sender rows by selected sender type
		function applySenderType() {
			var type = $box.find('select[name="sender_type"]').val();
			$box.find('.speedy-row-sender-address').toggle(type !== 'office');
			$box.find('.speedy-row-sender-office').toggle(type === 'office');
		}

		$box.on('change', 'select[name="sender_type"]', applySenderType);
		applySenderType();

		// Rebuild parcel rows when count changes
		$box.on('change', '#speedy-mb-parcels', function () {
			var count = Math.max(1, parseInt($(this).val(), 10) || 1);
			rebuildParcelRows($box, count, {});
		});

		$('#speedy-create-shipment').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			setStatus($status, i18n.creating, false);

			$.post(speedyAdmin.ajaxUrl, gatherFormData($box))
				.done(function (resp) {
					if (resp && resp.success) {
						setStatus($status, (resp.data && resp.data.message) || '', false);
						window.location.reload();
					} else {
						setStatus($status, (resp && resp.data && resp.data.message) || i18n.genericError, true);
						$btn.prop('disabled', false);
					}
				})
				.fail(function () {
					setStatus($status, i18n.genericError, true);
					$btn.prop('disabled', false);
				});
		});

		$('#speedy-cancel-shipment').on('click', function () {
			if (!window.confirm(i18n.confirmCancel || 'Сигурни ли сте?')) {
				return;
			}

			var reason = window.prompt(i18n.cancelReason || 'Причина:', 'Отказана от търговеца');

			if (reason === null) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);
			setStatus($status, i18n.cancelling, false);

			$.post(speedyAdmin.ajaxUrl, {
				action: 'speedy_cancel_shipment',
				nonce: speedyAdmin.nonce,
				order_id: orderId,
				comment: reason
			}).done(function (resp) {
				if (resp && resp.success) {
					setStatus($status, (resp.data && resp.data.message) || '', false);
					window.location.reload();
				} else {
					setStatus($status, (resp && resp.data && resp.data.message) || i18n.genericError, true);
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				setStatus($status, i18n.genericError, true);
				$btn.prop('disabled', false);
			});
		});

		$('#speedy-request-pickup').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			setStatus($status, i18n.requestingPickup, false);

			$.post(speedyAdmin.ajaxUrl, {
				action: 'speedy_request_pickup',
				nonce: speedyAdmin.nonce,
				order_id: orderId,
				date: $('#speedy-pickup-date').val(),
				hour: $('#speedy-pickup-hour').val()
			}).done(function (resp) {
				if (resp && resp.success) {
					setStatus($status, (resp.data && resp.data.message) || i18n.pickupOk, false);
				} else {
					setStatus($status, (resp && resp.data && resp.data.message) || i18n.genericError, true);
				}
			}).fail(function () {
				setStatus($status, i18n.genericError, true);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
