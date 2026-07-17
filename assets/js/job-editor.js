( function ( wp, config ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var __ = wp.i18n.__;

	function option( label, value ) {
		return { label: label, value: value };
	}

	function JobSettings() {
		var editor = useSelect( function ( select ) {
			var store = select( 'core/editor' );
			return {
				meta: store.getEditedPostAttribute( 'meta' ) || {},
				title: store.getEditedPostAttribute( 'title' ) || '',
				content: store.getEditedPostContent() || ''
			};
		}, [] );
		var actions = useDispatch( 'core/editor' );
		var data = Object.assign( {}, config.defaults || {}, editor.meta._llamahire_job || {} );
		var organization = config.organization || {};
		var organizationName = data.organization_name || organization.name || '';
		var issues = [];

		if ( ! editor.title.trim() ) {
			issues.push( __( 'Add a concise job title.', 'llamahire' ) );
		}
		if ( ! editor.content.replace( /<[^>]*>/g, '' ).trim() ) {
			issues.push( __( 'Add a complete job description.', 'llamahire' ) );
		}
		if ( ! organizationName.trim() ) {
			issues.push( __( 'Add a hiring organization.', 'llamahire' ) );
		}
		if ( data.workplace === 'remote' ) {
			if ( ! data.applicant_countries.trim() ) {
				issues.push( __( 'Add at least one country where remote applicants may work.', 'llamahire' ) );
			}
		} else if ( ! data.address_locality.trim() || ! data.address_country.trim() ) {
			issues.push( __( 'Add the job city/locality and country.', 'llamahire' ) );
		}
		if ( data.salary_min !== '' && data.salary_max !== '' && Number( data.salary_max ) < Number( data.salary_min ) ) {
			issues.push( __( 'Maximum salary must be at least the minimum salary.', 'llamahire' ) );
		}

		function set( key, value ) {
			var nextMeta = Object.assign( {}, editor.meta );
			var nextData = Object.assign( {}, data );
			nextData[ key ] = value;
			nextMeta._llamahire_job = nextData;
			actions.editPost( { meta: nextMeta } );
		}

		var addressControls = data.workplace === 'remote' ?
			el( TextControl, {
				label: __( 'Eligible applicant countries', 'llamahire' ),
				help: __( 'Comma-separated two-letter country codes, for example US, CA. Required for fully remote jobs.', 'llamahire' ),
				value: data.applicant_countries,
				onChange: function ( value ) { set( 'applicant_countries', value.toUpperCase() ); }
			} ) :
			el( Fragment, {},
				el( TextControl, { label: __( 'Street address', 'llamahire' ), value: data.address_street, onChange: function ( value ) { set( 'address_street', value ); } } ),
				el( TextControl, { label: __( 'City or locality', 'llamahire' ), value: data.address_locality, onChange: function ( value ) { set( 'address_locality', value ); } } ),
				el( TextControl, { label: __( 'State, province, or region', 'llamahire' ), value: data.address_region, onChange: function ( value ) { set( 'address_region', value ); } } ),
				el( TextControl, { label: __( 'Postal code', 'llamahire' ), value: data.postal_code, onChange: function ( value ) { set( 'postal_code', value ); } } ),
				el( TextControl, { label: __( 'Country code', 'llamahire' ), help: __( 'Two-letter ISO code, such as US, CA, or GB.', 'llamahire' ), maxLength: 2, value: data.address_country, onChange: function ( value ) { set( 'address_country', value.toUpperCase() ); } } )
			);

		return el( Fragment, {},
			el( PluginDocumentSettingPanel, { name: 'llamahire-readiness', title: __( 'Google Jobs readiness', 'llamahire' ), className: 'llamahire-readiness' },
				issues.length ?
					el( Notice, { status: 'warning', isDismissible: false },
						el( 'strong', {}, __( 'Complete these for Google Jobs eligibility:', 'llamahire' ) ),
						el( 'ul', {}, issues.map( function ( issue ) { return el( 'li', { key: issue }, issue ); } ) )
					) :
					el( Notice, { status: 'success', isDismissible: false }, __( 'Required Google Jobs fields are complete.', 'llamahire' ) )
			),
			el( PluginDocumentSettingPanel, { name: 'llamahire-role', title: __( 'Role and publication', 'llamahire' ) },
				el( SelectControl, {
					label: __( 'Employment type', 'llamahire' ), value: data.employment_type,
					options: [ option( __( 'Full time', 'llamahire' ), 'FULL_TIME' ), option( __( 'Part time', 'llamahire' ), 'PART_TIME' ), option( __( 'Contractor', 'llamahire' ), 'CONTRACTOR' ), option( __( 'Temporary', 'llamahire' ), 'TEMPORARY' ), option( __( 'Internship', 'llamahire' ), 'INTERN' ), option( __( 'Volunteer', 'llamahire' ), 'VOLUNTEER' ), option( __( 'Per diem', 'llamahire' ), 'PER_DIEM' ), option( __( 'Other', 'llamahire' ), 'OTHER' ) ],
					onChange: function ( value ) { set( 'employment_type', value ); }
				} ),
				el( SelectControl, {
					label: __( 'Workplace', 'llamahire' ), value: data.workplace,
					options: [ option( __( 'On-site', 'llamahire' ), 'onsite' ), option( __( 'Hybrid', 'llamahire' ), 'hybrid' ), option( __( 'Fully remote', 'llamahire' ), 'remote' ) ],
					onChange: function ( value ) { set( 'workplace', value ); }
				} ),
				el( TextControl, { label: __( 'Application deadline', 'llamahire' ), type: 'date', value: data.deadline, onChange: function ( value ) { set( 'deadline', value ); } } ),
				el( TextControl, { label: __( 'Stable job reference', 'llamahire' ), help: __( 'Used as Google’s unique identifier. Keep it stable after publication.', 'llamahire' ), value: data.job_identifier, onChange: function ( value ) { set( 'job_identifier', value ); } } ),
				el( CheckboxControl, { label: __( 'Featured job', 'llamahire' ), checked: data.featured === '1', onChange: function ( value ) { set( 'featured', value ? '1' : '0' ); } } ),
				el( CheckboxControl, { label: __( 'Closed to applications', 'llamahire' ), checked: data.closed === '1', onChange: function ( value ) { set( 'closed', value ? '1' : '0' ); } } )
			),
			el( PluginDocumentSettingPanel, { name: 'llamahire-location', title: data.workplace === 'remote' ? __( 'Remote eligibility', 'llamahire' ) : __( 'Job location', 'llamahire' ) }, addressControls ),
			el( PluginDocumentSettingPanel, { name: 'llamahire-compensation', title: __( 'Compensation', 'llamahire' ) },
				el( 'p', { className: 'llamahire-editor-help' }, __( 'Only enter salary supplied by the employer. Values entered here are shown publicly.', 'llamahire' ) ),
				el( TextControl, { label: __( 'Minimum salary', 'llamahire' ), type: 'number', min: 0, value: data.salary_min, onChange: function ( value ) { set( 'salary_min', value ); } } ),
				el( TextControl, { label: __( 'Maximum salary', 'llamahire' ), type: 'number', min: 0, value: data.salary_max, onChange: function ( value ) { set( 'salary_max', value ); } } ),
				el( TextControl, { label: __( 'Currency', 'llamahire' ), help: __( 'Three-letter ISO code, such as USD, CAD, or GBP.', 'llamahire' ), maxLength: 3, value: data.salary_currency, onChange: function ( value ) { set( 'salary_currency', value.toUpperCase() ); } } ),
				el( SelectControl, {
					label: __( 'Pay period', 'llamahire' ), value: data.salary_unit,
					options: [ option( __( 'Hour', 'llamahire' ), 'HOUR' ), option( __( 'Day', 'llamahire' ), 'DAY' ), option( __( 'Week', 'llamahire' ), 'WEEK' ), option( __( 'Month', 'llamahire' ), 'MONTH' ), option( __( 'Year', 'llamahire' ), 'YEAR' ) ],
					onChange: function ( value ) { set( 'salary_unit', value ); }
				} )
			),
			el( PluginDocumentSettingPanel, { name: 'llamahire-organization', title: __( 'Hiring organization', 'llamahire' ) },
				el( 'p', { className: 'llamahire-editor-help' }, __( 'Leave overrides blank to use the organization defaults in Jobs → Settings.', 'llamahire' ) ),
				el( TextControl, { label: __( 'Organization name override', 'llamahire' ), placeholder: organization.name || '', value: data.organization_name, onChange: function ( value ) { set( 'organization_name', value ); } } ),
				el( TextControl, { label: __( 'Website override', 'llamahire' ), type: 'url', placeholder: organization.website || '', value: data.organization_url, onChange: function ( value ) { set( 'organization_url', value ); } } ),
				el( TextControl, { label: __( 'Logo URL override', 'llamahire' ), type: 'url', placeholder: organization.logo || '', value: data.organization_logo, onChange: function ( value ) { set( 'organization_logo', value ); } } )
			)
		);
	}

	registerPlugin( 'llamahire-job-settings', { render: JobSettings, icon: 'businessperson' } );
} )( window.wp, window.llamahireJobEditor || {} );
