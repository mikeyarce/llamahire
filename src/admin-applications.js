import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { DataViews } from '@wordpress/dataviews/wp';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import './admin-applications.scss';

const config = window.llamahireApplications || {};

const STATUS_OPTIONS = [
	{ value: 'new', label: __( 'New', 'llamahire' ) },
	{ value: 'reviewing', label: __( 'Reviewing', 'llamahire' ) },
	{ value: 'interviewing', label: __( 'Interviewing', 'llamahire' ) },
	{ value: 'offer', label: __( 'Offer', 'llamahire' ) },
	{ value: 'rejected', label: __( 'Rejected', 'llamahire' ) },
	{ value: 'hired', label: __( 'Hired', 'llamahire' ) },
];

const NOTIFICATION_OPTIONS = [
	{ value: 'pending', label: __( 'Pending', 'llamahire' ) },
	{ value: 'sent', label: __( 'Sent', 'llamahire' ) },
	{ value: 'partial', label: __( 'Partial', 'llamahire' ) },
	{ value: 'failed', label: __( 'Failed', 'llamahire' ) },
];

const labelFor = ( options, value ) =>
	options.find( ( option ) => option.value === value )?.label || value;

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

const defaultView = () => ( {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: {
		field: 'received',
		direction: 'desc',
	},
	titleField: 'candidate',
	fields: [ 'job', 'status', 'notification_status', 'received' ],
	layout: { density: 'balanced' },
} );

const initialView = () => {
	const params = new URLSearchParams( window.location.search );
	const filters = [];
	const candidate = params.get( 'candidate' );
	const email = params.get( 'email' );
	const jobs = ( params.get( 'job_ids' ) || '' )
		.split( ',' )
		.map( Number )
		.filter( Boolean );
	const statuses = ( params.get( 'statuses' ) || '' ).split( ',' ).filter( Boolean );
	const notificationStatuses = (
		params.get( 'notification_statuses' ) || ''
	)
		.split( ',' )
		.filter( Boolean );
	const received = ( params.get( 'received' ) || '' ).split( ',' ).filter( Boolean );
	const legacyJob = Number( params.get( 'job_id' ) || config.initialJobId || 0 );
	const legacyStatus = params.get( 'status' ) || config.initialStatus;

	if ( candidate ) {
		filters.push( { field: 'candidate', operator: 'contains', value: candidate } );
	}
	if ( email ) {
		filters.push( { field: 'email', operator: 'contains', value: email } );
	}
	if ( jobs.length || legacyJob ) {
		filters.push( {
			field: 'job',
			operator: 'isAny',
			value: jobs.length ? jobs : [ legacyJob ],
		} );
	}
	if ( statuses.length || legacyStatus ) {
		filters.push( {
			field: 'status',
			operator: 'isAny',
			value: statuses.length ? statuses : [ legacyStatus ],
		} );
	}
	if ( notificationStatuses.length ) {
		filters.push( {
			field: 'notification_status',
			operator: 'isAny',
			value: notificationStatuses,
		} );
	}
	if ( params.get( 'received_operator' ) && received.length ) {
		filters.push( {
			field: 'received',
			operator: params.get( 'received_operator' ),
			value: received.length === 1 ? received[ 0 ] : received,
		} );
	}

	return {
		...defaultView(),
		type: [ 'table', 'list' ].includes( params.get( 'layout' ) )
			? params.get( 'layout' )
			: 'table',
		search: params.get( 's' ) || config.initialSearch || '',
		filters,
		page: Math.max( 1, Number( params.get( 'paged' ) || 1 ) ),
		perPage: [ 10, 20, 50, 100 ].includes(
			Number( params.get( 'per_page' ) )
		)
			? Number( params.get( 'per_page' ) )
			: 20,
		sort: {
			field: [
				'candidate',
				'job',
				'status',
				'notification_status',
				'received',
			].includes( params.get( 'orderby' ) )
				? params.get( 'orderby' )
				: 'received',
			direction: params.get( 'order' ) === 'asc' ? 'asc' : 'desc',
		},
	};
};

const queryFromView = ( view ) => {
	const candidate = getFilter( view, 'candidate' );
	const job = getFilter( view, 'job' );
	const email = getFilter( view, 'email' );
	const status = getFilter( view, 'status' );
	const notification = getFilter( view, 'notification_status' );
	const received = getFilter( view, 'received' );
	const query = {
		page: view.page || 1,
		per_page: view.perPage || 20,
		search: view.search || undefined,
		candidate: candidate?.value || undefined,
		email: email?.value || undefined,
		job_ids: job ? valueArray( job.value ).map( Number ).filter( Boolean ) : undefined,
		statuses: status ? valueArray( status.value ) : undefined,
		notification_statuses: notification
			? valueArray( notification.value )
			: undefined,
		received_operator: received?.operator,
		received: received ? valueArray( received.value ) : undefined,
		orderby: view.sort?.field || 'received',
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
	if ( query.candidate ) {
		url.searchParams.set( 'candidate', query.candidate );
	}
	if ( query.email ) {
		url.searchParams.set( 'email', query.email );
	}
	if ( query.job_ids?.length ) {
		url.searchParams.set( 'job_ids', query.job_ids.join( ',' ) );
	}
	if ( query.statuses?.length ) {
		url.searchParams.set( 'statuses', query.statuses.join( ',' ) );
	}
	if ( query.notification_statuses?.length ) {
		url.searchParams.set(
			'notification_statuses',
			query.notification_statuses.join( ',' )
		);
	}
	if ( query.received_operator ) {
		url.searchParams.set( 'received_operator', query.received_operator );
	}
	if ( query.received?.length ) {
		url.searchParams.set( 'received', query.received.join( ',' ) );
	}
	if ( query.page > 1 ) {
		url.searchParams.set( 'paged', query.page );
	}
	if ( query.per_page !== 20 ) {
		url.searchParams.set( 'per_page', query.per_page );
	}
	if ( query.orderby !== 'received' ) {
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
		id: 'candidate',
		type: 'text',
		label: __( 'Candidate', 'llamahire' ),
		enableHiding: false,
		enableGlobalSearch: true,
		filterBy: { operators: [ 'contains', 'startsWith', 'is' ] },
		render: ( { item } ) => (
			<div className="llamahire-dataviews-candidate">
				<a className="llamahire-dataviews-primary" href={ item.detail_url }>
					{ item.candidate }
				</a>
				<a href={ `mailto:${ item.email }` }>{ item.email }</a>
			</div>
		),
	},
	{
		id: 'email',
		type: 'email',
		label: __( 'Candidate email', 'llamahire' ),
		enableHiding: false,
		enableGlobalSearch: true,
		filterBy: { operators: [ 'contains', 'startsWith', 'is' ] },
	},
	{
		id: 'job',
		type: 'integer',
		label: __( 'Job', 'llamahire' ),
		elements: config.jobs || [],
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
		id: 'status',
		type: 'text',
		label: __( 'Status', 'llamahire' ),
		elements: STATUS_OPTIONS,
		filterBy: { operators: [ 'isAny' ] },
		render: ( { item } ) => (
			<span className={ `llamahire-status-badge llamahire-status-badge--${ item.status }` }>
				{ labelFor( STATUS_OPTIONS, item.status ) }
			</span>
		),
	},
	{
		id: 'notification_status',
		type: 'text',
		label: __( 'Email status', 'llamahire' ),
		elements: NOTIFICATION_OPTIONS,
		filterBy: { operators: [ 'isAny' ] },
		render: ( { item } ) => (
			<span
				className={ `llamahire-status-badge llamahire-status-badge--email-${ item.notification_status }` }
			>
				{ labelFor( NOTIFICATION_OPTIONS, item.notification_status ) }
			</span>
		),
	},
	{
		id: 'received',
		type: 'datetime',
		label: __( 'Received', 'llamahire' ),
		filterBy: {
			operators: [ 'on', 'beforeInc', 'afterInc', 'between' ],
		},
		render: ( { item } ) => item.received_display,
	},
];

function ApplicationsDataView() {
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
								__( 'Applications could not be loaded.', 'llamahire' )
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

	const exportQuery = { ...query };
	delete exportQuery.page;
	delete exportQuery.per_page;
	const exportUrl = addQueryArgs( config.exportUrl, exportQuery );
	const hasFilters = Boolean(
		view.search || ( view.filters && view.filters.length )
	);
	const resultText = isLoading
		? __( 'Loading applications…', 'llamahire' )
		: sprintf(
				/* translators: %s: Number of matching applications. */
				__( '%s matching applications', 'llamahire' ),
				result.total
		  );

	return (
		<>
			<div className="llamahire-dataviews-summary">
				<p role="status" aria-live="polite">
					{ resultText }
				</p>
				{ config.canExport && (
					<a className="button" href={ exportUrl }>
						{ hasFilters
							? __( 'Export filtered CSV', 'llamahire' )
							: __( 'Export CSV', 'llamahire' ) }
					</a>
				) }
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
				defaultLayouts={ {
					table: {},
					list: {},
				} }
				paginationInfo={ {
					totalItems: result.total,
					totalPages: result.pages,
				} }
				isLoading={ isLoading }
				searchLabel={ __( 'Search candidates', 'llamahire' ) }
				config={ { perPageSizes: [ 10, 20, 50, 100 ] } }
				empty={
					<p>
						{ hasFilters
							? __( 'No applications match this view.', 'llamahire' )
							: __( 'No applications yet.', 'llamahire' ) }
					</p>
				}
				onReset={
					hasFilters
						? () => setView( defaultView() )
						: false
				}
				actions={ [
					{
						id: 'review',
						label: __( 'Review application', 'llamahire' ),
						isPrimary: true,
						callback: ( [ item ] ) => {
							window.location.href = item.detail_url;
						},
					},
				] }
			/>
		</>
	);
}

domReady( () => {
	const root = document.getElementById( 'llamahire-applications-root' );
	if ( root ) {
		createRoot( root ).render( <ApplicationsDataView /> );
	}
} );
