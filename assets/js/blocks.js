( function ( blocks, element, components, blockEditor, i18n, ServerSideRender ) {
	'use strict';
	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
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

	blocks.registerBlockType( 'llamahire/application-form', {
		edit: function ( props ) {
			return el( element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Form settings', 'llamahire' ) },
					el( TextControl, { label: __( 'Heading', 'llamahire' ), value: props.attributes.heading, onChange: function ( value ) { props.setAttributes( { heading: value } ); } } ),
					el( TextControl, { label: __( 'Job ID (leave empty on a job)', 'llamahire' ), type: 'number', value: props.attributes.jobId || '', onChange: function ( value ) { props.setAttributes( { jobId: parseInt( value, 10 ) || 0 } ); } } )
				) ),
				el( 'div', { className: 'llamahire-editor-placeholder' }, el( 'strong', {}, props.attributes.heading ), el( 'p', {}, __( 'The candidate application form will appear here.', 'llamahire' ) ) )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.i18n, window.wp.serverSideRender );
