<?php
/**
 * Styles configuration.
 *
 * The Leaflet stylesheets are dropped again by the component's alter_styles() unless one of
 * the map providers this plugin adds is in use.
 *
 * @package GeolocationPlus\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! hivepress()->get_version( 'geolocation_plus_for_hivepress' ) ) {
	return [];
}

return [
	'hpgp_leaflet'         => [
		'handle'  => 'hpgp-leaflet',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/vendor/leaflet/leaflet.css',
		'version' => '1.9.4',
		'scope'   => [ 'frontend', 'backend' ],
	],

	'hpgp_cluster'         => [
		'handle'  => 'hpgp-leaflet-markercluster',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/vendor/leaflet/MarkerCluster.css',
		'version' => '1.5.3',
		'scope'   => [ 'frontend', 'backend' ],
	],

	'hpgp_cluster_default' => [
		'handle'  => 'hpgp-leaflet-markercluster-default',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/vendor/leaflet/MarkerCluster.Default.css',
		'version' => '1.5.3',
		'scope'   => [ 'frontend', 'backend' ],
	],

	'hpgp_geolocation'     => [
		'handle'  => 'hivepress-geolocation-plus',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/css/frontend.css',
		'version' => hpgp_asset_version( 'assets/css/frontend.css' ),
		'scope'   => [ 'frontend', 'backend', 'editor' ],
	],
];
