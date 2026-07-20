( function ( blocks, element, components, blockEditor, data, i18n, ServerSideRender ) {
	'use strict';
	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var useSelect = data.useSelect;
	var __ = i18n.__;

	blocks.registerBlockType( 'llamahire/jobs-directory', {
		edit: function ( props ) {
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Directory settings', 'llamahire' ) },
					el( RangeControl, { label: __( 'Jobs per page', 'llamahire' ), min: 1, max: 50, value: props.attributes.perPage, onChange: function ( value ) { props.setAttributes( { perPage: value } ); } } ),
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
