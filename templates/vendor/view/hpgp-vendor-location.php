<?php
/**
 * Formatted vendor location.
 *
 * A copy of the HivePress Geolocation extension's own location part with one change: the
 * address is put through the configured format before it is shown. The block is only re-pointed
 * at this file while a format is set, so with the default "Full address" the extension's own
 * output is what renders and nothing here runs at all.
 *
 * Override this file by copying it to "hivepress/vendor/view/hpgp-vendor-location.php" in your theme.
 *
 * @package GeolocationPlus\Templates
 *
 * @var \HivePress\Models\Vendor $vendor The vendor being displayed.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( $vendor->get_location() ) :

	// The full address stays in the title attribute, so shortening what is displayed never
	// hides information from somebody who wants it.
	$hpgp_address = hivepress()->hpgp_geolocation ? hivepress()->hpgp_geolocation->format_address( $vendor->get_location() ) : $vendor->get_location();

	$hpgp_url = hivepress()->router->get_url(
		'location_view_page',
		[
			'latitude'  => $vendor->get_latitude(),
			'longitude' => $vendor->get_longitude(),
		]
	);
	?>
	<div class="hp-vendor__location hp-listing__location">
		<i class="hp-icon fas fa-map-marker-alt"></i>
		<?php if ( get_option( 'hp_geolocation_hide_address' ) ) : ?>
			<span><?php echo esc_html( $hpgp_address ); ?></span>
		<?php else : ?>
			<a href="<?php echo esc_url( $hpgp_url ); ?>" target="_blank" title="<?php echo esc_attr( $vendor->get_location() ); ?>"><?php echo esc_html( $hpgp_address ); ?></a>
		<?php endif; ?>
	</div>
	<?php
endif;
