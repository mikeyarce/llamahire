( function () {
	'use strict';

	var applicationFieldset = document.querySelector( '.llamahire-employer-portal__applications' );
	if ( applicationFieldset ) {
		var method = applicationFieldset.querySelector( '[name="application_method"]' );
		var target = applicationFieldset.querySelector( '[name="application_target"]' );
		var label = applicationFieldset.querySelector( '[data-llamahire-application-target-label]' );
		var applicationHelp = applicationFieldset.querySelector( '[data-llamahire-application-target-help]' );
		if ( method && target && label && applicationHelp ) {
			var updateTarget = function () {
				var key = method.value === 'external_url' ? 'externalUrl' : ( method.value === 'external_email' ? 'externalEmail' : 'internal' );
				label.textContent = applicationFieldset.dataset[ key + 'Label' ];
				applicationHelp.textContent = applicationFieldset.dataset[ key + 'Help' ];
				target.type = method.value === 'external_url' ? 'url' : 'email';
				target.inputMode = method.value === 'external_url' ? 'url' : 'email';
				target.autocomplete = method.value === 'external_url' ? 'url' : 'email';
			};

			method.addEventListener( 'change', updateTarget );
			updateTarget();
		}
	}

	var locationFieldset = document.querySelector( '[data-llamahire-location-fields]' );
	if ( locationFieldset ) {
		var workplace = locationFieldset.querySelector( '[name="workplace"]' );
		var physicalFields = locationFieldset.querySelector( '[data-llamahire-physical-location]' );
		var remoteFields = locationFieldset.querySelector( '[data-llamahire-remote-location]' );
		var locationHelp = locationFieldset.querySelector( '[data-llamahire-location-help]' );
		var city = locationFieldset.querySelector( '[name="address_locality"]' );
		var country = locationFieldset.querySelector( '[name="address_country"]' );
		var eligibleCountries = locationFieldset.querySelector( '[name="applicant_countries"]' );
		if ( workplace && physicalFields && remoteFields && locationHelp && city && country && eligibleCountries ) {
			var updateLocation = function () {
				var remote = workplace.value === 'remote';
				physicalFields.hidden = remote;
				remoteFields.hidden = ! remote;
				city.required = ! remote;
				country.required = ! remote;
				eligibleCountries.required = remote;
				locationHelp.textContent = remote ? locationFieldset.dataset.remoteHelp : locationFieldset.dataset.physicalHelp;
			};

			workplace.addEventListener( 'change', updateLocation );
			updateLocation();
		}
	}
}() );
