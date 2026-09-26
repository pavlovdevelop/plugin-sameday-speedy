/* Sameday WooCommerce BG Frontend JavaScript */

jQuery(function($) {
	var checkoutConfig = typeof samedayWooCommerceBg !== 'undefined' ? samedayWooCommerceBg : null;
	var updateTimer = null;
	var easyboxLocationsRequest = null;
	var easyboxCitiesLoaded = false;
	var easyboxLocationsMap = {};
	var speedyCitiesLoaded = false;
	var speedyLocationsRequest = null;
	var speedyLocationsMap = {};

	if (!checkoutConfig) {
		return;
	}

	function getSelectedProvider() {
		return $('input[name="sameday_shipping_provider"]:checked').val() || '';
	}

	function getSelectedService() {
		return $('input[name="sameday_shipping_service"]:checked').val() || '';
	}

	function isEasyboxService() {
		return getSelectedService() === 'sameday_easybox';
	}

	function isSpeedyLocationService(serviceCode) {
		serviceCode = serviceCode || getSelectedService();

		return serviceCode === 'speedy_office' || serviceCode === 'speedy_aps';
	}

	function getCartSubtotal() {
		return Number(checkoutConfig.cartSubtotal || $('#sameday_cart_subtotal').val() || 0);
	}

	function getEasyboxCity() {
		return $('#sameday_easybox_city').val() || '';
	}

	function getEasyboxLocationId() {
		return $('#sameday_easybox_location').val() || '';
	}

	function getSamedayDoorCity() {
		return $.trim($('#sameday_door_city').val() || '');
	}

	function getSamedayDoorAddress() {
		return $.trim($('#sameday_door_address').val() || '');
	}

	function getSpeedyCityId() {
		return $('#sameday_speedy_city').val() || '';
	}

	function getSpeedyLocationId() {
		return $('#sameday_speedy_location').val() || '';
	}

	function getSpeedyDoorCity() {
		return $.trim($('#speedy_door_city').val() || '');
	}

	function getSpeedyDoorAddress() {
		return $.trim($('#speedy_door_address').val() || '');
	}

	function getSpeedyLocationType(serviceCode) {
		serviceCode = serviceCode || getSelectedService();

		if (serviceCode === 'speedy_aps') {
			return 'aps';
		}

		if (serviceCode === 'speedy_office') {
			return 'office';
		}

		return 'all';
	}

	function getServiceDefinition(serviceCode) {
		var servicesByProvider = checkoutConfig.config && checkoutConfig.config.services ? checkoutConfig.config.services : {};
		var providerKey;
		var providerServices;

		for (providerKey in servicesByProvider) {
			if (!Object.prototype.hasOwnProperty.call(servicesByProvider, providerKey)) {
				continue;
			}

			providerServices = servicesByProvider[providerKey];

			if (providerServices && providerServices[serviceCode]) {
				return providerServices[serviceCode];
			}
		}

		return null;
	}

	function getFreeShippingThreshold(serviceCode) {
		var definition = getServiceDefinition(serviceCode);
		var thresholds = checkoutConfig.config && checkoutConfig.config.freeShippingThresholds ? checkoutConfig.config.freeShippingThresholds : {};

		if (!definition || !definition.provider) {
			return 0;
		}

		return Number(thresholds[definition.provider] || 0);
	}

	function isFreeShippingService(serviceCode) {
		return serviceCode === 'sameday_easybox' || serviceCode === 'speedy_office' || serviceCode === 'speedy_aps';
	}

	function hasFreeShipping(serviceCode) {
		var threshold = getFreeShippingThreshold(serviceCode);

		return isFreeShippingService(serviceCode) && threshold > 0 && getCartSubtotal() >= threshold;
	}

	function isServiceForProvider(serviceCode, provider) {
		var definition = getServiceDefinition(serviceCode);

		return !!(definition && definition.provider === provider);
	}

	function formatPrice(value) {
		return Number(value).toFixed(2).replace('.', ',') + ' ' + checkoutConfig.strings.currency;
	}

	function convertBgnToEur(valueBgn) {
		var rate = checkoutConfig.config && checkoutConfig.config.conversionRate ? Number(checkoutConfig.config.conversionRate) : 1.95583;

		return Number(valueBgn) / rate;
	}

	function calculateSamedayPrice(serviceCode, weight) {
		var pricing = checkoutConfig.config.pricing.sameday;
		var includedWeight = Number(pricing.includedWeight || 3);
		var extraWeight;
		var price;

		if (serviceCode === 'sameday_easybox') {
			price = Number(pricing.easybox.base);

			if (weight > includedWeight) {
				extraWeight = weight - includedWeight;
				price += extraWeight * Number(pricing.easybox.additional);
			}

			return convertBgnToEur(price);
		}

		price = Number(pricing.door.base);

		if (weight > includedWeight) {
			extraWeight = weight - includedWeight;
			price += extraWeight * Number(pricing.door.additional);
		}

		return convertBgnToEur(price);
	}

	function calculateSpeedyPrice(serviceCode, weight) {
		var speedyPricing = checkoutConfig.config.pricing.speedy;
		var bands = speedyPricing.bands || [];
		var band;
		var i;
		var baseCost;
		var pickupCost;
		var deliveryCost;
		var price;

		for (i = 0; i < bands.length; i += 1) {
			if (weight <= Number(bands[i].max)) {
				band = bands[i];
				break;
			}
		}

		if (!band) {
			baseCost = Number(speedyPricing.over20.base) + Math.max(0, weight - Number(speedyPricing.over20.threshold)) * Number(speedyPricing.over20.extraKg);
			pickupCost = Number(speedyPricing.over20.pickup);
			deliveryCost = Number(speedyPricing.over20.delivery);
		} else {
			baseCost = Number(band.base);
			pickupCost = Number(band.pickup);
			deliveryCost = Number(band.delivery);
		}

		price = baseCost + pickupCost;

		if (serviceCode === 'speedy_door') {
			price += deliveryCost;
		}

		return convertBgnToEur(price);
	}

	function calculatePrice(serviceCode) {
		var weight = Number(checkoutConfig.cartWeight || $('#sameday_cart_weight').val() || 0);

		if (!serviceCode) {
			return 0;
		}

		if (hasFreeShipping(serviceCode)) {
			return 0;
		}

		if (serviceCode.indexOf('sameday_') === 0) {
			return calculateSamedayPrice(serviceCode, weight);
		}

		return calculateSpeedyPrice(serviceCode, weight);
	}

	function setEasyboxMeta(text) {
		$('#sameday_easybox_selected_meta').text(text || '');
	}

	function setSpeedyMeta(text) {
		$('#sameday_speedy_selected_meta').text(text || '');
	}

	function escapeHtml(value) {
		return $('<div/>').text(value).html();
	}

	function destroyEnhancedSelect($select) {
		if (!$select.length || !$select.hasClass('select2-hidden-accessible')) {
			return;
		}

		if ($.fn.selectWoo) {
			try {
				$select.selectWoo('destroy');
				return;
			} catch (error) {}
		}

		if ($.fn.select2) {
			try {
				$select.select2('destroy');
			} catch (error) {}
		}
	}

	function enhanceSelect($select, placeholder) {
		var config = {
			width: '100%',
			placeholder: placeholder,
			allowClear: true
		};

		if (!$select.length) {
			return;
		}

		destroyEnhancedSelect($select);

		if ($.fn.selectWoo) {
			$select.selectWoo(config);
			return;
		}

		if ($.fn.select2) {
			$select.select2(config);
		}
	}

	function enhanceEasyboxCitySelect() {
		enhanceSelect($('#sameday_easybox_city'), checkoutConfig.strings.easyboxChooseCity);
	}

	function enhanceEasyboxLocationSelect() {
		enhanceSelect($('#sameday_easybox_location'), checkoutConfig.strings.easyboxChooseLocation);
	}

	function enhanceSpeedyCitySelect() {
		enhanceSelect($('#sameday_speedy_city'), checkoutConfig.strings.speedyChooseCity);
	}

	function enhanceSpeedyLocationSelect() {
		enhanceSelect($('#sameday_speedy_location'), getSpeedyLocationPlaceholder());
	}

	function getSpeedyLocationPlaceholder(serviceCode) {
		serviceCode = serviceCode || getSelectedService();

		return serviceCode === 'speedy_aps' ? checkoutConfig.strings.speedyChooseAps : checkoutConfig.strings.speedyChooseOffice;
	}

	function getSpeedyLocationLabel(serviceCode) {
		serviceCode = serviceCode || getSelectedService();

		return serviceCode === 'speedy_aps' ? checkoutConfig.strings.speedyApsLabel : checkoutConfig.strings.speedyOfficeLabel;
	}

	function renderEasyboxCityOptions(cities) {
		var $citySelect = $('#sameday_easybox_city');
		var selectedCity = $citySelect.attr('data-selected') || $citySelect.val() || '';
		var options = ['<option value="">' + checkoutConfig.strings.easyboxChooseCity + '</option>'];

		$.each(cities, function(index, city) {
			var label = city.name + ' (' + city.count + ')';
			options.push('<option value="' + escapeHtml(city.name) + '">' + escapeHtml(label) + '</option>');
		});

		$citySelect.html(options.join(''));

		if (selectedCity) {
			$citySelect.val(selectedCity);
		}

		$citySelect.prop('disabled', false);
		enhanceEasyboxCitySelect();
	}

	function setEasyboxLocationsPlaceholder(text) {
		var $locationSelect = $('#sameday_easybox_location');

		$locationSelect.html('<option value="">' + escapeHtml(text) + '</option>');
		$locationSelect.prop('disabled', false);
		easyboxLocationsMap = {};
		enhanceEasyboxLocationSelect();
		setEasyboxMeta('');
	}

	function renderEasyboxLocationOptions(locations) {
		var $locationSelect = $('#sameday_easybox_location');
		var selectedLocation = String($locationSelect.attr('data-selected') || $locationSelect.val() || '');
		var options = ['<option value="">' + checkoutConfig.strings.easyboxChooseLocation + '</option>'];

		easyboxLocationsMap = {};

		$.each(locations, function(index, location) {
			var optionLabel = location.name + ' - ' + location.address;

			easyboxLocationsMap[String(location.id)] = location;
			options.push('<option value="' + escapeHtml(String(location.id)) + '">' + escapeHtml(optionLabel) + '</option>');
		});

		$locationSelect.html(options.join(''));

		if (selectedLocation && easyboxLocationsMap[selectedLocation]) {
			$locationSelect.val(selectedLocation);
		}

		$locationSelect.prop('disabled', false);
		enhanceEasyboxLocationSelect();
		updateEasyboxSelectionMeta();
		updateSummary();
	}

	function renderSpeedyCityOptions(cities) {
		var $citySelect = $('#sameday_speedy_city');
		var selectedCityId = String($citySelect.attr('data-selected') || $citySelect.val() || '');
		var options = ['<option value="">' + checkoutConfig.strings.speedyChooseCity + '</option>'];

		$.each(cities, function(index, city) {
			options.push('<option value="' + escapeHtml(String(city.id)) + '">' + escapeHtml(city.label) + '</option>');
		});

		$citySelect.html(options.join(''));

		if (selectedCityId) {
			$citySelect.val(selectedCityId);
		}

		$citySelect.prop('disabled', false);
		enhanceSpeedyCitySelect();
	}

	function setSpeedyLocationsPlaceholder(text) {
		var $locationSelect = $('#sameday_speedy_location');

		$locationSelect.html('<option value="">' + escapeHtml(text) + '</option>');
		$locationSelect.prop('disabled', false);
		speedyLocationsMap = {};
		enhanceSpeedyLocationSelect();
		setSpeedyMeta('');
	}

	function renderSpeedyLocationOptions(locations) {
		var $locationSelect = $('#sameday_speedy_location');
		var selectedLocation = String($locationSelect.attr('data-selected') || $locationSelect.val() || '');
		var options = ['<option value="">' + getSpeedyLocationPlaceholder() + '</option>'];

		speedyLocationsMap = {};

		$.each(locations, function(index, location) {
			speedyLocationsMap[String(location.id)] = location;
			options.push('<option value="' + escapeHtml(String(location.id)) + '">' + escapeHtml(location.label) + '</option>');
		});

		$locationSelect.html(options.join(''));

		if (selectedLocation && speedyLocationsMap[selectedLocation]) {
			$locationSelect.val(selectedLocation);
		}

		$locationSelect.prop('disabled', false);
		enhanceSpeedyLocationSelect();
		updateSpeedySelectionMeta();
		updateSummary();
	}

	function updateEasyboxSelectionMeta() {
		var locationId = String(getEasyboxLocationId());
		var location = easyboxLocationsMap[locationId];
		var fallbackLabel;

		if (!location) {
			fallbackLabel = $('#sameday_easybox_location option:selected').text() || '';

			if (locationId && fallbackLabel && fallbackLabel !== checkoutConfig.strings.easyboxChooseLocation) {
				setEasyboxMeta(fallbackLabel);
				return;
			}

			setEasyboxMeta('');
			return;
		}

		setEasyboxMeta(location.details || location.label || '');
	}

	function updateSpeedySelectionMeta() {
		var locationId = String(getSpeedyLocationId());
		var location = speedyLocationsMap[locationId];
		var fallbackLabel;

		if (!location) {
			fallbackLabel = $('#sameday_speedy_location option:selected').text() || '';

			if (locationId && fallbackLabel && fallbackLabel !== getSpeedyLocationPlaceholder()) {
				setSpeedyMeta(fallbackLabel);
				return;
			}

			setSpeedyMeta('');
			return;
		}

		setSpeedyMeta(location.details || location.label || '');
	}

	function ensureEasyboxCitiesLoaded() {
		var $citySelect = $('#sameday_easybox_city');

		if (easyboxCitiesLoaded || !$citySelect.length) {
			return;
		}

		$citySelect.prop('disabled', true).html('<option value="">' + checkoutConfig.strings.easyboxLoadingCities + '</option>');

		$.ajax({
			url: checkoutConfig.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'get_easybox_cities',
				security: checkoutConfig.nonce
			}
		}).done(function(response) {
			var meta;

			if (!response || !response.success || !response.data || !response.data.cities) {
				setEasyboxLocationsPlaceholder(checkoutConfig.strings.easyboxLoadError);
				return;
			}

			meta = response.data.meta || {};

			if (!response.data.cities.length && meta.last_error) {
				$citySelect.prop('disabled', false).html('<option value="">' + escapeHtml(checkoutConfig.strings.easyboxLoadError) + '</option>');
				setEasyboxLocationsPlaceholder(meta.last_error);
				return;
			}

			easyboxCitiesLoaded = true;
			renderEasyboxCityOptions(response.data.cities);
			fetchEasyboxLocations();
		}).fail(function() {
			$citySelect.prop('disabled', false).html('<option value="">' + checkoutConfig.strings.easyboxLoadError + '</option>');
			setEasyboxLocationsPlaceholder(checkoutConfig.strings.easyboxLoadError);
		});
	}

	function ensureSpeedyCitiesLoaded() {
		var $citySelect = $('#sameday_speedy_city');

		if (speedyCitiesLoaded || !$citySelect.length) {
			return;
		}

		$citySelect.prop('disabled', true).html('<option value="">' + checkoutConfig.strings.speedyLoadingCities + '</option>');

		$.ajax({
			url: checkoutConfig.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'get_speedy_cities',
				security: checkoutConfig.nonce
			}
		}).done(function(response) {
			var meta;

			if (!response || !response.success || !response.data || !response.data.cities) {
				setSpeedyLocationsPlaceholder(checkoutConfig.strings.speedyLoadError);
				return;
			}

			meta = response.data.meta || {};

			if (!response.data.cities.length && meta.last_error) {
				$citySelect.prop('disabled', false).html('<option value="">' + escapeHtml(checkoutConfig.strings.speedyLoadError) + '</option>');
				setSpeedyLocationsPlaceholder(meta.last_error);
				return;
			}

			speedyCitiesLoaded = true;
			renderSpeedyCityOptions(response.data.cities);
			fetchSpeedyLocations();
		}).fail(function() {
			$citySelect.prop('disabled', false).html('<option value="">' + checkoutConfig.strings.speedyLoadError + '</option>');
			setSpeedyLocationsPlaceholder(checkoutConfig.strings.speedyLoadError);
		});
	}

	function fetchEasyboxLocations() {
		var city = getEasyboxCity();
		var $locationSelect = $('#sameday_easybox_location');

		if (!isEasyboxService() || !$locationSelect.length) {
			return;
		}

		if (!city) {
			setEasyboxLocationsPlaceholder(checkoutConfig.strings.easyboxChooseCityFirst);
			return;
		}

		if (easyboxLocationsRequest && easyboxLocationsRequest.readyState !== 4) {
			easyboxLocationsRequest.abort();
		}

		$locationSelect.prop('disabled', true).html('<option value="">' + checkoutConfig.strings.easyboxLoadingLocations + '</option>');

		easyboxLocationsRequest = $.ajax({
			url: checkoutConfig.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'get_easybox_locations',
				security: checkoutConfig.nonce,
				city: city
			}
		}).done(function(response) {
			var message;

			if (!response || !response.success || !response.data) {
				setEasyboxLocationsPlaceholder(checkoutConfig.strings.easyboxLoadError);
				return;
			}

			if (!response.data.locations || !response.data.locations.length) {
				message = response.data.message || checkoutConfig.strings.easyboxNoResults;
				setEasyboxLocationsPlaceholder(message);
				return;
			}

			renderEasyboxLocationOptions(response.data.locations);
		}).fail(function(xhr, status) {
			if (status !== 'abort') {
				setEasyboxLocationsPlaceholder(checkoutConfig.strings.easyboxLoadError);
			}
		});
	}

	function fetchSpeedyLocations() {
		var cityId = getSpeedyCityId();
		var type = getSpeedyLocationType();
		var $locationSelect = $('#sameday_speedy_location');
		var labelText = getSpeedyLocationLabel();

		$('#sameday-speedy-location-label .sameday-speedy-label-text').text(labelText);
		$('#sameday_speedy_help').text(checkoutConfig.strings.speedyTypeHelp);

		if (!isSpeedyLocationService() || !$locationSelect.length) {
			return;
		}

		if (!cityId) {
			setSpeedyLocationsPlaceholder(checkoutConfig.strings.speedyChooseCityFirst);
			return;
		}

		if (speedyLocationsRequest && speedyLocationsRequest.readyState !== 4) {
			speedyLocationsRequest.abort();
		}

		$locationSelect.prop('disabled', true).html('<option value="">' + checkoutConfig.strings.speedyLoadingLocations + '</option>');

		speedyLocationsRequest = $.ajax({
			url: checkoutConfig.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'get_speedy_locations',
				security: checkoutConfig.nonce,
				city_id: cityId,
				type: type
			}
		}).done(function(response) {
			var message;

			if (!response || !response.success || !response.data) {
				setSpeedyLocationsPlaceholder(checkoutConfig.strings.speedyLoadError);
				return;
			}

			if (!response.data.locations || !response.data.locations.length) {
				message = response.data.message || checkoutConfig.strings.speedyNoResults;
				setSpeedyLocationsPlaceholder(message);
				return;
			}

			renderSpeedyLocationOptions(response.data.locations);
		}).fail(function(xhr, status) {
			if (status !== 'abort') {
				setSpeedyLocationsPlaceholder(checkoutConfig.strings.speedyLoadError);
			}
		});
	}

	function isSelectionReady(serviceCode) {
		serviceCode = serviceCode || getSelectedService();

		if (!serviceCode) {
			return false;
		}

		switch (serviceCode) {
			case 'sameday_easybox':
				return !!getEasyboxLocationId();

			case 'sameday_door':
				return !!getSamedayDoorCity() && !!getSamedayDoorAddress();

			case 'speedy_office':
			case 'speedy_aps':
				return !!getSpeedyCityId() && !!getSpeedyLocationId();

			case 'speedy_door':
				return !!getSpeedyDoorCity() && !!getSpeedyDoorAddress();
		}

		return false;
	}

	function updateDetailsField() {
		var serviceCode = getSelectedService();
		var definition = getServiceDefinition(serviceCode);
		var $easyboxSelector = $('.sameday-easybox-selector');
		var $samedayDoorFields = $('.sameday-door-fields');
		var $speedyLocationSelector = $('.speedy-location-selector');
		var $speedyDoorFields = $('.speedy-door-fields');

		if (!definition) {
			$easyboxSelector.addClass('is-hidden');
			$samedayDoorFields.addClass('is-hidden');
			$speedyLocationSelector.addClass('is-hidden');
			$speedyDoorFields.addClass('is-hidden');
			return;
		}

		if (serviceCode === 'sameday_easybox') {
			$easyboxSelector.removeClass('is-hidden');
			$samedayDoorFields.addClass('is-hidden');
			$speedyLocationSelector.addClass('is-hidden');
			$speedyDoorFields.addClass('is-hidden');
			ensureEasyboxCitiesLoaded();
			enhanceEasyboxCitySelect();
			enhanceEasyboxLocationSelect();
			return;
		}

		if (serviceCode === 'sameday_door') {
			$easyboxSelector.addClass('is-hidden');
			$samedayDoorFields.removeClass('is-hidden');
			$speedyLocationSelector.addClass('is-hidden');
			$speedyDoorFields.addClass('is-hidden');
			return;
		}

		if (isSpeedyLocationService(serviceCode)) {
			$easyboxSelector.addClass('is-hidden');
			$samedayDoorFields.addClass('is-hidden');
			$speedyLocationSelector.removeClass('is-hidden');
			$speedyDoorFields.addClass('is-hidden');
			ensureSpeedyCitiesLoaded();
			enhanceSpeedyCitySelect();
			enhanceSpeedyLocationSelect();
			fetchSpeedyLocations();
			return;
		}

		if (serviceCode === 'speedy_door') {
			$easyboxSelector.addClass('is-hidden');
			$samedayDoorFields.addClass('is-hidden');
			$speedyLocationSelector.addClass('is-hidden');
			$speedyDoorFields.removeClass('is-hidden');
			return;
		}

		$easyboxSelector.addClass('is-hidden');
		$samedayDoorFields.addClass('is-hidden');
		$speedyLocationSelector.addClass('is-hidden');
		$speedyDoorFields.addClass('is-hidden');
	}

	function updateProviderVisibility() {
		var provider = getSelectedProvider();
		var currentService = getSelectedService();

		$('.sameday-service-group').removeClass('is-active');

		if (provider) {
			$('.sameday-service-group[data-provider="' + provider + '"]').addClass('is-active');
		}

		if (currentService && !isServiceForProvider(currentService, provider)) {
			$('input[name="sameday_shipping_service"]').prop('checked', false);
			$('#sameday_easybox_location').attr('data-selected', '').val('');
			$('#sameday_speedy_location').attr('data-selected', '').val('');
			setEasyboxMeta('');
			setSpeedyMeta('');
		}
	}

	function updateSummary() {
		var serviceCode = getSelectedService();
		var price = calculatePrice(serviceCode);
		var $summary = $('#sameday-delivery-summary');

		if (!serviceCode || !isSelectionReady(serviceCode)) {
			$summary.removeClass('has-price is-free-shipping').addClass('is-hidden').html('');
			return;
		}

		if (hasFreeShipping(serviceCode)) {
			$summary
				.removeClass('is-hidden has-price')
				.addClass('is-free-shipping')
				.html('<strong>' + checkoutConfig.strings.freeShipping + '</strong>');
			return;
		}

		$summary
			.removeClass('is-hidden is-free-shipping')
			.addClass('has-price')
			.html('<strong>' + checkoutConfig.strings.pricePrefix + ' ' + formatPrice(price) + '</strong>');
	}

	function updateCheckedState() {
		$('.sameday-choice-card, .sameday-choice-row').removeClass('is-selected');
		$('.sameday-choice-card input:checked, .sameday-choice-row input:checked').each(function() {
			$(this).closest('.sameday-choice-card, .sameday-choice-row').addClass('is-selected');
		});
	}

	function refreshDeliveryUi() {
		updateProviderVisibility();
		updateDetailsField();
		updateSummary();
		updateCheckedState();
		updateEasyboxSelectionMeta();
		updateSpeedySelectionMeta();
	}

	function triggerCheckoutUpdate() {
		clearTimeout(updateTimer);
		updateTimer = setTimeout(function() {
			$(document.body).trigger('update_checkout');
		}, 150);
	}

	$(document.body).on('change', 'input[name="sameday_shipping_provider"]', function() {
		refreshDeliveryUi();
		triggerCheckoutUpdate();
	});

	$(document.body).on('change', 'input[name="sameday_shipping_service"]', function() {
		if (getSelectedService() !== 'sameday_easybox') {
			$('#sameday_easybox_location').attr('data-selected', '').val('');
			setEasyboxMeta('');
		}

		if (!isSpeedyLocationService()) {
			$('#sameday_speedy_location').attr('data-selected', '').val('');
			setSpeedyMeta('');
		}

		refreshDeliveryUi();
		triggerCheckoutUpdate();
	});

	$(document.body).on('change', '#sameday_easybox_city', function() {
		$('#sameday_easybox_city').attr('data-selected', $(this).val());
		$('#sameday_easybox_location').attr('data-selected', '');
		fetchEasyboxLocations();
		updateSummary();
		triggerCheckoutUpdate();
	});

	$(document.body).on('change', '#sameday_easybox_location', function() {
		$('#sameday_easybox_location').attr('data-selected', $(this).val());
		updateEasyboxSelectionMeta();
		updateSummary();
		triggerCheckoutUpdate();
	});

	$(document.body).on('input', '#sameday_door_city, #sameday_door_address', function() {
		updateSummary();
		triggerCheckoutUpdate();
	});

	$(document.body).on('change', '#sameday_speedy_city', function() {
		$('#sameday_speedy_city').attr('data-selected', $(this).val());
		$('#sameday_speedy_location').attr('data-selected', '');
		fetchSpeedyLocations();
		updateSummary();
		triggerCheckoutUpdate();
	});

	$(document.body).on('change', '#sameday_speedy_location', function() {
		$('#sameday_speedy_location').attr('data-selected', $(this).val());
		updateSpeedySelectionMeta();
		updateSummary();
		triggerCheckoutUpdate();
	});

	$(document.body).on('input', '#speedy_door_city, #speedy_door_address', function() {
		updateSummary();
		triggerCheckoutUpdate();
	});

	$(document.body).on('updated_checkout', function() {
		refreshDeliveryUi();
	});

	refreshDeliveryUi();
});
