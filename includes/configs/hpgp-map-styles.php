<?php
/**
 * Map styles configuration.
 *
 * A flat name => label list of the styles the selected map provider offers, which is what the
 * map block's "Map Style" setting reads. HivePress resolves a string `options` argument to a
 * config of the same name when no matching Form::get_{name}() method exists
 * (`components/class-form.php:59-96`), so this file is all a select needs to be populated.
 *
 * @package GeolocationPlus\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpgp_component = hivepress()->hpgp_geolocation;

if ( ! $hpgp_component ) {
	return [];
}

$hpgp_provider = $hpgp_component->get_provider();

if ( ! $hpgp_provider ) {
	return [];
}

$hpgp_styles = [];

foreach ( (array) ( $hpgp_provider['styles'] ?? [] ) as $hpgp_name => $hpgp_style ) {
	$hpgp_styles[ $hpgp_name ] = $hpgp_style['label'];
}

return $hpgp_styles;
