<?php
/**
 * Scripts configuration.
 *
 * Both entries are pruned again by the component's alter_scripts(), which is where the
 * decision "is one of our providers in use?" is made. Leaflet in particular is only ever
 * enqueued for our own providers, since Google Maps and Mapbox draw their own maps.
 *
 * @package GeolocationPlus\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// The component builds the script payload. Core has no __isset(), so a missing component
// reads as null rather than failing an isset() check.
$hpgp_component = hivepress()->hpgp_geolocation;

if ( ! hivepress()->get_version( 'geolocation_plus_for_hivepress' ) || ! $hpgp_component ) {
	return [];
}


return [
	'hpgp_leaflet'     => [
		'handle'  => 'hpgp-leaflet',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/vendor/leaflet/leaflet.js',
		'version' => '1.9.4',
		'scope'   => [ 'frontend', 'backend' ],
	],

	'hpgp_cluster'     => [
		'handle'  => 'hpgp-leaflet-markercluster',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/vendor/leaflet/leaflet.markercluster.js',
		'version' => '1.5.3',
		'deps'    => [ 'hpgp-leaflet' ],
		'scope'   => [ 'frontend', 'backend' ],
	],

	'hpgp_geolocation' => [
		'handle'  => 'hivepress-geolocation-plus',
		'src'     => hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/js/common.js',
		'version' => hpgp_asset_version( 'assets/js/common.js' ),
		'deps'    => [ 'hivepress-core' ],
		'scope'   => [ 'frontend', 'backend' ],
		// Nested one level deliberately. WP_Scripts::localize() casts every TOP-LEVEL scalar to
		// a string (wp-includes/class-wp-scripts.php:655-663) and leaves nested arrays alone,
		// so a flat payload would reach the browser with false as "" and 3 as "3". Everything
		// under `config` keeps the type it was given.
		'data'    => [ 'config' => $hpgp_component->get_script_data() ],
	],
];
