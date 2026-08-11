import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { DataViews } from '@wordpress/dataviews/wp';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import './admin-activity.scss';

const config = window.llamahireActivity || {};

const valueArray = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value;
	}
	return value === undefined || value === null || value === ''
		? []
		: [ value ];
};

const getFilter = ( view, field ) =>
	( view.filters || [] ).find( ( filter ) => filter.field === field );

const preferredLayout = () =>
	window.matchMedia( '(max-width: 782px)' ).matches ? 'list' : 'table';

const defaultView = () => ( {
	type: preferredLayout(),
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: {
		field: 'occurred',
		direction: 'desc',
	},
	titleField: 'event',
	fields: [ 'job', 'subject', 'actor', 'occurred' ],
	layout: { density: 'balanced' },
} );

const initialView = () => {
	const params = new URLSearchParams( window.location.search );
	const filters = [];
	const eventTypes = ( params.get( 'event_types' ) || '' )
		.split( ',' )
		.filter( Boolean );
	const jobs = ( params.get( 'job_ids' ) || '' )
		.split( ',' )
		.map( Number )
		.filter( Boolean );
	const actors = ( params.get( 'actor_ids' ) || '' )
		.split( ',' )
		.map( Number )
		.filter( Boolean );
	const occurred = ( params.get( 'occurred' ) || '' )
		.split( ',' )
		.filter( Boolean );

	if ( eventTypes.length ) {
		filters.push( {
			field: 'event',
			operator: 'isAny',
			value: eventTypes,
		} );
	}
	if ( jobs.length ) {
		filters.push( { field: 'job', operator: 'isAny', value: jobs } );
	}
	if ( actors.length ) {
		filters.push( { field: 'actor', operator: 'isAny', value: actors } );
	}
	if ( params.get( 'occurred_operator' ) && occurred.length ) {
		filters.push( {
			field: 'occurred',
			operator: params.get( 'occurred_operator' ),
			value: occurred.length === 1 ? occurred[ 0 ] : occurred,
		} );
	}

	return {
		...defaultView(),
		type: [ 'table', 'list' ].includes( params.get( 'layout' ) )
			? params.get( 'layout' )
			: preferredLayout(),
		search: params.get( 's' ) || '',
		filters,
		page: Math.max( 1, Number( params.get( 'paged' ) || 1 ) ),
		perPage: [ 10, 20, 50, 100 ].includes(
			Number( params.get( 'per_page' ) )
		)
			? Number( params.get( 'per_page' ) )
			: 20,
		sort: {
			field: [ 'event', 'job', 'occurred' ].includes(
				params.get( 'orderby' )
			)
				? params.get( 'orderby' )
				: 'occurred',
			direction: params.get( 'order' ) === 'asc' ? 'asc' : 'desc',
		},
	};
};

const queryFromView = ( view ) => {
	const event = getFilter( view, 'event' );
	const job = getFilter( view, 'job' );
	const actor = getFilter( view, 'actor' );
	const occurred = getFilter( view, 'occurred' );
	const query = {
		page: view.page || 1,
		per_page: view.perPage || 20,
		search: view.search || undefined,
		event_types: event ? valueArray( event.value ) : undefined,
		job_ids: job
			? valueArray( job.value ).map( Number ).filter( Boolean )
			: undefined,
		actor_ids: actor
			? valueArray( actor.value ).map( Number ).filter( Boolean )
			: undefined,
		occurred_operator: occurred?.operator,
		occurred: occurred ? valueArray( occurred.value ) : undefined,
		orderby: view.sort?.field || 'occurred',
		order: view.sort?.direction || 'desc',
	};
	return Object.fromEntries(
		Object.entries( query ).filter(
			( [ , value ] ) =>
				value !== undefined && ( ! Array.isArray( value ) || value.length )
		)
	);
};

const syncUrl = ( view ) => {
	const query = queryFromView( view );
	const url = new URL( config.baseUrl, window.location.origin );
	if ( query.search ) {
		url.searchParams.set( 's', query.search );
	}
	if ( query.event_types?.length ) {
		url.searchParams.set( 'event_types', query.event_types.join( ',' ) );
	}
	if ( query.job_ids?.length ) {
		url.searchParams.set( 'job_ids', query.job_ids.join( ',' ) );
	}
	if ( query.actor_ids?.length ) {
		url.searchParams.set( 'actor_ids', query.actor_ids.join( ',' ) );
	}
	if ( query.occurred_operator ) {
		url.searchParams.set( 'occurred_operator', query.occurred_operator );
	}
	if ( query.occurred?.length ) {
		url.searchParams.set( 'occurred', query.occurred.join( ',' ) );
	}
	if ( query.page > 1 ) {
		url.searchParams.set( 'paged', query.page );
	}
	if ( query.per_page !== 20 ) {
		url.searchParams.set( 'per_page', query.per_page );
	}
	if ( query.orderby !== 'occurred' ) {
		url.searchParams.set( 'orderby', query.orderby );
	}
	if ( query.order !== 'desc' ) {
		url.searchParams.set( 'order', query.order );
	}
	if ( view.type !== 'table' ) {
		url.searchParams.set( 'layout', view.type );
	}
	window.history.replaceState( {}, '', url );
};

const fields = [
	{
		id: 'event',
		type: 'text',
		label: __( 'Event', 'llamahire' ),
		elements: config.events || [],
		enableHiding: false,
		enableGlobalSearch: true,
		filterBy: { operators: [ 'isAny' ] },
		getValue: ( { item } ) => item.event_type,
		render: ( { item } ) =>
			item.target_url ? (
				<a className="llamahire-activity-primary" href={ item.target_url }>
					{ item.event }
				</a>
			) : (
				<span className="llamahire-activity-primary">{ item.event }</span>
			),
	},
	{
		id: 'job',
		type: 'integer',
		label: __( 'Job', 'llamahire' ),
		elements: config.jobs || [],
		enableGlobalSearch: true,
		filterBy: { operators: [ 'isAny' ] },
		getValue: ( { item } ) => item.job_id,
		render: ( { item } ) =>
			item.job_edit_url ? (
				<a href={ item.job_edit_url }>{ item.job }</a>
			) : (
				item.job
			),
	},
	{
		id: 'subject',
		type: 'text',
		label: __( 'Subject', 'llamahire' ),
		enableSorting: false,
		filterBy: false,
		render: ( { item } ) =>
			item.subject_url ? (
				<a href={ item.subject_url }>{ item.subject }</a>
			) : (
				item.subject
			),
	},
	{
		id: 'actor',
		type: 'integer',
		label: __( 'Actor', 'llamahire' ),
		elements: config.actors || [],
		enableGlobalSearch: true,
		enableSorting: false,
		filterBy: { operators: [ 'isAny' ] },
		getValue: ( { item } ) => item.actor_id,
		render: ( { item } ) => item.actor,
	},
	{
		id: 'occurred',
		type: 'datetime',
		label: __( 'Date', 'llamahire' ),
		filterBy: {
			operators: [ 'on', 'beforeInc', 'afterInc', 'between' ],
		},
		render: ( { item } ) => item.occurred_display,
	},
];

function ActivityDataView() {
	const [ view, setView ] = useState( initialView );
	const [ result, setResult ] = useState( {
		items: [],
		total: 0,
		pages: 1,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const query = useMemo( () => queryFromView( view ), [ view ] );
	const serializedQuery = JSON.stringify( query );

	useEffect( () => {
		syncUrl( view );
		const controller = new AbortController();
		const timeout = window.setTimeout( () => {
			setIsLoading( true );
			setError( '' );
			apiFetch( {
				path: addQueryArgs( config.apiPath, query ),
				signal: controller.signal,
			} )
				.then( ( response ) => {
					setResult( response );
					if ( response.pages && view.page > response.pages ) {
						setView( ( current ) => ( {
							...current,
							page: response.pages,
						} ) );
					}
				} )
				.catch( ( requestError ) => {
					if ( requestError?.name !== 'AbortError' ) {
						setError(
							requestError?.message ||
								__( 'Activity could not be loaded.', 'llamahire' )
						);
					}
				} )
				.finally( () => setIsLoading( false ) );
		}, 200 );
		return () => {
			window.clearTimeout( timeout );
			controller.abort();
		};
	}, [ serializedQuery ] );

	const hasFilters = Boolean(
		view.search || ( view.filters && view.filters.length )
	);
	const resultText = isLoading
		? __( 'Loading activity…', 'llamahire' )
		: sprintf(
				/* translators: %s: Number of matching activity events. */
				__( '%s matching events', 'llamahire' ),
				result.total
		  );

	return (
		<>
			<div className="llamahire-activity-summary">
				<p role="status" aria-live="polite">
					{ resultText }
				</p>
			</div>
			{ error && (
				<div className="notice notice-error inline" role="alert">
					<p>{ error }</p>
				</div>
			) }
			<DataViews
				data={ result.items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				defaultLayouts={ { table: {}, list: {} } }
				paginationInfo={ {
					totalItems: result.total,
					totalPages: result.pages,
				} }
				isLoading={ isLoading }
				searchLabel={ __( 'Search activity', 'llamahire' ) }
				config={ { perPageSizes: [ 10, 20, 50, 100 ] } }
				empty={
					<p>
						{ hasFilters
							? __( 'No activity matches this view.', 'llamahire' )
							: __( 'No activity recorded yet.', 'llamahire' ) }
					</p>
				}
				onReset={
					hasFilters ? () => setView( defaultView() ) : false
				}
			/>
		</>
	);
}

domReady( () => {
	const root = document.getElementById( 'llamahire-activity-root' );
	if ( root ) {
		createRoot( root ).render( <ActivityDataView /> );
	}
} );
