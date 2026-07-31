( function () {
	'use strict';

	var recoverableResults = [ 'required', 'invalid_phone', 'invalid_fields', 'resume_size', 'resume_type', 'resume_storage', 'rate_limited', 'error' ];

	function storageKey( container ) {
		return 'llamahire-application:' + window.location.pathname + ':' + container.dataset.llamahireApplication;
	}

	function clear( key ) {
		try {
			window.sessionStorage.removeItem( key );
		} catch ( error ) {
			// Storage can be unavailable in privacy modes; the form still works without restoration.
		}
	}

	function preserveValues( form, key ) {
		var values = { expires: Date.now() + 10 * 60 * 1000 };
		[ 'name', 'email', 'phone', 'cover_letter' ].forEach( function ( name ) {
			var field = form.elements.namedItem( name );
			if ( field ) {
				values[ name ] = field.value;
			}
		} );
		try {
			window.sessionStorage.setItem( key, JSON.stringify( values ) );
		} catch ( error ) {
			// Storage can be unavailable in privacy modes; submission must remain functional.
		}
	}

	function enhanceSubmission( form, key ) {
		if ( ! window.FormData || ! window.XMLHttpRequest ) {
			form.addEventListener( 'submit', function () {
				preserveValues( form, key );
			} );
			return;
		}

		var supportProbe = new window.XMLHttpRequest();
		if ( ! supportProbe.upload ) {
			form.addEventListener( 'submit', function () {
				preserveValues( form, key );
			} );
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			preserveValues( form, key );
			if ( form.dataset.submitting ) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			var xhr = new window.XMLHttpRequest();
			var feedback = form.querySelector( '[data-upload-feedback]' );
			var status = form.querySelector( '[data-upload-status]' );
			var progressWrapper = form.querySelector( '[data-upload-progress-wrapper]' );
			var progress = form.querySelector( '[data-upload-progress]' );
			var percent = form.querySelector( '[data-upload-percent]' );
			var submit = event.submitter || form.querySelector( '[type="submit"]' );
			var resume = form.elements.namedItem( 'resume' );
			var hasResume = !! ( resume && ( resume.value || resume.files && resume.files.length ) );

			form.dataset.submitting = 'true';
			form.setAttribute( 'aria-busy', 'true' );
			if ( submit ) {
				submit.disabled = true;
				submit.setAttribute( 'aria-disabled', 'true' );
			}
			feedback.hidden = false;
			status.setAttribute( 'role', 'status' );
			status.textContent = hasResume ? status.dataset.uploading : status.dataset.submitting;
			progressWrapper.hidden = ! hasResume;
			if ( hasResume ) {
				progress.value = 0;
				percent.textContent = '0%';
			}

			xhr.open( form.getAttribute( 'method' ) || 'POST', form.getAttribute( 'action' ) || window.location.href );
			xhr.upload.addEventListener( 'progress', function ( uploadEvent ) {
				if ( ! hasResume ) {
					return;
				}
				if ( uploadEvent.lengthComputable ) {
					var value = Math.min( 100, Math.round( uploadEvent.loaded / uploadEvent.total * 100 ) );
					progress.value = value;
					percent.textContent = value + '%';
				} else {
					progress.removeAttribute( 'value' );
					percent.textContent = '';
				}
			} );
			xhr.upload.addEventListener( 'load', function () {
				status.textContent = status.dataset.processing;
				if ( hasResume ) {
					progress.value = 100;
					percent.textContent = '100%';
				}
			} );

			function recover() {
				delete form.dataset.submitting;
				form.removeAttribute( 'aria-busy' );
				if ( submit ) {
					submit.disabled = false;
					submit.removeAttribute( 'aria-disabled' );
				}
				progressWrapper.hidden = true;
				status.setAttribute( 'role', 'alert' );
				status.textContent = status.dataset.error;
				status.focus();
			}

			xhr.addEventListener( 'load', function () {
				if ( xhr.status >= 200 && xhr.status < 400 && xhr.responseURL ) {
					window.location.assign( xhr.responseURL.split( '#' )[0] + '#llamahire-application' );
					return;
				}
				recover();
			} );
			xhr.addEventListener( 'error', recover );
			xhr.addEventListener( 'abort', recover );
			xhr.send( new window.FormData( form ) );
		} );
	}

	document.querySelectorAll( '[data-llamahire-application]' ).forEach( function ( container ) {
		var key = storageKey( container );
		var form = container.querySelector( '[data-llamahire-application-form]' );
		var result = container.dataset.applicationResult || '';

		if ( form && recoverableResults.indexOf( result ) !== -1 ) {
			try {
				var saved = JSON.parse( window.sessionStorage.getItem( key ) || '{}' );
				if ( saved.expires > Date.now() ) {
					[ 'name', 'email', 'phone', 'cover_letter' ].forEach( function ( name ) {
						var field = form.elements.namedItem( name );
						if ( field && typeof saved[ name ] === 'string' ) {
							field.value = saved[ name ];
						}
					} );
				}
			} catch ( error ) {
				// Ignore malformed or unavailable session storage.
			}
			clear( key );
		} else if ( result || form ) {
			clear( key );
		}

		if ( ! form ) {
			return;
		}
		var resume = form.elements.namedItem( 'resume' );
		var resumePrompt = form.querySelector( '[data-resume-prompt]' );
		if ( resume && resumePrompt ) {
			resume.addEventListener( 'change', function () {
				if ( resume.files && resume.files.length ) {
					resumePrompt.textContent = resume.files[0].name;
				}
			} );
		}
		enhanceSubmission( form, key );
	} );
}() );
