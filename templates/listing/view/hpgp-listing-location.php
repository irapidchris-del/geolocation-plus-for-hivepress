<?php
/**
 * Formatted listing location.
 *
 * A copy of the HivePress Geolocation extension's own location part with one change: the
 * address is put through the configured format before it is shown. The block is only re-pointed
 * at this file while a format is set, so with the default "Full address" the extension's own
 * output is what renders and nothing here runs at all.
 *
 * Override this file by copying it to "hivepress/listing/view/hpgp-listing-location.php" in your theme.
 *
 * @package GeolocationPlus\Templates
 *
 * @var \HivePress\Models\Listing $listing The listing being displayed.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( $listing->get_location() ) :

	// The full address stays in the title attribute, so shortening what is displayed never
	// hides information from somebody who wants it.
	$hpgp_address = hivepress()->hpgp_geolocation ? hivepress()->hpgp_geolocation->format_address( $listing->get_location(), 'listing' ) : $listing->get_location();

	$hpgp_url = hivepress()->router->get_url(
		'location_view_page',
		[
			'latitude'  => $listing->get_latitude(),
			'longitude' => $listing->get_longitude(),
		]
	);
	?>
	<div class="hp-listing__location">
		<i class="hp-icon fas fa-map-marker-alt"></i>
		<?php if ( get_option( 'hp_geolocation_hide_address' ) ) : ?>
			<span><?php echo esc_html( $hpgp_address ); ?></span>
		<?php else : ?>
			<a href="<?php echo esc_url( $hpgp_url ); ?>" target="_blank" title="<?php echo esc_attr( $listing->get_location() ); ?>"><?php echo esc_html( $hpgp_address ); ?></a>
		<?php endif; ?>
	</div>
	<?php
endif;
