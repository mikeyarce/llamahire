( function () {
	'use strict';

	const config = window.llamahireHiring || {};
	const pipeline = document.querySelector( '[data-hiring-pipeline]' );
	if ( ! pipeline ) {
		return;
	}

	let draggedCard = null;
	let dropStage = null;
	let pointerOrigin = null;
	let pointerDragging = false;
	let suppressClick = false;

	const clearDragState = () => {
		pipeline.querySelectorAll( '.is-drag-over' ).forEach( ( stage ) => stage.classList.remove( 'is-drag-over' ) );
		if ( draggedCard ) {
			draggedCard.classList.remove( 'is-dragging' );
		}
		draggedCard = null;
		dropStage = null;
	};

	const announce = ( message, isError ) => {
		const toast = document.querySelector( '.llamahire-hiring-toast' );
		if ( ! toast ) {
			return;
		}
		toast.textContent = message;
		toast.classList.toggle( 'is-error', Boolean( isError ) );
		toast.hidden = false;
	};

	const moveCandidate = async ( card, status ) => {
		if ( ! card || status === card.dataset.candidateStatus ) {
			clearDragState();
			return;
		}
		const application = card.dataset.candidateId;
		card.classList.add( 'is-saving' );
		clearDragState();
		const body = new URLSearchParams( {
			action: 'llamahire_move_application',
			application,
			status,
			nonce: config.nonce || '',
		} );
		try {
			const response = await fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} );
			const result = await response.json();
			if ( ! response.ok || ! result.success ) {
				throw new Error( result?.data?.message || config.errorMessage );
			}
			announce( ( config.movedMessage || 'Candidate moved to %s.' ).replace( '%s', result.data.label ), false );
			window.setTimeout( () => window.location.reload(), 180 );
		} catch ( error ) {
			card.classList.remove( 'is-saving' );
			announce( error.message || config.errorMessage, true );
		}
	};

	pipeline.addEventListener( 'dragstart', ( event ) => {
		const card = event.target.closest( '[data-candidate-id]' );
		if ( ! card ) {
			return;
		}
		draggedCard = card;
		card.classList.add( 'is-dragging' );
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData( 'text/plain', card.dataset.candidateId );
	} );

	pipeline.addEventListener( 'dragover', ( event ) => {
		const stage = event.target.closest( '[data-stage]' );
		if ( ! stage || ! draggedCard ) {
			return;
		}
		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';
		if ( dropStage !== stage ) {
			pipeline.querySelectorAll( '.is-drag-over' ).forEach( ( item ) => item.classList.remove( 'is-drag-over' ) );
			dropStage = stage;
			stage.classList.add( 'is-drag-over' );
		}
	} );

	pipeline.addEventListener( 'drop', ( event ) => {
		const stage = event.target.closest( '[data-stage]' );
		if ( ! stage || ! draggedCard ) {
			return;
		}
		event.preventDefault();
		moveCandidate( draggedCard, stage.dataset.stage );
	} );

	pipeline.addEventListener( 'dragend', clearDragState );

	pipeline.addEventListener( 'pointerdown', ( event ) => {
		const card = event.target.closest( '[data-candidate-id]' );
		if ( ! card || event.button !== 0 || event.target.closest( 'details, select, button' ) ) {
			return;
		}
		pointerOrigin = { x: event.clientX, y: event.clientY, card };
	} );

	window.addEventListener( 'pointermove', ( event ) => {
		if ( ! pointerOrigin ) {
			return;
		}
		const distance = Math.hypot( event.clientX - pointerOrigin.x, event.clientY - pointerOrigin.y );
		if ( ! pointerDragging && distance < 7 ) {
			return;
		}
		event.preventDefault();
		pointerDragging = true;
		draggedCard = pointerOrigin.card;
		draggedCard.classList.add( 'is-dragging' );
		const stage = document.elementFromPoint( event.clientX, event.clientY )?.closest( '[data-stage]' );
		if ( stage !== dropStage ) {
			pipeline.querySelectorAll( '.is-drag-over' ).forEach( ( item ) => item.classList.remove( 'is-drag-over' ) );
			dropStage = stage || null;
			if ( dropStage ) {
				dropStage.classList.add( 'is-drag-over' );
			}
		}
	}, { passive: false } );

	window.addEventListener( 'pointerup', () => {
		if ( pointerDragging && draggedCard && dropStage ) {
			const card = draggedCard;
			const status = dropStage.dataset.stage;
			suppressClick = true;
			moveCandidate( card, status );
			window.setTimeout( () => {
				suppressClick = false;
			}, 250 );
		} else {
			clearDragState();
		}
		pointerOrigin = null;
		pointerDragging = false;
	} );

	pipeline.addEventListener( 'click', ( event ) => {
		if ( suppressClick ) {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true );

	document.querySelectorAll( '.llamahire-hiring-filters select[name="job_id"]' ).forEach( ( select ) => {
		select.addEventListener( 'change', () => select.form.submit() );
	} );

	document.querySelectorAll( '[data-open-reject-dialog]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const dialog = document.getElementById( button.dataset.openRejectDialog );
			if ( dialog?.showModal ) {
				dialog.showModal();
			}
		} );
	} );

	document.querySelectorAll( '.llamahire-reject-dialog' ).forEach( ( dialog ) => {
		dialog.querySelectorAll( '[data-close-reject-dialog]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => dialog.close() );
		} );
		dialog.addEventListener( 'click', ( event ) => {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				dialog.close();
			}
		} );
	} );
}() );
