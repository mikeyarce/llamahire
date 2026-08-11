import { getElement, store, withSyncEvent } from '@wordpress/interactivity';

const QUERY_KEYS = [ 'job_search', 'department', 'employment_type', 'workplace', 'location', 'featured' ];
const formsSelector = '[data-llamahire-query-form]';
let debounceTimer;
let navigationSequence = 0;

document.documentElement.classList.add( 'llamahire-has-interactivity' );

const { state } = store( 'llamahire/job-discovery', {
	state: {
		isLoading: false,
	},
	actions: {
		submit: withSyncEvent( ( event ) => {
			event.preventDefault();
			const activeElement = document.activeElement;
			const focusKey = activeElement && activeElement.name ? activeElement.name : 'job_search';
			void navigateTo( buildUrl(), focusKey );
		} ),
		update: () => {
			const { ref } = getElement();
			updateControlState( ref );
			if ( normalizeName( ref.name ) === 'location' ) {
				resetLocationSearch( ref.closest( '[data-llamahire-location-menu]' ) );
			}
			void navigateTo( buildUrl(), normalizeName( ref.name ), ref.value );
		},
		debounce: () => {
			const { ref } = getElement();
			updateControlState( ref );
			window.clearTimeout( debounceTimer );
			debounceTimer = window.setTimeout( () => {
				void navigateTo( buildUrl(), normalizeName( ref.name ) );
			}, 400 );
		},
		clear: withSyncEvent( ( event ) => {
			const { ref } = getElement();
			if ( ! shouldHandleLink( event, ref ) ) {
				return;
			}
			event.preventDefault();
			void navigateTo( ref.href, 'job_search' );
		} ),
		remove: withSyncEvent( ( event ) => {
			const { ref } = getElement();
			if ( ! shouldHandleLink( event, ref ) ) {
				return;
			}
			event.preventDefault();
			void navigateTo( ref.href, ref.dataset.llamahireFilterKey );
		} ),
	},
} );

function buildUrl() {
	const forms = Array.from( document.querySelectorAll( formsSelector ) );
	const action = forms[ 0 ] ? forms[ 0 ].action : window.location.href;
	const url = new URL( action, window.location.href );

	url.searchParams.delete( 'job_page' );
	forms.forEach( ( form ) => {
		const values = new Map();
		Array.from( form.elements ).forEach( ( control ) => {
			const key = normalizeName( control.name );
			if ( ! key || ! QUERY_KEYS.includes( key ) || control.disabled ) {
				return;
			}
			if ( ! values.has( key ) ) {
				values.set( key, [] );
			}
			if ( ( control.type === 'checkbox' || control.type === 'radio' ) && ! control.checked ) {
				return;
			}
			const value = control.value.trim();
			if ( value ) {
				values.get( key ).push( value );
			}
		} );
		values.forEach( ( selected, key ) => {
			url.searchParams.delete( key );
			url.searchParams.delete( `${ key }[]` );
			if ( selected.length ) {
				url.searchParams.set( key, [ ...new Set( selected ) ].join( key === 'location' ? '|' : ',' ) );
			}
		} );
	} );

	return url.href;
}

async function navigateTo( url, focusKey, focusValue = '' ) {
	const sequence = ++navigationSequence;
	state.isLoading = true;

	try {
		const { actions } = await import( '@wordpress/interactivity-router' );
		await actions.navigate( url );
		syncControls( url );
		focusControl( focusKey, focusValue );
	} finally {
		if ( sequence === navigationSequence ) {
			state.isLoading = false;
		}
	}
}

function syncControls( href ) {
	const url = new URL( href, window.location.href );
	document.querySelectorAll( formsSelector ).forEach( ( form ) => {
		Array.from( form.elements ).forEach( ( control ) => {
			const key = normalizeName( control.name );
			if ( ! key || ! QUERY_KEYS.includes( key ) || control.type === 'hidden' ) {
				return;
			}
			if ( control.type === 'checkbox' || control.type === 'radio' ) {
				control.checked = selectedValues( url, key ).includes( control.value.toLowerCase() );
			} else {
				control.value = url.searchParams.get( key ) || '';
			}
			updateControlState( control );
		} );
	} );
}

function updateControlState( control ) {
	const wrapper = control.closest( '.llamahire-filter-control, .llamahire-filter-menu, .llamahire-checkbox' );
	if ( ! wrapper ) {
		return;
	}
	const groupControls = wrapper.matches( '.llamahire-filter-menu' ) ? Array.from( wrapper.querySelectorAll( 'input[type="checkbox"]' ) ) : [ control ];
	const selected = groupControls.filter( ( candidate ) => candidate.checked );
	const isActive = control.type === 'checkbox' ? selected.length > 0 : Boolean( control.value.trim() );
	wrapper.classList.toggle( 'is-active', isActive );
	const summary = wrapper.querySelector( '[data-llamahire-filter-summary]' );
	if ( summary ) {
		const selectedLabels = selected.map( ( candidate ) => candidate.closest( 'label' )?.querySelector( 'span' )?.textContent.trim() || candidate.value );
		summary.textContent = 0 === selectedLabels.length ? summary.dataset.placeholder : ( 1 === selectedLabels.length ? selectedLabels[ 0 ] : `${ summary.dataset.placeholder } · ${ selectedLabels.length }` );
	}
}

function focusControl( key, value = '' ) {
	if ( ! key ) {
		return;
	}
	const controls = Array.from( document.querySelectorAll( `${ formsSelector } [name]` ) ).filter( ( candidate ) => normalizeName( candidate.name ) === key && candidate.type !== 'hidden' && ! candidate.disabled );
	if ( key === 'location' ) {
		const menu = controls[ 0 ]?.closest( '[data-llamahire-location-menu]' );
		if ( menu ) {
			menu.open = true;
			const search = menu.querySelector( '[data-llamahire-location-search]' );
			resetLocationSearch( menu );
			search?.focus( { preventScroll: true } );
			return;
		}
	}
	const control = ( value ? controls.find( ( candidate ) => candidate.value === value ) : null ) || controls[ 0 ];
	if ( control ) {
		control.focus( { preventScroll: true } );
	}
}

function normalizeName( name ) {
	return name ? name.replace( /\[\]$/, '' ) : '';
}

function selectedValues( url, key ) {
	const canonical = ( url.searchParams.get( key ) || '' ).split( key === 'location' ? '|' : ',' );
	return [ ...new Set( canonical.concat( url.searchParams.getAll( `${ key }[]` ) ).filter( Boolean ).map( ( value ) => value.toLowerCase() ) ) ];
}

function filterLocationOptions( search ) {
	const menu = search.closest( '[data-llamahire-location-menu]' );
	if ( ! menu ) {
		return;
	}
	const query = search.value.trim().toLocaleLowerCase();
	const options = Array.from( menu.querySelectorAll( '[data-llamahire-location-option]' ) );
	let matches = 0;
	options.forEach( ( option ) => {
		const visible = ! query || option.textContent.toLocaleLowerCase().includes( query );
		option.hidden = ! visible;
		if ( visible ) {
			matches++;
		}
	} );
	const empty = menu.querySelector( '[data-llamahire-location-empty]' );
	if ( empty ) {
		empty.hidden = matches > 0;
	}
	const status = menu.querySelector( '[data-llamahire-location-status]' );
	if ( status ) {
		status.textContent = `${ matches } ${ matches === 1 ? status.dataset.singular : status.dataset.plural }`;
	}
}

function resetLocationSearch( menu ) {
	const search = menu?.querySelector( '[data-llamahire-location-search]' );
	if ( search ) {
		search.value = '';
		filterLocationOptions( search );
	}
}

function shouldHandleLink( event, link ) {
	return event.button === 0 && ! event.metaKey && ! event.ctrlKey && ! event.altKey && ! event.shiftKey && ! event.defaultPrevented && link instanceof window.HTMLAnchorElement && ( ! link.target || link.target === '_self' ) && link.origin === window.location.origin;
}

window.addEventListener( 'popstate', () => syncControls( window.location.href ) );
document.addEventListener( 'click', ( event ) => {
	document.querySelectorAll( '.llamahire-filter-menu[open]' ).forEach( ( menu ) => {
		if ( ! menu.contains( event.target ) ) {
			menu.removeAttribute( 'open' );
		}
	} );
} );
document.addEventListener( 'input', ( event ) => {
	if ( event.target.matches( '[data-llamahire-location-search]' ) ) {
		filterLocationOptions( event.target );
	}
} );
document.addEventListener( 'keydown', ( event ) => {
	if ( event.target.matches( '[data-llamahire-location-search]' ) && ( event.key === 'Enter' || event.key === 'ArrowDown' ) ) {
		const firstOption = Array.from( event.target.closest( '[data-llamahire-location-menu]' ).querySelectorAll( '[data-llamahire-location-option]' ) ).find( ( option ) => ! option.hidden && ( event.key === 'ArrowDown' || ! option.querySelector( 'input[type="checkbox"]' ).checked ) );
		if ( firstOption ) {
			event.preventDefault();
			const checkbox = firstOption.querySelector( 'input[type="checkbox"]' );
			if ( event.key === 'Enter' ) {
				checkbox.click();
			} else {
				checkbox.focus();
			}
			return;
		}
	}
	if ( event.key !== 'Escape' ) {
		return;
	}
	const menu = document.querySelector( '.llamahire-filter-menu[open]' );
	if ( menu ) {
		menu.removeAttribute( 'open' );
		menu.querySelector( 'summary' )?.focus();
	}
} );
