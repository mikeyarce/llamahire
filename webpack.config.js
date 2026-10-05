const path = require( 'node:path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: { ...defaultConfig.resolve?.alias, '@wordpress/data$': path.resolve( __dirname, 'src/wordpress-data.js' ) }
	},
	plugins: defaultConfig.plugins.map( plugin => {
		if ( plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' ) return plugin;
		return new DependencyExtractionWebpackPlugin( {
			...plugin.options,
			requestToHandle( request ) {
				if ( request === '@llamahire/wordpress-data' ) return 'wp-data';
				return undefined;
			},
			requestToExternal( request ) {
				if ( request === '@llamahire/wordpress-data' ) return [ 'wp', 'data' ];
				// WordPress 6.5 has React 18 but does not register react-jsx-runtime.
				// Bundle the small helper while retaining WordPress's shared React instance.
				if ( [ 'react/jsx-runtime', '@wordpress/data' ].includes( request ) ) return false;
				return undefined;
			}
		} );
	} )
};
