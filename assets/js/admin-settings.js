( function ( $, wp ) {
	'use strict';

	$( function () {
		var setupChoice = $( 'input[name="careers_action"]' );
		var siteModeChoice = $( 'input[name="organization[site_mode]"], input[name="llamahire_organization[site_mode]"]' );
		var careersTitle = $( '#llamahire-careers-title' );
		var careersPage = $( '#llamahire-setup-careers-page' );
		var privacyText = $( '#llamahire-setup-privacy-text, #llamahire-privacy-text' ).first();
		var privacyPage = $( '#llamahire-setup-privacy-page' );
		var retentionChoice = $( '#llamahire-setup-retention' );
		var candidatePreview = $( '.llamahire-candidate-preview' );
		var antiSpamProvider = $( '#llamahire-anti-spam-provider' );
		var antiSpamKeys = $( '[data-llamahire-anti-spam-keys]' );

		function updateAntiSpamFields() {
			antiSpamKeys.prop( 'hidden', ! antiSpamProvider.length || 'none' === antiSpamProvider.val() );
		}

		function updatePrivacyPreview() {
			if ( ! candidatePreview.length ) {
				return;
			}
			var selectedPolicy = privacyPage.find( 'option:selected' );
			var hasPolicy = '0' !== privacyPage.val() || '1' === candidatePreview.attr( 'data-default-policy-available' );
			var retention = retentionChoice.find( 'option:selected' ).text();
			candidatePreview.find( '[data-llamahire-preview-privacy]' ).text( privacyText.val() || 'Your privacy notice will appear here.' );
			candidatePreview.find( '[data-llamahire-preview-policy-link]' ).prop( 'hidden', ! hasPolicy );
			candidatePreview.find( '[data-llamahire-preview-policy-source]' ).text( hasPolicy ? 'Policy link: ' + selectedPolicy.text() : 'No privacy policy link will appear until a WordPress privacy policy is configured.' );
			candidatePreview.find( '[data-llamahire-preview-retention]' ).text( '0' === retentionChoice.val() ? 'Application records are kept until manually erased.' : 'Application records are scheduled for deletion after ' + retention.toLowerCase() + '.' );
		}

		function updateSiteModeCopy() {
			var mode = siteModeChoice.filter( ':checked' ).val() === 'job_board' ? 'job-board' : 'company';
			var previousMode = mode === 'job-board' ? 'company' : 'job-board';
			var companyWebsite = $( '[data-llamahire-company-website]' );
			var jobBoardOnly = $( '[data-llamahire-job-board-only]' );
			$( '[data-llamahire-mode-copy]' ).each( function () {
				$( this ).text( $( this ).attr( 'data-' + mode + '-copy' ) );
			} );
			companyWebsite.prop( 'hidden', 'job-board' === mode );
			companyWebsite.find( ':input' ).prop( 'disabled', 'job-board' === mode );
			jobBoardOnly.prop( 'hidden', 'job-board' !== mode );
			if ( careersTitle.length ) {
				var currentTitle = careersTitle.val();
				var previousTitle = careersTitle.attr( 'data-' + previousMode + '-default' );
				if ( ! currentTitle || currentTitle === previousTitle ) {
					careersTitle.val( careersTitle.attr( 'data-' + mode + '-default' ) );
				}
			}
			if ( privacyText.length ) {
				var current = privacyText.val();
				var previousDefault = privacyText.attr( 'data-' + previousMode + '-default' );
				if ( ! current || current === previousDefault ) {
					privacyText.val( privacyText.attr( 'data-' + mode + '-default' ) );
				}
			}
			updatePrivacyPreview();
		}

		function updateCareersChoice() {
			var action = setupChoice.filter( ':checked' ).val();
			careersTitle.prop( 'disabled', 'create' !== action ).prop( 'required', 'create' === action );
			careersPage.prop( 'disabled', 'select' !== action ).prop( 'required', 'select' === action );
		}

		if ( setupChoice.length ) {
			setupChoice.on( 'change', updateCareersChoice );
			updateCareersChoice();
		}

		if ( siteModeChoice.length ) {
			siteModeChoice.on( 'change', updateSiteModeCopy );
			updateSiteModeCopy();
		}

		privacyText.add( privacyPage ).add( retentionChoice ).on( 'input change', updatePrivacyPreview );
		updatePrivacyPreview();
		antiSpamProvider.on( 'change', updateAntiSpamFields );
		updateAntiSpamFields();

		$( '[data-llamahire-settings]' ).each( function () {
			var screen = $( this );
			var form = screen.find( '[data-llamahire-settings-form]' );
			var sections = screen.find( '[data-llamahire-settings-section]' );
			var related = screen.find( '[data-llamahire-settings-related]' );
			var links = screen.find( '[data-llamahire-settings-link]' );
			var defaultSection = 'organization';
			var storageKey = 'llamahire-settings-section';
			var currentSection = defaultSection;

			function sectionFromHash() {
				var match = window.location.hash.match( /^#llamahire-settings-([a-z-]+)$/ );
				var remembered;
				if ( match && sections.filter( '[data-llamahire-settings-section="' + match[ 1 ] + '"]' ).length ) {
					return match[ 1 ];
				}
				try {
					remembered = window.sessionStorage.getItem( storageKey );
				} catch ( error ) {
					remembered = '';
				}
				return remembered && sections.filter( '[data-llamahire-settings-section="' + remembered + '"]' ).length ? remembered : defaultSection;
			}

			function showSection( name, shouldFocus ) {
				var current = sections.filter( '[data-llamahire-settings-section="' + name + '"]' );
				if ( ! current.length ) {
					return;
				}
				currentSection = name;
				sections.each( function () {
					$( this ).prop( 'hidden', $( this ).attr( 'data-llamahire-settings-section' ) !== name );
				} );
				related.each( function () {
					$( this ).prop( 'hidden', $( this ).attr( 'data-llamahire-settings-related' ) !== name );
				} );
				links.each( function () {
					var link = $( this );
					if ( link.attr( 'data-llamahire-settings-link' ) === name ) {
						link.attr( 'aria-current', 'page' );
					} else {
						link.removeAttr( 'aria-current' );
					}
				} );
				try {
					window.sessionStorage.setItem( storageKey, name );
				} catch ( error ) {
					// The active hash still preserves the section when storage is unavailable.
				}
				if ( shouldFocus ) {
					current.find( 'h2' ).first().trigger( 'focus' );
				}
			}

			screen.addClass( 'is-enhanced' );
			screen.find( '[data-llamahire-page-picker]' ).each( function () {
				var picker = $( this );
				var select = screen.find( '#' + picker.attr( 'data-select-id' ) );
				var mount = picker.find( '[data-llamahire-page-combobox]' ).get( 0 );
				var options = [];
				var control;

				if ( ! mount || ! select.length || ! wp || ! wp.components || ! wp.components.ComboboxControl || ! wp.element ) {
					return;
				}
				select.find( 'option' ).each( function () {
					options.push( {
						value: $( this ).attr( 'value' ),
						label: $( this ).text()
					} );
				} );
				control = wp.element.createElement( wp.components.ComboboxControl, {
					label: picker.attr( 'data-label' ),
					hideLabelFromVision: true,
					value: select.val(),
					options: options,
					allowReset: false,
					__next40pxDefaultSize: true,
					onChange: function ( value ) {
						select.val( null == value ? '0' : value ).trigger( 'change' );
					}
				} );
				if ( wp.element.createRoot ) {
					wp.element.createRoot( mount ).render( control );
				} else {
					wp.element.render( control, mount );
				}
				picker.addClass( 'is-enhanced' );
			} );
			links.on( 'click', function ( event ) {
				var name = $( this ).attr( 'data-llamahire-settings-link' );
				event.preventDefault();
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', '#llamahire-settings-' + name );
				} else {
					window.location.hash = 'llamahire-settings-' + name;
				}
				showSection( name, true );
			} );
			form.on( 'submit', function () {
				var action = form.attr( 'action' ).split( '#' )[ 0 ];
				form.attr( 'action', action + '#llamahire-settings-' + currentSection );
			} );
			form.on( 'invalid', ':input', function () {
				var section = $( this ).closest( '[data-llamahire-settings-section]' ).attr( 'data-llamahire-settings-section' );
				if ( section ) {
					showSection( section, false );
				}
			} );
			$( window ).on( 'hashchange', function () {
				showSection( sectionFromHash(), false );
			} );
			showSection( sectionFromHash(), false );
		} );

		$( '[data-llamahire-setup]' ).each( function () {
			var setup = $( this );
			var form = setup.find( '.llamahire-setup-form' );
			var steps = form.find( '[data-llamahire-setup-step]' );
			var indicators = setup.find( '[data-llamahire-step-indicator]' );
			var status = setup.find( '.llamahire-setup-progress__status' );
			var progress = setup.find( 'progress' );
			var back = form.find( '[data-llamahire-setup-back]' );
			var next = form.find( '[data-llamahire-setup-next]' );
			var submit = form.find( '[data-llamahire-setup-submit]' );
			var skip = form.find( '[data-llamahire-setup-skip]' );
			var edit = form.find( '[data-llamahire-setup-edit]' );
			var stepInput = form.find( '[data-llamahire-setup-step-input]' );
			var currentStep = Math.max( 1, Math.min( steps.length, parseInt( setup.attr( 'data-initial-step' ), 10 ) || 1 ) );
			var isSkipping = false;

			function stepTitle( step ) {
				return indicators.filter( '[data-llamahire-step-indicator="' + step + '"]' ).find( '> span:last-child' ).text();
			}

			function reviewValue( key, value ) {
				form.find( '[data-llamahire-review="' + key + '"]' ).text( value || 'Not set' );
			}

			function updateReview() {
				var mode = form.find( 'input[name="organization[site_mode]"]:checked' ).closest( 'label' ).find( 'strong' ).text();
				var isJobBoard = 'job_board' === form.find( 'input[name="organization[site_mode]"]:checked' ).val();
				var action = form.find( 'input[name="careers_action"]:checked' ).val();
				var pageValue = 'create' === action
					? careersTitle.val()
					: careersPage.find( 'option:selected' ).text();
				var location = [
					form.find( '#llamahire-setup-locality' ).val(),
					form.find( '#llamahire-setup-region' ).val(),
					form.find( '#llamahire-setup-country option:selected' ).text()
				].filter( Boolean ).join( ', ' );
				var currency = form.find( '#llamahire-setup-currency option:selected' ).text();
				var policy = form.find( '#llamahire-setup-privacy-page option:selected' ).text();
				var retention = form.find( '#llamahire-setup-retention option:selected' ).text();

				reviewValue( 'purpose', mode );
				reviewValue( 'identity', form.find( '#llamahire-setup-name' ).val() );
				reviewValue( 'defaults', ( location || 'No default location' ) + ' · ' + currency );
				reviewValue( 'email', 'Notifications: ' + form.find( '#llamahire-setup-email' ).val() );
				reviewValue( 'privacy', 'Privacy policy: ' + policy );
				reviewValue( 'retention', 'Retention: ' + retention );
				reviewValue( 'careers', pageValue );
				reviewValue( 'publication', 'create' === action ? 'This page will be published when Setup is completed.' : 'Existing compatible published page.' );
				reviewValue( 'employer-pages', isJobBoard ? 'Submit a Job, My Jobs, and Account pages will be created automatically if needed.' : '' );
			}

			function showStep( step, shouldFocus ) {
				currentStep = Math.max( 1, Math.min( steps.length, step ) );
				steps.each( function () {
					var isCurrent = parseInt( $( this ).attr( 'data-llamahire-setup-step' ), 10 ) === currentStep;
					$( this ).prop( 'hidden', ! isCurrent );
				} );
				indicators.each( function () {
					var indicator = $( this );
					var indicatorStep = parseInt( indicator.attr( 'data-llamahire-step-indicator' ), 10 );
					indicator.find( '.llamahire-setup-steps__marker' ).text( indicatorStep < currentStep ? '✓' : indicatorStep );
					indicator.toggleClass( 'is-current', indicatorStep === currentStep );
					indicator.toggleClass( 'is-complete', indicatorStep < currentStep );
					if ( indicatorStep === currentStep ) {
						indicator.attr( 'aria-current', 'step' );
					} else {
						indicator.removeAttr( 'aria-current' );
					}
				} );
				stepInput.val( currentStep );
				progress.val( currentStep - 1 ).attr( 'aria-valuetext', 1 === currentStep ? 'Not complete' : 'Step ' + currentStep + ' of ' + steps.length ).text( Math.round( ( currentStep - 1 ) / steps.length * 100 ) + '%' );
				status.text( 'Step ' + currentStep + ' of ' + steps.length + ' — ' + stepTitle( currentStep ) );
				back.prop( 'hidden', 1 === currentStep );
				next.prop( 'hidden', currentStep === steps.length );
				submit.prop( 'hidden', currentStep !== steps.length );
				if ( currentStep === steps.length ) {
					updateReview();
				}
				if ( shouldFocus ) {
					steps.filter( '[data-llamahire-setup-step="' + currentStep + '"]' ).find( 'h2' ).first().trigger( 'focus' );
				}
			}

			function validateStep( step ) {
				var valid = true;
				var fields = steps.filter( '[data-llamahire-setup-step="' + step + '"]' ).find( ':input' ).filter( ':enabled' );
				fields.each( function () {
					if ( ! this.checkValidity() ) {
						if ( step !== currentStep ) {
							showStep( step, false );
						}
						this.reportValidity();
						valid = false;
						return false;
					}
				} );
				return valid;
			}

			setup.addClass( 'llamahire-setup-is-enhanced' );
			form.attr( 'novalidate', 'novalidate' );
			next.on( 'click', function () {
				if ( validateStep( currentStep ) ) {
					showStep( currentStep + 1, true );
				}
			} );
			back.on( 'click', function () {
				showStep( currentStep - 1, true );
			} );
			edit.on( 'click', function () {
				showStep( parseInt( $( this ).attr( 'data-llamahire-setup-edit' ), 10 ), true );
			} );
			skip.on( 'click', function () {
				isSkipping = true;
			} );
			form.on( 'submit', function ( event ) {
				var step;
				if ( isSkipping ) {
					return;
				}
				for ( step = 1; step <= steps.length; step++ ) {
					if ( ! validateStep( step ) ) {
						event.preventDefault();
						showStep( step, false );
						return;
					}
				}
			} );
			form.on( 'input change', 'input, textarea, select', function () {
				if ( currentStep === steps.length ) {
					updateReview();
				}
			} );
			showStep( currentStep, false );
		} );

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
