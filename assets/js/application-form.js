( function () {
	'use strict';

	var recoverableResults = [ 'required', 'invalid_phone', 'invalid_fields', 'resume_size', 'resume_type', 'resume_storage', 'rate_limited', 'error' ];

	function enhanceSubmission( form ) {
		if ( ! window.FormData || ! window.XMLHttpRequest ) {
			return;
		}

		var supportProbe = new window.XMLHttpRequest();
		if ( ! supportProbe.upload ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
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

			function recover( message, clearFile ) {
				delete form.dataset.submitting;
				form.removeAttribute( 'aria-busy' );
				if ( submit ) {
					submit.disabled = false;
					submit.removeAttribute( 'aria-disabled' );
				}
				progressWrapper.hidden = true;
				status.setAttribute( 'role', 'alert' );
				status.textContent = 'string' === typeof message && message ? message : status.dataset.error;
				status.focus();
				if ( clearFile && resume ) {
					resume.value = '';
					resume.dispatchEvent( new window.Event( 'change' ) );
				}
			}

			xhr.addEventListener( 'load', function () {
				if ( xhr.status === 422 ) {
					var invalidDocument = new window.DOMParser().parseFromString( xhr.responseText, 'text/html' );
					var invalidNotice = invalidDocument.querySelector( '[data-llamahire-application] .llamahire-notice' );
					invalidDocument.querySelectorAll( '[data-llamahire-field-error][id]' ).forEach( function ( source ) {
						var target = document.getElementById( source.id );
						if ( target && form.contains( target ) ) {
							target.textContent = source.textContent;
							form.querySelectorAll( '[aria-describedby]' ).forEach( function ( field ) {
								if ( field.getAttribute( 'aria-describedby' ).split( /\s+/ ).indexOf( source.id ) !== -1 ) {
									field.setAttribute( 'aria-invalid', source.textContent.trim() ? 'true' : 'false' );
								}
							} );
						}
					} );
					recover( invalidNotice ? invalidNotice.textContent.trim() : '', false );
					return;
				}

				if ( xhr.status >= 200 && xhr.status < 400 && xhr.responseURL ) {
					var responseUrl = new window.URL( xhr.responseURL, window.location.href );
					var result = responseUrl.searchParams.get( 'application' ) || '';
					if ( recoverableResults.indexOf( result ) !== -1 ) {
						var responseDocument = new window.DOMParser().parseFromString( xhr.responseText, 'text/html' );
						var notice = responseDocument.querySelector( '[data-llamahire-application] .llamahire-notice' );
						recover( notice ? notice.textContent.trim() : '', true );
						return;
					}
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
		var form = container.querySelector( '[data-llamahire-application-form]' );

		if ( ! form ) {
			return;
		}
		var resume = form.elements.namedItem( 'resume' );
		var resumePrompt = form.querySelector( '[data-resume-prompt]' );
		if ( resume && resumePrompt ) {
			var resumePromptText = resumePrompt.textContent;
			resume.addEventListener( 'change', function () {
				if ( resume.files && resume.files.length ) {
					resumePrompt.textContent = resume.files[0].name;
				} else {
					resumePrompt.textContent = resumePromptText;
				}
			} );
		}
		enhanceSubmission( form );
	} );
}() );
