import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, SelectControl, Spinner } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { DataViews } from '@wordpress/dataviews/wp';
import { createRoot, Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { useView } from './wordpress-views';
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

const STATUS_ACTION_ICON = (
	<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
		<path d="M5 7h10.25l-2.6-2.6 1.1-1.1 4.5 4.45-4.5 4.45-1.1-1.1 2.6-2.6H5V7Zm14 10H8.75l2.6 2.6-1.1 1.1-4.5-4.45 4.5-4.45 1.1 1.1-2.6 2.6H19V17Z" />
	</svg>
);

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

const DEFAULT_FIELDS = [ 'job', 'status', 'notification_status', 'received' ];
const DEFAULT_SORT = {
	field: 'received',
	direction: 'desc',
};
const DEFAULT_LAYOUTS = {
	table: { density: 'balanced' },
	list: { density: 'balanced' },
};

const defaultView = () => ( {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: DEFAULT_SORT,
	titleField: 'candidate',
	fields: DEFAULT_FIELDS,
	layout: { density: 'balanced' },
} );

const initialQueryView = () => {
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
		search: params.get( 's' ) || config.initialSearch || '',
		filters,
		page: Math.max( 1, Number( params.get( 'paged' ) || 1 ) ),
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
	if ( query.orderby !== 'received' ) {
		url.searchParams.set( 'orderby', query.orderby );
	}
	if ( query.order !== 'desc' ) {
		url.searchParams.set( 'order', query.order );
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

function BulkStatusModal( { items, closeModal, onComplete } ) {
	const [ destination, setDestination ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const destinationLabel = labelFor( STATUS_OPTIONS, destination );
	const changedCount = destination
		? items.filter( ( item ) => item.status !== destination ).length
		: items.length;

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! destination || isSaving ) {
			return;
		}
		setIsSaving( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: config.bulkStatusPath,
				method: 'POST',
				data: {
					application_ids: items.map( ( item ) => item.id ),
					status: destination,
				},
			} );
			onComplete( response, destination );
			closeModal();
		} catch ( requestError ) {
			setError(
				requestError?.message ||
					__( 'The selected applications could not be updated.', 'llamahire' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<form className="llamahire-bulk-status" onSubmit={ submit }>
			<SelectControl
				label={ __( 'Move selected candidates to', 'llamahire' ) }
				value={ destination }
				options={ [
					{ value: '', label: __( 'Choose a status', 'llamahire' ) },
					...STATUS_OPTIONS,
				] }
				onChange={ setDestination }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ destination && (
				<div className="llamahire-bulk-status__confirmation" role="status">
					<p>
						{ sprintf(
							/* translators: 1: Number of candidates that will change. 2: Destination status. */
							_n(
								'%1$d candidate will move to %2$s.',
								'%1$d candidates will move to %2$s.',
								changedCount,
								'llamahire'
							),
							changedCount,
							destinationLabel
						) }
					</p>
					{ changedCount < items.length && (
						<p>
							{ sprintf(
								/* translators: %d: Number of selected candidates already in the destination status. */
								_n(
									'%d selected candidate is already there and will not change.',
									'%d selected candidates are already there and will not change.',
									items.length - changedCount,
									'llamahire'
								),
								items.length - changedCount
							) }
						</p>
					) }
				</div>
			) }
			{ error && (
				<div className="notice notice-error inline" role="alert">
					<p>{ error }</p>
				</div>
			) }
			<div className="llamahire-bulk-status__actions">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isSaving }>
					{ __( 'Cancel', 'llamahire' ) }
				</Button>
				<Button
					variant="primary"
					type="submit"
					disabled={ ! destination || isSaving }
					isBusy={ isSaving }
				>
					{ isSaving
						? __( 'Updating…', 'llamahire' )
						: __( 'Confirm status change', 'llamahire' ) }
				</Button>
			</div>
		</form>
	);
}

function SortButton( { field, label, view, onChangeView } ) {
	const isActive = view.sort?.field === field;
	const direction = isActive ? view.sort.direction : 'asc';
	const nextDirection = isActive && direction === 'asc' ? 'desc' : 'asc';
	return (
		<button
			type="button"
			className={ `llamahire-applications-table__sort${
				isActive ? ' is-active' : ''
			}` }
			onClick={ () =>
				onChangeView( {
					...view,
					page: 1,
					sort: { field, direction: nextDirection },
				} )
			}
		>
			<span>{ label }</span>
			{ isActive && (
				<span
					className={ `dashicons dashicons-arrow-${
						direction === 'asc' ? 'up' : 'down'
					}-alt2` }
					aria-hidden="true"
				/>
			) }
		</button>
	);
}

function ApplicationReview( { item, onClose, onUpdated } ) {
	const [ detail, setDetail ] = useState( null );
	const [ status, setStatus ] = useState( item.status );
	const [ newNote, setNewNote ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isAddingNote, setIsAddingNote ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ feedback, setFeedback ] = useState( { message: '', id: 0 } );
	const [ isCoverLetterOpen, setIsCoverLetterOpen ] = useState( false );
	const [ isActivityOpen, setIsActivityOpen ] = useState( false );
	const [ isNotesOpen, setIsNotesOpen ] = useState( false );
	const reviewId = `llamahire-application-review-${ item.id }`;

	useEffect( () => {
		const controller = new AbortController();
		setIsLoading( true );
		setError( '' );
		apiFetch( {
			path: `${ config.apiPath }/${ item.id }`,
			signal: controller.signal,
		} )
			.then( ( response ) => {
				setDetail( response );
				setStatus( response.status );
			} )
			.catch( ( requestError ) => {
				if ( requestError?.name !== 'AbortError' ) {
					setError(
						requestError?.message ||
							__( 'The application review could not be loaded.', 'llamahire' )
					);
				}
			} )
			.finally( () => setIsLoading( false ) );
		return () => controller.abort();
	}, [ item.id ] );

	const saveReview = async ( event ) => {
		event.preventDefault();
		if ( isSaving ) {
			return;
		}
		setIsSaving( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: `${ config.apiPath }/${ item.id }`,
				method: 'POST',
				data: { status },
			} );
			setDetail( response );
			setStatus( response.status );
			setFeedback( ( current ) => ( {
				message: __( 'Status saved.', 'llamahire' ),
				id: current.id + 1,
			} ) );
			onUpdated( response );
		} catch ( requestError ) {
			setError(
				requestError?.message ||
					__( 'The application review could not be saved.', 'llamahire' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const addNote = async ( event ) => {
		event.preventDefault();
		if ( isAddingNote || ! newNote.trim() ) {
			return;
		}
		setIsAddingNote( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: `${ config.apiPath }/${ item.id }/notes`,
				method: 'POST',
				data: { note: newNote },
			} );
			setDetail( response );
			setNewNote( '' );
			setFeedback( ( current ) => ( {
				message: __( 'Private note added.', 'llamahire' ),
				id: current.id + 1,
			} ) );
			onUpdated( response );
		} catch ( requestError ) {
			setError(
				requestError?.message ||
					__( 'The private note could not be added.', 'llamahire' )
			);
		} finally {
			setIsAddingNote( false );
		}
	};

	const materialCount = detail
		? Number( Boolean( detail.resume ) ) +
		  Number( Boolean( detail.cover_letter ) )
		: 0;
	const latestActivity = detail?.activity?.[ 0 ];
	const latestNote = detail?.private_notes?.[ 0 ];

	return (
		<section
			id={ reviewId }
			className="llamahire-inline-review"
			aria-label={ sprintf(
				/* translators: %s: Candidate name. */
				__( 'Review %s', 'llamahire' ),
				item.candidate
			) }
			tabIndex="-1"
		>
			<div className="llamahire-inline-review__header">
				<Button variant="link" onClick={ onClose }>
					{ __( 'Collapse review', 'llamahire' ) }
				</Button>
			</div>
			{ isLoading && (
				<div className="llamahire-inline-review__loading" role="status">
					<Spinner />
					<span>{ __( 'Loading application review…', 'llamahire' ) }</span>
				</div>
			) }
			{ error && (
				<div className="notice notice-error inline" role="alert">
					<p>{ error }</p>
				</div>
			) }
			{ detail && (
				<div className="llamahire-inline-review__body">
					<div className="llamahire-inline-review__summary">
						<section className="llamahire-inline-review__status">
							<h3>{ __( 'Status', 'llamahire' ) }</h3>
							{ config.canManage ? (
								<form onSubmit={ saveReview } className="llamahire-inline-review__status-form">
									<SelectControl
										hideLabelFromVision
										label={ __( 'Status', 'llamahire' ) }
										value={ status }
										options={ STATUS_OPTIONS }
										onChange={ setStatus }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
									/>
									<Button
										variant="secondary"
										type="submit"
										isBusy={ isSaving }
										disabled={ isSaving }
									>
										{ isSaving ? __( 'Saving…', 'llamahire' ) : __( 'Save status', 'llamahire' ) }
									</Button>
								</form>
							) : (
								<p>{ labelFor( STATUS_OPTIONS, status ) }</p>
							) }
						</section>
						<section className="llamahire-inline-review__latest-activity">
							<h3>{ __( 'Latest activity', 'llamahire' ) }</h3>
							{ latestActivity ? (
								<div className="llamahire-inline-review__latest-entry">
									<div>
										<strong>{ latestActivity.event }</strong>
										<span>{ latestActivity.occurred } · { latestActivity.actor }</span>
									</div>
									<Button variant="link" onClick={ () => setIsActivityOpen( true ) }>
										{ __( 'View all activity', 'llamahire' ) }
									</Button>
								</div>
							) : (
								<p className="llamahire-inline-review__empty">{ __( 'No activity recorded yet.', 'llamahire' ) }</p>
							) }
						</section>
					</div>
					<div className="llamahire-inline-review__confirmation" role="status" aria-live="polite" aria-atomic="true">
						<span key={ feedback.id }>{ feedback.message || '\u00a0' }</span>
					</div>
					<div className="llamahire-inline-review__content">
					<section className="llamahire-inline-review__materials">
						<h3>{ __( 'Application materials', 'llamahire' ) }</h3>
						{ materialCount ? (
							<ul className="llamahire-materials-list">
								{ detail.resume && (
									<li>
										<span
											className={ `dashicons ${
												detail.resume.type === 'PDF'
													? 'dashicons-pdf'
													: 'dashicons-media-document'
											}` }
											aria-hidden="true"
										/>
										<span className="llamahire-materials-list__label">
											<strong>{ detail.resume.name }</strong>
											<small>{ detail.resume.type }</small>
										</span>
										<span className="llamahire-materials-list__actions">
											{ detail.resume.preview_url && (
												<a
													href={ detail.resume.preview_url }
													target="_blank"
													rel="noopener noreferrer"
													aria-label={ sprintf(
														/* translators: %s: File name. */
														__( 'View %s', 'llamahire' ),
														detail.resume.name
													) }
												>
													{ __( 'View', 'llamahire' ) }
												</a>
											) }
											<a
												href={ detail.resume.download_url }
												aria-label={ sprintf(
													/* translators: %s: File name. */
													__( 'Download %s', 'llamahire' ),
													detail.resume.name
												) }
											>
												{ __( 'Download', 'llamahire' ) }
											</a>
										</span>
									</li>
								) }
								{ detail.cover_letter && (
									<li>
										<span
											className="dashicons dashicons-media-text"
											aria-hidden="true"
										/>
										<span className="llamahire-materials-list__label">
											<strong>{ __( 'Cover letter', 'llamahire' ) }</strong>
											<small>{ __( 'Text response', 'llamahire' ) }</small>
										</span>
										<span className="llamahire-materials-list__actions">
											<Button
												variant="link"
												onClick={ () => setIsCoverLetterOpen( true ) }
											>
												{ __( 'View', 'llamahire' ) }
											</Button>
										</span>
									</li>
								) }
							</ul>
						) : (
							<p className="llamahire-inline-review__empty">
								{ __( 'No application materials were provided.', 'llamahire' ) }
							</p>
						) }
					</section>
					<section className="llamahire-inline-review__notes">
						<h3>{ __( 'Notes', 'llamahire' ) }</h3>
						{ config.canManage ? (
							<form onSubmit={ addNote } className="llamahire-inline-review__note-composer">
								<label>
									<span>{ __( 'Add private note', 'llamahire' ) }</span>
									<textarea
										rows="3"
										value={ newNote }
										onChange={ ( event ) => setNewNote( event.target.value ) }
										maxLength={ config.noteMaxLength || 5000 }
										placeholder={ __( 'Add a private note…', 'llamahire' ) }
									/>
								</label>
								<div className="llamahire-inline-review__save">
									<Button
										variant="primary"
										type="submit"
										isBusy={ isAddingNote }
										disabled={ isAddingNote || ! newNote.trim() }
									>
										{ isAddingNote
											? __( 'Adding…', 'llamahire' )
											: __( 'Add note', 'llamahire' ) }
									</Button>
								</div>
							</form>
						) : null }
						<div className="llamahire-inline-review__latest-note">
							<h4>{ __( 'Latest note', 'llamahire' ) }</h4>
							{ latestNote ? (
								<>
									<div className="llamahire-note-card">
										<p>{ latestNote.body }</p>
										<small>{ latestNote.author } · { latestNote.created }</small>
									</div>
									<Button variant="link" onClick={ () => setIsNotesOpen( true ) }>
										{ __( 'View all notes', 'llamahire' ) }
									</Button>
								</>
							) : (
								<p className="llamahire-inline-review__empty">{ __( 'No private notes yet.', 'llamahire' ) }</p>
							) }
						</div>
					</section>
					</div>
				</div>
			) }
			{ isCoverLetterOpen && detail?.cover_letter && (
				<Modal
					title={ __( 'Cover letter', 'llamahire' ) }
					onRequestClose={ () => setIsCoverLetterOpen( false ) }
					className="llamahire-cover-letter-modal"
				>
					<p className="llamahire-cover-letter-modal__content">
						{ detail.cover_letter }
					</p>
				</Modal>
			) }
			{ isActivityOpen && detail?.activity?.length > 0 && (
				<Modal
					title={ __( 'Activity history', 'llamahire' ) }
					onRequestClose={ () => setIsActivityOpen( false ) }
					className="llamahire-history-modal"
				>
					<ol className="llamahire-history-list">
						{ detail.activity.map( ( event ) => (
							<li key={ event.id }><strong>{ event.event }</strong><span>{ event.occurred } · { event.actor }</span></li>
						) ) }
					</ol>
				</Modal>
			) }
			{ isNotesOpen && detail?.private_notes?.length > 0 && (
				<Modal
					title={ __( 'Private notes', 'llamahire' ) }
					onRequestClose={ () => setIsNotesOpen( false ) }
					className="llamahire-history-modal"
				>
					<ol className="llamahire-history-list llamahire-history-list--notes">
						{ detail.private_notes.map( ( note ) => (
							<li key={ note.id }><p>{ note.body }</p><span>{ note.author } · { note.created }</span></li>
						) ) }
					</ol>
				</Modal>
			) }
		</section>
	);
}

function ApplicationsTable( {
	items,
	view,
	onChangeView,
	selection,
	onChangeSelection,
	expandedId,
	onToggleReview,
	onReviewUpdated,
	isLoading,
	hasFilters,
} ) {
	const visibleFields = view.fields || defaultView().fields;
	const columns = [
		{ id: 'job', label: __( 'Job', 'llamahire' ) },
		{ id: 'status', label: __( 'Status', 'llamahire' ) },
		{ id: 'notification_status', label: __( 'Email status', 'llamahire' ) },
		{ id: 'received', label: __( 'Received', 'llamahire' ) },
	].filter( ( column ) => visibleFields.includes( column.id ) );
	const itemIds = items.map( ( item ) => String( item.id ) );
	const allSelected = Boolean(
		itemIds.length && itemIds.every( ( id ) => selection.includes( id ) )
	);
	const someSelected = itemIds.some( ( id ) => selection.includes( id ) );
	const columnCount = columns.length + 3;
	const density = view.layout?.density || 'balanced';

	const toggleAll = () => {
		if ( allSelected ) {
			onChangeSelection(
				selection.filter( ( id ) => ! itemIds.includes( id ) )
			);
			return;
		}
		onChangeSelection( Array.from( new Set( [ ...selection, ...itemIds ] ) ) );
	};

	const toggleOne = ( id ) => {
		onChangeSelection(
			selection.includes( id )
				? selection.filter( ( selectedId ) => selectedId !== id )
				: [ ...selection, id ]
		);
	};

	return (
		<div
			className={ `llamahire-applications-table llamahire-applications-table--${ density }` }
			aria-busy={ isLoading }
		>
			<table>
				<thead>
					<tr>
						<th className="llamahire-applications-table__check">
							<input
								type="checkbox"
								checked={ allSelected }
								ref={ ( input ) => {
									if ( input ) {
										input.indeterminate = someSelected && ! allSelected;
									}
								} }
								onChange={ toggleAll }
								aria-label={ allSelected ? __( 'Deselect all', 'llamahire' ) : __( 'Select all', 'llamahire' ) }
							/>
						</th>
						<th>
							<SortButton
								field="candidate"
								label={ __( 'Candidate', 'llamahire' ) }
								view={ view }
								onChangeView={ onChangeView }
							/>
						</th>
						{ columns.map( ( column ) => (
							<th key={ column.id }>
								<SortButton
									field={ column.id }
									label={ column.label }
									view={ view }
									onChangeView={ onChangeView }
								/>
							</th>
						) ) }
						<th className="llamahire-applications-table__actions">
							{ __( 'Actions', 'llamahire' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => {
						const id = String( item.id );
						const isExpanded = expandedId === id;
						return (
							<Fragment key={ id }>
								<tr className={ isExpanded ? 'is-expanded' : '' }>
									<td className="llamahire-applications-table__check">
										<input
											type="checkbox"
											checked={ selection.includes( id ) }
											onChange={ () => toggleOne( id ) }
											aria-label={ item.candidate }
										/>
									</td>
									<td>
										<div className="llamahire-dataviews-candidate">
											<a
												className="llamahire-dataviews-primary"
												href={ item.detail_url }
												data-llamahire-review-trigger={ id }
												onClick={ ( event ) => {
													event.preventDefault();
													onToggleReview( item, event.currentTarget );
												} }
											>
												{ item.candidate }
											</a>
											<a href={ `mailto:${ item.email }` }>{ item.email }</a>
										</div>
									</td>
									{ columns.map( ( column ) => (
										<td key={ column.id }>
											{ column.id === 'job' &&
												( item.job_edit_url ? (
													<a href={ item.job_edit_url }>{ item.job }</a>
												) : (
													item.job
												) ) }
											{ column.id === 'status' && (
												<span className={ `llamahire-status-badge llamahire-status-badge--${ item.status }` }>
													{ labelFor( STATUS_OPTIONS, item.status ) }
												</span>
											) }
											{ column.id === 'notification_status' && (
												<span className={ `llamahire-status-badge llamahire-status-badge--email-${ item.notification_status }` }>
													{ labelFor( NOTIFICATION_OPTIONS, item.notification_status ) }
												</span>
											) }
											{ column.id === 'received' && item.received_display }
										</td>
									) ) }
									<td className="llamahire-applications-table__actions">
										<Button
											variant="tertiary"
											className="llamahire-applications-table__review-button"
											data-llamahire-review-trigger={ id }
											label={
												isExpanded
													? __( 'Collapse application review', 'llamahire' )
													: __( 'Review application', 'llamahire' )
											}
											onClick={ ( event ) =>
												onToggleReview( item, event.currentTarget )
											}
											aria-expanded={ isExpanded }
											aria-controls={ `llamahire-application-review-${ item.id }` }
										>
											<span
												className={ `dashicons dashicons-arrow-${
													isExpanded ? 'up' : 'down'
												}-alt2` }
												aria-hidden="true"
											/>
										</Button>
									</td>
								</tr>
								{ isExpanded && (
									<tr className="llamahire-applications-table__expanded-row">
										<td colSpan={ columnCount }>
											<ApplicationReview
												item={ item }
												onClose={ () => onToggleReview( item ) }
												onUpdated={ onReviewUpdated }
											/>
										</td>
									</tr>
								) }
							</Fragment>
						);
					} ) }
					{ ! items.length && (
						<tr>
							<td colSpan={ columnCount } className="llamahire-applications-table__empty">
								{ isLoading ? (
									<Spinner />
								) : hasFilters ? (
									__( 'No applications match this view.', 'llamahire' )
								) : (
									__( 'No applications yet.', 'llamahire' )
								) }
							</td>
						</tr>
					) }
				</tbody>
			</table>
		</div>
	);
}

function ApplicationsDataView() {
	const [ queryView, setQueryView ] = useState( initialQueryView );
	const defaultApplicationsView = useMemo( defaultView, [] );
	const activeViewOverrides = useMemo(
		() => ( {
			filters: queryView.filters,
			sort: queryView.sort,
		} ),
		[ queryView.filters, queryView.sort ]
	);
	const {
		view,
		isModified: hasSavedView,
		updateView: updateSavedView,
		resetToDefault: resetSavedView,
	} = useView( {
		kind: 'llamahire',
		name: 'applications',
		slug: 'inbox',
		defaultView: defaultApplicationsView,
		defaultLayouts: DEFAULT_LAYOUTS,
		activeViewOverrides,
		queryParams: {
			page: queryView.page,
			search: queryView.search,
		},
	} );
	const [ result, setResult ] = useState( {
		items: [],
		total: 0,
		pages: 1,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ success, setSuccess ] = useState( '' );
	const [ selection, setSelection ] = useState( [] );
	const [ expandedId, setExpandedId ] = useState( '' );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const reviewTrigger = useRef( null );
	const query = useMemo( () => queryFromView( view ), [ view ] );
	const serializedQuery = JSON.stringify( query );
	const hasQueryChanges = Boolean(
		queryView.search ||
			queryView.filters.length ||
			queryView.page > 1 ||
			queryView.sort.field !== DEFAULT_SORT.field ||
			queryView.sort.direction !== DEFAULT_SORT.direction
	);
	const resetView = () => {
		resetSavedView();
		setQueryView( {
			search: '',
			filters: [],
			page: 1,
			sort: DEFAULT_SORT,
		} );
	};

	const setView = ( nextViewOrUpdater ) => {
		const nextView =
			typeof nextViewOrUpdater === 'function'
				? nextViewOrUpdater( view )
				: nextViewOrUpdater;
		setQueryView( {
			search: nextView.search || '',
			filters: nextView.filters || [],
			page: Math.max( 1, Number( nextView.page || 1 ) ),
			sort: nextView.sort || DEFAULT_SORT,
		} );
		updateSavedView( {
			...nextView,
			search: queryView.search,
			filters: [],
			page: queryView.page,
			sort: DEFAULT_SORT,
		} );
	};

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
					setExpandedId( ( current ) =>
						response.items.some( ( item ) => String( item.id ) === current )
							? current
							: ''
					);
					setSelection( ( current ) =>
						current.filter( ( id ) =>
							response.items.some( ( item ) => String( item.id ) === id )
						)
					);
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
	}, [ serializedQuery, refreshKey ] );

	useEffect( () => {
		if ( ! expandedId && ! reviewTrigger.current ) {
			return undefined;
		}
		const focusFrame = window.requestAnimationFrame( () => {
			if ( expandedId ) {
				document
					.getElementById( `llamahire-application-review-${ expandedId }` )
					?.focus();
				return;
			}
			if ( reviewTrigger.current?.isConnected ) {
				reviewTrigger.current.focus();
			}
			reviewTrigger.current = null;
		} );
		return () => window.cancelAnimationFrame( focusFrame );
	}, [ expandedId ] );

	const bulkStatusComplete = ( response, destination ) => {
		const destinationLabel = labelFor( STATUS_OPTIONS, destination );
		setResult( ( current ) => ( {
			...current,
			items: current.items.map( ( item ) =>
				response.application_ids.includes( item.id )
					? { ...item, status: destination }
					: item
			),
		} ) );
		setSuccess(
			response.updated
				? sprintf(
						/* translators: 1: Number of updated candidates. 2: Destination status. */
						_n(
							'%1$d candidate moved to %2$s.',
							'%1$d candidates moved to %2$s.',
							response.updated,
							'llamahire'
						),
						response.updated,
						destinationLabel
				  )
				: __( 'The selected candidates were already in that status.', 'llamahire' )
		);
		setRefreshKey( ( current ) => current + 1 );
	};

	const toggleReview = ( item, trigger ) => {
		const id = String( item.id );
		if ( expandedId !== id ) {
			if ( trigger instanceof window.HTMLElement ) {
				reviewTrigger.current = trigger;
			} else if ( document.activeElement instanceof window.HTMLElement ) {
				reviewTrigger.current = document.activeElement;
			}
		}
		setExpandedId( ( current ) => ( current === id ? '' : id ) );
	};

	const reviewUpdated = ( application ) => {
		setResult( ( current ) => ( {
			...current,
			items: current.items.map( ( item ) =>
				item.id === application.id
					? { ...item, status: application.status }
					: item
			),
		} ) );
	};

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
	const expandedItem = result.items.find(
		( item ) => String( item.id ) === expandedId
	);
	const actions = [
		{
			id: 'review',
			label: __( 'Review application', 'llamahire' ),
			isPrimary: true,
			callback: ( [ item ] ) => toggleReview( item ),
		},
		...( config.canManage
			? [
					{
						id: 'change-status',
						label: __( 'Change status', 'llamahire' ),
						modalHeader: ( items ) =>
							sprintf(
								/* translators: %d: Number of selected candidates. */
								_n(
									'Change status for %d candidate',
									'Change status for %d candidates',
									items.length,
									'llamahire'
								),
								items.length
							),
						icon: STATUS_ACTION_ICON,
						supportsBulk: true,
						RenderModal: ( modalProps ) => (
							<BulkStatusModal
								{ ...modalProps }
								onComplete={ bulkStatusComplete }
							/>
						),
					},
			  ]
			: [] ),
	];

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
			{ success && (
				<div className="notice notice-success inline is-dismissible" role="status">
					<p>{ success }</p>
					<Button
						className="notice-dismiss"
						label={ __( 'Dismiss this notice.', 'llamahire' ) }
						onClick={ () => setSuccess( '' ) }
					/>
				</div>
			) }
			<DataViews
				data={ result.items }
				getItemId={ ( item ) => String( item.id ) }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				defaultLayouts={ DEFAULT_LAYOUTS }
				paginationInfo={ {
					totalItems: result.total,
					totalPages: result.pages,
				} }
				isLoading={ isLoading }
				selection={ selection }
				onChangeSelection={ setSelection }
				searchLabel={ __( 'Search candidates', 'llamahire' ) }
				config={ { perPageSizes: [ 10, 20, 50, 100 ] } }
				empty={
					<p>
						{ hasFilters
							? __( 'No applications match this view.', 'llamahire' )
							: __( 'No applications yet.', 'llamahire' ) }
					</p>
				}
				onReset={ hasSavedView || hasQueryChanges ? resetView : false }
				actions={ actions }
				onClickItem={ toggleReview }
			>
				<div className="llamahire-dataviews-toolbar">
					<div className="llamahire-dataviews-toolbar__search">
						<DataViews.Search label={ __( 'Search candidates', 'llamahire' ) } />
						<DataViews.FiltersToggle />
					</div>
					<div className="llamahire-dataviews-toolbar__view">
						<DataViews.LayoutSwitcher />
						<DataViews.ViewConfig />
					</div>
				</div>
				<DataViews.FiltersToggled />
				{ view.type === 'table' ? (
					<ApplicationsTable
						items={ result.items }
						view={ view }
						onChangeView={ setView }
						selection={ selection }
						onChangeSelection={ setSelection }
						expandedId={ expandedId }
						onToggleReview={ toggleReview }
						onReviewUpdated={ reviewUpdated }
						isLoading={ isLoading }
						hasFilters={ hasFilters }
					/>
				) : (
					<>
						<DataViews.Layout />
						{ expandedItem && (
							<div className="llamahire-list-review">
								<ApplicationReview
									item={ expandedItem }
									onClose={ () => toggleReview( expandedItem ) }
									onUpdated={ reviewUpdated }
								/>
							</div>
						) }
					</>
				) }
				<DataViews.Footer />
			</DataViews>
		</>
	);
}

domReady( () => {
	const root = document.getElementById( 'llamahire-applications-root' );
	if ( root ) {
		createRoot( root ).render( <ApplicationsDataView /> );
	}
} );
