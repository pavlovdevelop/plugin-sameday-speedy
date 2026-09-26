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
	var deliveryFieldsRequest = null;
	var lastRenderedCountry = '';
	var lastPaymentMethod = '';
	var speedyQuotes = {};
	var speedyQuoteTimer = null;
	var speedyQuoteRequest = null;

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

	function getSelectedPaymentMethod() {
		return $('input[name="payment_method"]:checked').val() || '';
	}

	function getCardFreeShippingConfig() {
		return (checkoutConfig.config && checkoutConfig.config.cardFreeShipping) || {};
	}

	function isCardPaymentMethod(method) {
		var config = getCardFreeShippingConfig();
		var gateways = config.gateways || [];
		var i;

		if (!method) {
			return false;
		}

		for (i = 0; i < gateways.length; i += 1) {
			if (String(gateways[i]).toLowerCase() === String(method).toLowerCase()) {
				return true;
			}
		}

		return false;
	}

	function getFreeShippingConfig() {
		return (checkoutConfig.config && checkoutConfig.config.freeShipping) || {};
	}

	// International shipping is never free, and the order value rules only
	// cover the destinations inside the configured free shipping scope.
	function isFreeShippingEligible(serviceCode) {
		var config = getFreeShippingConfig();
		var definition = getServiceDefinition(serviceCode);
		var excluded = config.excludedProviders || [];
		var countries = config.countries || [];
		var country = getDeliveryCountry();
		var i;

		if (!definition || !definition.provider) {
			return false;
		}

		for (i = 0; i < excluded.length; i += 1) {
			if (String(excluded[i]) === String(definition.provider)) {
				return false;
			}
		}

		if (config.countryScope === 'all') {
			return true;
		}

		for (i = 0; i < countries.length; i += 1) {
			if (String(countries[i]).toUpperCase() === country) {
				return true;
			}
		}

		return false;
	}

	function hasCardFreeShipping(serviceCode) {
		var config = getCardFreeShippingConfig();
		var threshold = Number(config.threshold || 0);

		if (!config.enabled || threshold <= 0) {
			return false;
		}

		if (!isFreeShippingEligible(serviceCode)) {
			return false;
		}

		if (getCartSubtotal() < threshold) {
			return false;
		}

		if (config.scope === 'pickup' && !isFreeShippingService(serviceCode)) {
			return false;
		}

		if (config.paymentScope === 'all') {
			return true;
		}

		return isCardPaymentMethod(getSelectedPaymentMethod());
	}

	function getDeliveryCountry() {
		var fromField = $('#sameday_delivery_country').val();

		if (fromField) {
			return String(fromField).toUpperCase();
		}

		return String((checkoutConfig.config && checkoutConfig.config.country) || '').toUpperCase();
	}

	function getCheckoutCountry() {
		var shipToDifferent = $('#ship-to-different-address-checkbox').is(':checked');
		var country = shipToDifferent ? $('#shipping_country').val() : $('#billing_country').val();

		if (!country) {
			country = $('#billing_country').val() || '';
		}

		return String(country || '').toUpperCase();
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

	function getA1postRequiredFieldsComplete() {
		return !!($.trim($('#a1post_name').val() || '') &&
			$.trim($('#a1post_phone').val() || '') &&
			$.trim($('#a1post_email').val() || '') &&
			$.trim($('#a1post_address_1').val() || '') &&
			$.trim($('#a1post_city').val() || '') &&
			$.trim($('#a1post_postcode').val() || ''));
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

		if (!isFreeShippingEligible(serviceCode)) {
			return false;
		}

		if (hasCardFreeShipping(serviceCode)) {
			return true;
		}

		return isFreeShippingService(serviceCode) && threshold > 0 && getCartSubtotal() >= threshold;
	}

	function isServiceForProvider(serviceCode, provider) {
		var definition = getServiceDefinition(serviceCode);

		return !!(definition && definition.provider === provider);
	}

	function isSpeedyService(serviceCode) {
		return !!serviceCode && serviceCode.indexOf('speedy_') === 0;
	}

	// The Speedy price comes from the server (real contract price for the chosen
	// office / city, incl. the COD fee), so the summary always matches the totals.
	function getSpeedyQuoteKey(serviceCode) {
		var destination = serviceCode === 'speedy_door' ? getSpeedyDoorCity().toLowerCase() : getSpeedyLocationId();

		return [serviceCode, destination, $.trim($('#billing_postcode').val() || ''), getSelectedPaymentMethod()].join('|');
	}

	function requestSpeedyQuote(serviceCode) {
		var key = getSpeedyQuoteKey(serviceCode);

		clearTimeout(speedyQuoteTimer);
		speedyQuoteTimer = setTimeout(function() {
			if (speedyQuoteRequest) {
				speedyQuoteRequest.abort();
			}

			speedyQuoteRequest = $.ajax({
				url: checkoutConfig.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sameday_speedy_quote',
					security: checkoutConfig.nonce,
					service: serviceCode,
					location: getSpeedyLocationId(),
					door_city: getSpeedyDoorCity(),
					postcode: $.trim($('#billing_postcode').val() || ''),
					payment_method: getSelectedPaymentMethod()
				}
			}).done(function(response) {
				speedyQuotes[key] = response && response.success ? response.data : { failed: true };
			}).fail(function(xhr, status) {
				if (status !== 'abort') {
					speedyQuotes[key] = { failed: true };
				}
			}).always(function() {
				speedyQuoteRequest = null;

				if (getSpeedyQuoteKey(getSelectedService()) === key) {
					updateSummary();
				}
			});
		}, 400);
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

	function calculateA1postPrice(weight) {
		var pricing = (checkoutConfig.config.pricing && checkoutConfig.config.pricing.a1post) || {};
		var zones = pricing.zones || [];
		var country = getDeliveryCountry();
		var fallback = null;
		var zone = null;
		var includedWeight;
		var price;
		var i;

		for (i = 0; i < zones.length; i += 1) {
			if (!zones[i].countries || !zones[i].countries.length) {
				if (!fallback) {
					fallback = zones[i];
				}

				continue;
			}

			if ($.inArray(country, zones[i].countries) !== -1) {
				zone = zones[i];
				break;
			}
		}

		if (!zone) {
			zone = fallback;
		}

		if (!zone) {
			return 0;
		}

		includedWeight = Number(zone.includedWeight || 0);
		price = Number(zone.base || 0);

		if (weight > includedWeight) {
			price += Math.ceil(weight - includedWeight) * Number(zone.extraKg || 0);
		}

		return price;
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

		if (serviceCode.indexOf('a1post') === 0) {
			return calculateA1postPrice(weight);
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

			case 'a1post_international':
				return true;
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
		var $a1postFields = $('.a1post-fields');

		function showOnly($visible) {
			$easyboxSelector.addClass('is-hidden');
			$samedayDoorFields.addClass('is-hidden');
			$speedyLocationSelector.addClass('is-hidden');
			$speedyDoorFields.addClass('is-hidden');
			$a1postFields.addClass('is-hidden');

			if ($visible) {
				$visible.removeClass('is-hidden');
			}
		}

		if (!definition) {
			showOnly(null);
			return;
		}

		if (serviceCode === 'sameday_easybox') {
			showOnly($easyboxSelector);
			ensureEasyboxCitiesLoaded();
			enhanceEasyboxCitySelect();
			enhanceEasyboxLocationSelect();
			return;
		}

		if (serviceCode === 'sameday_door') {
			showOnly($samedayDoorFields);
			return;
		}

		if (isSpeedyLocationService(serviceCode)) {
			showOnly($speedyLocationSelector);
			ensureSpeedyCitiesLoaded();
			enhanceSpeedyCitySelect();
			enhanceSpeedyLocationSelect();
			fetchSpeedyLocations();
			return;
		}

		if (serviceCode === 'speedy_door') {
			showOnly($speedyDoorFields);
			return;
		}

		if (serviceCode === 'a1post_international') {
			showOnly($a1postFields);
			return;
		}

		showOnly(null);
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

		if (isSpeedyService(serviceCode)) {
			var quote = speedyQuotes[getSpeedyQuoteKey(serviceCode)];

			if (!quote) {
				$summary
					.removeClass('is-hidden is-free-shipping has-price')
					.html('<strong>' + (checkoutConfig.strings.calculatingPrice || '...') + '</strong>');
				requestSpeedyQuote(serviceCode);
				return;
			}

			if (!quote.failed) {
				if (quote.free) {
					$summary
						.removeClass('is-hidden has-price')
						.addClass('is-free-shipping')
						.html('<strong>' + checkoutConfig.strings.freeShipping + '</strong>');
					return;
				}

				$summary
					.removeClass('is-hidden is-free-shipping')
					.addClass('has-price')
					.html('<strong>' + checkoutConfig.strings.pricePrefix + ' ' + formatPrice(quote.price) + '</strong>');
				return;
			}
		}

		if (hasFreeShipping(serviceCode)) {
			$summary
				.removeClass('is-hidden has-price')
				.addClass('is-free-shipping')
				.html(
					'<strong>' + checkoutConfig.strings.freeShipping + '</strong>' +
					(hasCardFreeShipping(serviceCode) && getCardFreeShippingConfig().paymentScope !== 'all'
						? '<span class="sameday-free-shipping-reason">' + checkoutConfig.strings.freeShippingCard + '</span>'
						: '')
				);
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

	$(document.body).on('input change', '#a1post_name, #a1post_phone, #a1post_email, #a1post_address_1, #a1post_address_2, #a1post_city, #a1post_state, #a1post_postcode, #a1post_notes', function() {
		updateSummary();
		triggerCheckoutUpdate();
	});

	// The delivery box is rendered inside the billing form, which WooCommerce
	// does not refresh on "update_checkout" - so the country switch is handled
	// by re-rendering the box from the server.
	function reloadDeliveryFields() {
		var country = getCheckoutCountry();
		var $wrapper = $('#sameday-delivery-fields');

		if (!$wrapper.length || !country || country === lastRenderedCountry) {
			return;
		}

		lastRenderedCountry = country;

		if (deliveryFieldsRequest && deliveryFieldsRequest.readyState !== 4) {
			deliveryFieldsRequest.abort();
		}

		$wrapper.addClass('is-loading');

		deliveryFieldsRequest = $.ajax({
			url: checkoutConfig.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sameday_refresh_delivery_fields',
				security: checkoutConfig.nonce,
				country: country
			}
		}).done(function(response) {
			var $current = $('#sameday-delivery-fields');

			if (!response || !response.success || !response.data) {
				$current.removeClass('is-loading');
				return;
			}

			easyboxCitiesLoaded = false;
			speedyCitiesLoaded = false;
			easyboxLocationsMap = {};
			speedyLocationsMap = {};

			if (!$.trim(response.data.html)) {
				$current.removeClass('is-loading').html('');
			} else if ($current.length) {
				$current.replaceWith(response.data.html);
			}

			refreshDeliveryUi();
			triggerCheckoutUpdate();
		}).fail(function(xhr, status) {
			if (status !== 'abort') {
				$('#sameday-delivery-fields').removeClass('is-loading');
				lastRenderedCountry = '';
			}
		});
	}

	// WooCommerce does not recalculate the totals when the payment method
	// changes, so the delivery fee has to be refreshed explicitly - otherwise
	// the order review keeps showing a shipping charge that no longer applies.
	//
	// "payment_method_selected" is re-fired by WooCommerce after every
	// "updated_checkout", so the refresh runs only on a real change - otherwise
	// the two events would keep triggering each other.
	function handlePaymentMethodChange() {
		var method = getSelectedPaymentMethod();

		updateSummary();

		if (method === lastPaymentMethod) {
			return;
		}

		lastPaymentMethod = method;
		triggerCheckoutUpdate();
	}

	$(document.body).on('change', 'input[name="payment_method"]', handlePaymentMethodChange);
	$(document.body).on('payment_method_selected', handlePaymentMethodChange);

	$(document.body).on('change', '#billing_country, #shipping_country, #ship-to-different-address-checkbox', function() {
		reloadDeliveryFields();
	});

	$(document.body).on('country_to_state_changed', function() {
		reloadDeliveryFields();
	});

	$(document.body).on('updated_checkout', function() {
		refreshDeliveryUi();
	});

	lastRenderedCountry = getDeliveryCountry();
	lastPaymentMethod = getSelectedPaymentMethod();
	refreshDeliveryUi();
});
