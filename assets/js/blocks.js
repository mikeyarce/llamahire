( function ( blocks, element, components, blockEditor, data, i18n, ServerSideRender ) {
	'use strict';
	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var useSelect = data.useSelect;
	var __ = i18n.__;

	function cardControls( props, title ) {
		return el( InspectorControls, {}, el( PanelBody, { title: title },
			el( ToggleControl, { label: __( 'Show featured badge', 'llamahire' ), checked: props.attributes.showFeaturedBadge, onChange: function ( value ) { props.setAttributes( { showFeaturedBadge: value } ); } } ),
			el( ToggleControl, { label: __( 'Show excerpt', 'llamahire' ), checked: props.attributes.showExcerpt, onChange: function ( value ) { props.setAttributes( { showExcerpt: value } ); } } ),
			el( ToggleControl, { label: __( 'Show location', 'llamahire' ), checked: props.attributes.showLocation, onChange: function ( value ) { props.setAttributes( { showLocation: value } ); } } ),
			el( ToggleControl, { label: __( 'Show workplace', 'llamahire' ), checked: props.attributes.showWorkplace, onChange: function ( value ) { props.setAttributes( { showWorkplace: value } ); } } ),
			el( ToggleControl, { label: __( 'Show employment type', 'llamahire' ), checked: props.attributes.showEmploymentType, onChange: function ( value ) { props.setAttributes( { showEmploymentType: value } ); } } ),
			el( ToggleControl, { label: __( 'Show salary', 'llamahire' ), checked: props.attributes.showSalary, onChange: function ( value ) { props.setAttributes( { showSalary: value } ); } } ),
			el( TextControl, { label: __( 'Link label', 'llamahire' ), value: props.attributes.linkLabel, onChange: function ( value ) { props.setAttributes( { linkLabel: value } ); } } ),
			el( SelectControl, { label: __( 'Title heading level', 'llamahire' ), value: props.attributes.headingLevel, options: [ 2, 3, 4, 5, 6 ].map( function ( level ) { return { label: 'H' + level, value: level }; } ), onChange: function ( value ) { props.setAttributes( { headingLevel: parseInt( value, 10 ) || 3 } ); } } )
		) );
	}

	function humanize( value ) {
		if ( ! value ) {
			return '';
		}
		return value.toLowerCase().replace( /_/g, ' ' ).replace( /(^|\s)\S/g, function ( letter ) { return letter.toUpperCase(); } );
	}

	function detailsControls( props ) {
		return el( InspectorControls, {}, el( PanelBody, { title: __( 'Job details settings', 'llamahire' ) },
			el( ToggleControl, { label: __( 'Show organization', 'llamahire' ), checked: props.attributes.showOrganization, onChange: function ( value ) { props.setAttributes( { showOrganization: value } ); } } ),
			el( ToggleControl, { label: __( 'Show location', 'llamahire' ), checked: props.attributes.showLocation, onChange: function ( value ) { props.setAttributes( { showLocation: value } ); } } ),
			props.attributes.showLocation ? el( ToggleControl, { label: __( 'Show street address', 'llamahire' ), checked: props.attributes.showFullAddress, onChange: function ( value ) { props.setAttributes( { showFullAddress: value } ); } } ) : null,
			el( ToggleControl, { label: __( 'Show workplace', 'llamahire' ), checked: props.attributes.showWorkplace, onChange: function ( value ) { props.setAttributes( { showWorkplace: value } ); } } ),
			el( ToggleControl, { label: __( 'Show employment type', 'llamahire' ), checked: props.attributes.showEmploymentType, onChange: function ( value ) { props.setAttributes( { showEmploymentType: value } ); } } ),
			el( ToggleControl, { label: __( 'Show salary', 'llamahire' ), checked: props.attributes.showSalary, onChange: function ( value ) { props.setAttributes( { showSalary: value } ); } } ),
			el( ToggleControl, { label: __( 'Show posted date', 'llamahire' ), checked: props.attributes.showPostedDate, onChange: function ( value ) { props.setAttributes( { showPostedDate: value } ); } } ),
			el( ToggleControl, { label: __( 'Show application deadline', 'llamahire' ), checked: props.attributes.showDeadline, onChange: function ( value ) { props.setAttributes( { showDeadline: value } ); } } ),
			el( ToggleControl, { label: __( 'Show job reference', 'llamahire' ), checked: props.attributes.showReference, onChange: function ( value ) { props.setAttributes( { showReference: value } ); } } )
		) );
	}

	function useEditorJob( props ) {
		var contextJobId = parseInt( props.context && props.context['llamahire/jobId'], 10 ) || 0;
		return useSelect( function ( select ) {
			var editorStore = select( 'core/editor' );
			var currentPostType = editorStore && editorStore.getCurrentPostType ? editorStore.getCurrentPostType() : '';
			var currentPostId = editorStore && editorStore.getCurrentPostId ? editorStore.getCurrentPostId() : 0;
			var jobId = contextJobId || ( currentPostType === 'llamahire_job' ? currentPostId : 0 );
			return {
				jobId: jobId,
				job: jobId ? select( 'core' ).getEntityRecord( 'postType', 'llamahire_job', jobId ) : null
			};
		}, [ contextJobId ] );
	}

	blocks.registerBlockType( 'llamahire/jobs-directory', {
		edit: function ( props ) {
			var departments = useSelect( function ( select ) {
				return select( 'core' ).getEntityRecords( 'taxonomy', 'llamahire_department', { per_page: 100, orderby: 'name', order: 'asc' } );
			}, [] );
			var departmentOptions = [ { label: __( 'All departments', 'llamahire' ), value: '' } ];
			( departments || [] ).forEach( function ( department ) {
				departmentOptions.push( { label: department.name, value: department.slug } );
			} );
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Directory settings', 'llamahire' ) },
					el( RangeControl, { label: __( 'Jobs per page', 'llamahire' ), min: 1, max: 50, value: props.attributes.perPage, onChange: function ( value ) { props.setAttributes( { perPage: value } ); } } ),
					el( SelectControl, { label: __( 'Limit to department', 'llamahire' ), help: __( 'Useful for department landing pages. The selected department stays fixed while visitors use other filters.', 'llamahire' ), value: props.attributes.department, options: departmentOptions, onChange: function ( value ) { props.setAttributes( { department: value } ); } } ),
					el( ToggleControl, { label: __( 'Show search and filters', 'llamahire' ), checked: props.attributes.showFilters, onChange: function ( value ) { props.setAttributes( { showFilters: value } ); } } ),
					el( ToggleControl, { label: __( 'Featured jobs only', 'llamahire' ), checked: props.attributes.featuredOnly, onChange: function ( value ) { props.setAttributes( { featuredOnly: value } ); } } )
				) ),
				el( ServerSideRender, { block: 'llamahire/jobs-directory', attributes: props.attributes } )
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'llamahire/job-search', {
		edit: function ( props ) {
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Search settings', 'llamahire' ) },
					el( TextControl, { label: __( 'Field label', 'llamahire' ), value: props.attributes.label, onChange: function ( value ) { props.setAttributes( { label: value } ); } } ),
					el( TextControl, { label: __( 'Placeholder', 'llamahire' ), value: props.attributes.placeholder, onChange: function ( value ) { props.setAttributes( { placeholder: value } ); } } ),
					el( TextControl, { label: __( 'Button label', 'llamahire' ), value: props.attributes.buttonLabel, onChange: function ( value ) { props.setAttributes( { buttonLabel: value } ); } } )
				) ),
				el( ServerSideRender, { block: 'llamahire/job-search', attributes: props.attributes } )
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'llamahire/job-filters', {
		edit: function ( props ) {
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Filter settings', 'llamahire' ) },
					el( ToggleControl, { label: __( 'Show department', 'llamahire' ), checked: props.attributes.showDepartment, onChange: function ( value ) { props.setAttributes( { showDepartment: value } ); } } ),
					el( ToggleControl, { label: __( 'Show employment type', 'llamahire' ), checked: props.attributes.showEmploymentType, onChange: function ( value ) { props.setAttributes( { showEmploymentType: value } ); } } ),
					el( ToggleControl, { label: __( 'Show workplace', 'llamahire' ), checked: props.attributes.showWorkplace, onChange: function ( value ) { props.setAttributes( { showWorkplace: value } ); } } ),
					el( ToggleControl, { label: __( 'Show location', 'llamahire' ), checked: props.attributes.showLocation, onChange: function ( value ) { props.setAttributes( { showLocation: value } ); } } ),
					el( ToggleControl, { label: __( 'Show featured roles', 'llamahire' ), checked: props.attributes.showFeatured, onChange: function ( value ) { props.setAttributes( { showFeatured: value } ); } } ),
					el( TextControl, { label: __( 'Button label', 'llamahire' ), value: props.attributes.buttonLabel, onChange: function ( value ) { props.setAttributes( { buttonLabel: value } ); } } )
				) ),
				el( ServerSideRender, { block: 'llamahire/job-filters', attributes: props.attributes } )
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'llamahire/job-card', {
		edit: function ( props ) {
			var editorData = useEditorJob( props );
			if ( ! editorData.jobId ) {
				return el( element.Fragment, {}, cardControls( props, __( 'Card settings', 'llamahire' ) ), el( 'div', useBlockProps( { className: 'llamahire-editor-placeholder' } ), el( 'strong', {}, __( 'Job Card', 'llamahire' ) ), el( 'p', {}, __( 'This block displays the job supplied by a LlamaHire collection. No post ID is needed.', 'llamahire' ) ) ) );
			}
			if ( ! editorData.job ) {
				return el( element.Fragment, {}, cardControls( props, __( 'Card settings', 'llamahire' ) ), el( 'div', useBlockProps( { className: 'llamahire-editor-placeholder' } ), __( 'Loading job preview…', 'llamahire' ) ) );
			}
			var job = editorData.job;
			var meta = job.meta && job.meta._llamahire_job ? job.meta._llamahire_job : {};
			var metaItems = [];
			if ( props.attributes.showLocation && ( meta.location || meta.address_locality ) ) { metaItems.push( meta.location || meta.address_locality ); }
			if ( props.attributes.showWorkplace && meta.workplace ) { metaItems.push( humanize( meta.workplace ) ); }
			if ( props.attributes.showEmploymentType && meta.employment_type ) { metaItems.push( humanize( meta.employment_type ) ); }
			var headingTag = 'h' + ( props.attributes.headingLevel || 3 );
			return el( element.Fragment, {},
				cardControls( props, __( 'Card settings', 'llamahire' ) ),
				el( 'article', useBlockProps( { className: 'llamahire-job-card' } ),
					props.attributes.showFeaturedBadge && meta.featured === '1' ? el( 'span', { className: 'llamahire-badge' }, __( 'Featured', 'llamahire' ) ) : null,
					el( headingTag, {}, el( 'a', { href: '#', onClick: function ( event ) { event.preventDefault(); } }, job.title && job.title.rendered ? job.title.rendered : __( '(Untitled job)', 'llamahire' ) ) ),
					metaItems.length ? el( 'div', { className: 'llamahire-job-meta' }, metaItems.map( function ( item, index ) { return el( 'span', { key: index }, item ); } ) ) : null,
					props.attributes.showExcerpt && job.excerpt && job.excerpt.rendered ? el( element.RawHTML, {}, job.excerpt.rendered ) : null,
					props.attributes.showSalary && meta.salary_min ? el( 'p', { className: 'llamahire-card-salary' }, [ meta.salary_currency, meta.salary_min, meta.salary_max ? '–' + meta.salary_max : '' ].filter( Boolean ).join( ' ' ) ) : null,
					el( 'a', { className: 'llamahire-card-link', href: '#', onClick: function ( event ) { event.preventDefault(); } }, props.attributes.linkLabel || __( 'View role', 'llamahire' ), ' ', el( 'span', { 'aria-hidden': true }, '→' ) )
				)
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'llamahire/single-job-details', {
		edit: function ( props ) {
			var editorData = useEditorJob( props );
			if ( ! editorData.jobId ) {
				return el( element.Fragment, {}, detailsControls( props ), el( 'div', useBlockProps( { className: 'llamahire-editor-placeholder' } ), el( 'strong', {}, __( 'Single Job Details', 'llamahire' ) ), el( 'p', {}, __( 'This block displays the current job automatically. Add it to a job or a job-aware template.', 'llamahire' ) ) ) );
			}
			if ( ! editorData.job ) {
				return el( element.Fragment, {}, detailsControls( props ), el( 'div', useBlockProps( { className: 'llamahire-editor-placeholder' } ), __( 'Loading job details…', 'llamahire' ) ) );
			}
			var job = editorData.job;
			var meta = job.meta && job.meta._llamahire_job ? job.meta._llamahire_job : {};
			var locationParts = props.attributes.showFullAddress ? [ meta.address_street, meta.address_locality, meta.address_region, meta.postal_code, meta.address_country ] : [ meta.address_locality, meta.address_region, meta.address_country ];
			var location = meta.workplace === 'remote' ? ( meta.applicant_countries ? __( 'Remote', 'llamahire' ) + ' — ' + meta.applicant_countries : __( 'Remote', 'llamahire' ) ) : locationParts.filter( Boolean ).join( ', ' );
			location = location || meta.location || '';
			var salary = meta.salary_min || meta.salary_max ? [ meta.salary_currency, meta.salary_min, meta.salary_max && meta.salary_max !== meta.salary_min ? '–' + meta.salary_max : '', meta.salary_unit ? '/ ' + humanize( meta.salary_unit ).toLowerCase() : '' ].filter( Boolean ).join( ' ' ) : '';
			var rows = [];
			if ( props.attributes.showOrganization && meta.organization_name ) { rows.push( [ __( 'Organization', 'llamahire' ), meta.organization_name ] ); }
			if ( props.attributes.showLocation && location ) { rows.push( [ __( 'Location', 'llamahire' ), location ] ); }
			if ( props.attributes.showWorkplace && meta.workplace ) { rows.push( [ __( 'Workplace', 'llamahire' ), humanize( meta.workplace ) ] ); }
			if ( props.attributes.showEmploymentType && meta.employment_type ) { rows.push( [ __( 'Employment', 'llamahire' ), humanize( meta.employment_type ) ] ); }
			if ( props.attributes.showSalary && salary ) { rows.push( [ __( 'Salary', 'llamahire' ), salary ] ); }
			if ( props.attributes.showPostedDate && job.date ) { rows.push( [ __( 'Posted', 'llamahire' ), new Date( job.date ).toLocaleDateString() ] ); }
			if ( props.attributes.showDeadline && meta.deadline ) { rows.push( [ __( 'Apply by', 'llamahire' ), meta.deadline ] ); }
			if ( props.attributes.showReference && meta.job_identifier ) { rows.push( [ __( 'Job reference', 'llamahire' ), meta.job_identifier ] ); }
			return el( element.Fragment, {},
				detailsControls( props ),
				rows.length ? el( 'dl', useBlockProps( { className: 'llamahire-job-facts' } ), rows.map( function ( row ) { return el( 'div', { key: row[0] }, el( 'dt', {}, row[0] ), el( 'dd', {}, row[1] ) ); } ) ) : el( 'div', useBlockProps( { className: 'llamahire-editor-placeholder' } ), __( 'Choose at least one available job detail to display.', 'llamahire' ) )
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'llamahire/featured-jobs', {
		edit: function ( props ) {
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Featured jobs settings', 'llamahire' ) },
					el( RangeControl, { label: __( 'Number of jobs', 'llamahire' ), min: 1, max: 12, value: props.attributes.perPage, onChange: function ( value ) { props.setAttributes( { perPage: value } ); } } ),
					el( ToggleControl, { label: __( 'Show heading', 'llamahire' ), checked: props.attributes.showHeading, onChange: function ( value ) { props.setAttributes( { showHeading: value } ); } } ),
					props.attributes.showHeading ? el( TextControl, { label: __( 'Heading', 'llamahire' ), value: props.attributes.heading, onChange: function ( value ) { props.setAttributes( { heading: value } ); } } ) : null
				) ),
				cardControls( props, __( 'Card settings', 'llamahire' ) ),
				el( ServerSideRender, { block: 'llamahire/featured-jobs', attributes: props.attributes } )
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'llamahire/application-form', {
		edit: function ( props ) {
			var editorData = useSelect( function ( select ) {
				var editorStore = select( 'core/editor' );
				return {
					postType: editorStore && editorStore.getCurrentPostType ? editorStore.getCurrentPostType() : '',
					jobs: select( 'core' ).getEntityRecords( 'postType', 'llamahire_job', { per_page: 100, orderby: 'title', order: 'asc' } )
				};
			}, [] );
			var jobOptions = [ { label: editorData.postType === 'llamahire_job' ? __( 'Current job', 'llamahire' ) : __( 'Select a published job', 'llamahire' ), value: 0 } ];
			( editorData.jobs || [] ).forEach( function ( job ) {
				jobOptions.push( { label: job.title.rendered || __( '(Untitled job)', 'llamahire' ), value: job.id } );
			} );
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Form settings', 'llamahire' ) },
					el( TextControl, { label: __( 'Heading', 'llamahire' ), value: props.attributes.heading, onChange: function ( value ) { props.setAttributes( { heading: value } ); } } ),
					el( SelectControl, { label: __( 'Job', 'llamahire' ), help: editorData.postType === 'llamahire_job' ? __( 'Use Current job when this form is inside a job post.', 'llamahire' ) : __( 'Choose the job that receives applications from this form.', 'llamahire' ), value: props.attributes.jobId || 0, options: jobOptions, onChange: function ( value ) { props.setAttributes( { jobId: parseInt( value, 10 ) || 0 } ); } } )
				) ),
				el( 'div', { className: 'llamahire-editor-placeholder' }, el( 'strong', {}, props.attributes.heading ), el( 'p', {}, __( 'The candidate application form will appear here.', 'llamahire' ) ) )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.data, window.wp.i18n, window.wp.serverSideRender );
