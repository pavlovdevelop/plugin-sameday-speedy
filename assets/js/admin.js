/* Sameday WooCommerce BG Admin JavaScript */

jQuery(document).ready(function($) {
	// Toggle location form
	$('.sameday-toggle-form').click(function(e) {
		e.preventDefault();
		var formId = $(this).data('form');
		$('#' + formId).toggle();
	});

	// Confirm delete
	$('.sameday-delete-confirm').click(function(e) {
		if (!confirm(sameday_admin_vars.confirm_delete)) {
			e.preventDefault();
		}
	});

	// Auto-hide success/error messages
	setTimeout(function() {
		$('.sameday-message').fadeOut('slow');
	}, 5000);

	// Form validation
	$('#sameday-location-form').submit(function() {
		var city = $('#city').val().trim();
		var name = $('#name').val().trim();
		var address = $('#address').val().trim();

		if (city === '' || name === '' || address === '') {
			alert(sameday_admin_vars.required_fields);
			return false;
		}

		return true;
	});
});