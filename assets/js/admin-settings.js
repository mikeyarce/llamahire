( function ( $, wp ) {
	'use strict';

	$( function () {
		var setupChoice = $( 'input[name="careers_action"]' );
		var careersTitle = $( '#llamahire-careers-title' );
		var careersPage = $( '#llamahire-setup-careers-page' );

		function updateCareersChoice() {
			var action = setupChoice.filter( ':checked' ).val();
			careersTitle.prop( 'disabled', 'create' !== action ).prop( 'required', 'create' === action );
			careersPage.prop( 'disabled', 'select' !== action ).prop( 'required', 'select' === action );
		}

		if ( setupChoice.length ) {
			setupChoice.on( 'change', updateCareersChoice );
			updateCareersChoice();
		}

		$( '.notice-error[role="alert"]' ).first().trigger( 'focus' );

		$( '.llamahire-media-field' ).each( function () {
			var field = $( this );
			var input = field.find( 'input[type="hidden"]' );
			var preview = field.find( '.llamahire-media-preview' );
			var selectButton = field.find( '.llamahire-select-media' );
			var removeButton = field.find( '.llamahire-remove-media' );
			var emptyLabel = field.data( 'empty-label' );
			var selectedLabel = field.data( 'selected-label' );
			var frame;

			selectButton.on( 'click', function () {
				if ( frame ) {
					frame.open();
					return;
				}
				frame = wp.media( {
					title: field.data( 'media-title' ),
					button: { text: field.data( 'media-button' ) },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					input.val( attachment.url ).trigger( 'change' );
					preview.html( $( '<img>', { src: attachment.url, alt: '', css: { display: 'block', maxWidth: '240px', maxHeight: '120px', width: 'auto', height: 'auto' } } ) );
					selectButton.text( selectedLabel );
					removeButton.prop( 'hidden', false );
				} );
				frame.open();
			} );

			removeButton.on( 'click', function () {
				input.val( '' ).trigger( 'change' );
				preview.empty();
				selectButton.text( emptyLabel );
				removeButton.prop( 'hidden', true );
			} );
		} );
	} );
} )( window.jQuery, window.wp );
