<?php
/**
 * Service area component.
 *
 * @package GeolocationPlus\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lets vendors who travel to their customers set how far they travel.
 *
 * The Geolocation extension answers "what is within X of the searcher". A vendor who travels
 * needs the opposite question answered as well: "whose travel distance reaches the searcher".
 * This component adds a Service Radius field to the vendor profile and widens the location
 * search so a listing is found either way.
 *
 * @class Hpgp_Service_Area
 */
final class Hpgp_Service_Area extends Component {

	/**
	 * Vendor attribute that holds the radius, in the site's distance unit.
	 *
	 * Stored as post meta `hp_hpgp_service_radius` on the vendor, because a HivePress attribute
	 * with an edit field is an `_external` model field keyed `hp_{name}`.
	 *
	 * @var string
	 */
	const ATTRIBUTE = 'hpgp_service_radius';

	/**
	 * Kilometres in a mile. Matches the factor the Geolocation extension uses for its own
	 * radius (`hivepress-geolocation/includes/components/class-geolocation.php:136-137`), so a
	 * "10 miles" service area and a "10 miles" search radius cover the same ground.
	 *
	 * @var float
	 */
	const KM_PER_MILE = 1.60934;

	/**
	 * Most travelling posts one search will add. A safety valve, not a product limit.
	 *
	 * @var int
	 */
	const MAX_RESULTS = 1000;

	/**
	 * Travelling post IDs already looked up in this request, keyed by model and point.
	 *
	 * The main search and HivePress's featured-listing lookup run the same location filter
	 * (`hivepress/includes/components/class-attribute.php:2356-2369` copies the meta_query),
	 * so the second one reuses the first one's answer.
	 *
	 * @var array
	 */
	protected $found = [];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Without the Geolocation extension there are no coordinates to measure from.
		if ( ! hivepress()->get_version( 'geolocation' ) ) {
			parent::__construct( $args );

			return;
		}

		if ( is_admin() ) {

			// Offer the listing attribute options for "Travelling Listings".
			add_filter( 'hivepress/v1/settings', [ $this, 'alter_settings' ], 30 );
		}

		if ( $this->is_enabled() ) {

			// Add the Service Radius field to vendors.
			add_filter( 'hivepress/v1/models/vendor/attributes', [ $this, 'add_vendor_attributes' ], 200 );

			if ( ! is_admin() ) {

				// Widen location searches to reach travelling vendors.
				add_filter( 'posts_where', [ $this, 'widen_location_filter' ], 20, 2 );
			}

			if ( get_option( 'hp_geolocation_plus_service_area_address' ) ) {

				// Both callbacks confirm the chosen field still exists before acting; that needs a
				// posts query, which is too early to run here (components boot before init).

				// Refuse a booking address outside the vendor's service area.
				add_filter( 'hivepress/v1/forms/booking_confirm/errors', [ $this, 'check_booking_address' ], 20, 2 );

				// Charge the travel fee. Not gated on is_admin(): block checkout recalculates the
				// cart over REST and the classic checkout over admin-ajax, and both must see it.
				add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_travel_fees' ], 20 );
			}
		}

		parent::__construct( $args );
	}

	/**
	 * Gets the booking attribute that holds the customer's address, if one is chosen and usable.
	 *
	 * It must be a Location attribute from this plugin, because only those store coordinates
	 * beside the address (`hp_{name}_latitude` and `hp_{name}_longitude`, see
	 * Hpgp_Geolocation::add_location_attributes()). Without Bookings there is nothing to charge.
	 *
	 * @return string Attribute name, or an empty string.
	 */
	public function get_address_attribute() {
		if ( ! hivepress()->get_version( 'bookings' ) ) {
			return '';
		}

		$name = sanitize_key( (string) get_option( 'hp_geolocation_plus_service_area_address' ) );

		return '' !== $name && in_array( $name, array_keys( $this->get_booking_location_attributes() ), true ) ? $name : '';
	}

	/**
	 * Gets the booking attributes of the Location type, as name => label.
	 *
	 * @return array
	 */
	protected function get_booking_location_attributes() {
		static $found = null;

		if ( null !== $found ) {
			return $found;
		}

		$found = [];

		$posts = get_posts(
			[
				'post_type'      => 'hp_booking_attribute',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of attribute posts, read once per request.
				'meta_key'       => 'hp_edit_field_type',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'meta_value'     => 'hpgp_location',
			]
		);

		foreach ( $posts as $post ) {
			$found[ sanitize_key( $post->post_name ) ] = wp_specialchars_decode( $post->post_title, ENT_QUOTES );
		}

		return $found;
	}

	/**
	 * Checks whether a listing uses its vendor's service area.
	 *
	 * The same two conditions the search applies: the vendor entered a radius, and, when an
	 * attribute option marks travelling listings, the listing carries it.
	 *
	 * @param object $listing Listing object.
	 * @return bool
	 */
	public function is_travelling_listing( $listing ) {
		$vendor_id = (int) $listing->get_vendor__id();

		if ( ! $vendor_id || (float) get_post_meta( $vendor_id, 'hp_' . self::ATTRIBUTE, true ) <= 0 ) {
			return false;
		}

		$term_id = absint( get_option( 'hp_geolocation_plus_service_area_option' ) );

		if ( ! $term_id ) {
			return true;
		}

		$term = get_term( $term_id );

		return $term && ! is_wp_error( $term ) && has_term( $term->term_id, $term->taxonomy, $listing->get_id() );
	}

	/**
	 * Works out the distance and travel fee for a customer address and a listing.
	 *
	 * The distance is a straight line between the listing's location and the address, which is
	 * what the service area is measured in too; the readme and the field help say so.
	 *
	 * @param object $listing Listing object.
	 * @param float  $latitude Address latitude.
	 * @param float  $longitude Address longitude.
	 * @return array|null [ distance (site unit), radius (site unit), fee, allowed ] or null when
	 *                    the listing does not travel or either point is missing.
	 */
	public function get_travel_quote( $listing, $latitude, $longitude ) {
		if ( ! $this->is_travelling_listing( $listing ) ) {
			return null;
		}

		$from_lat = get_post_meta( $listing->get_id(), 'hp_latitude', true );
		$from_lng = get_post_meta( $listing->get_id(), 'hp_longitude', true );

		if ( ! is_numeric( $from_lat ) || ! is_numeric( $from_lng ) || ! is_numeric( $latitude ) || ! is_numeric( $longitude ) ) {
			return null;
		}

		$factor = $this->uses_miles() ? self::KM_PER_MILE : 1;
		$km     = $this->get_distance_km( (float) $from_lat, (float) $from_lng, (float) $latitude, (float) $longitude );
		$dist   = $km / $factor;

		$vendor_id = (int) $listing->get_vendor__id();
		$radius    = min( (float) get_post_meta( $vendor_id, 'hp_' . self::ATTRIBUTE, true ), (float) $this->get_max_radius() );

		$flat     = max( 0, (float) get_post_meta( $vendor_id, 'hp_hpgp_travel_fee', true ) );
		$rate     = max( 0, (float) get_post_meta( $vendor_id, 'hp_hpgp_travel_fee_rate', true ) );
		$included = max( 0, (float) get_option( 'hp_geolocation_plus_service_area_included', 0 ) );

		$fee = $flat + max( 0, $dist - $included ) * $rate;

		return [
			'distance' => round( $dist, 1 ),
			'radius'   => $radius,
			'fee'      => round( $fee, 2 ),

			// A little slack, so an address the search itself offered is never refused over
			// rounding in the stored coordinates.
			'allowed'  => $dist <= $radius + 0.05,
		];
	}

	/**
	 * Distance between two points on the Earth's surface, in kilometres (haversine).
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 * @return float
	 */
	public function get_distance_km( $lat1, $lng1, $lat2, $lng2 ) {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return 6371 * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Formats a distance with the site's unit, for customer-facing text.
	 *
	 * @param float $distance Distance in the site's unit.
	 * @return string
	 */
	protected function format_distance( $distance ) {

		// Whole numbers read as "25 miles", not "25.0 miles"; anything else keeps one decimal.
		$number = number_format_i18n( $distance, abs( $distance - round( $distance ) ) < 0.05 ? 0 : 1 );

		/* translators: %s: a distance. */
		return $this->uses_miles() ? sprintf( esc_html__( '%s miles', 'geolocation-plus-for-hivepress' ), $number ) : sprintf( esc_html__( '%s km', 'geolocation-plus-for-hivepress' ), $number );
	}

	/**
	 * Refuses a booking whose address is outside the vendor's service area.
	 *
	 * Runs on the booking details step, where the customer enters the address. An empty address
	 * is left to the form's own rules: some listings are booked at the vendor's premises.
	 *
	 * @param array  $errors Form errors.
	 * @param object $form Form object.
	 * @return array
	 */
	public function check_booking_address( $errors, $form ) {
		$name = $this->get_address_attribute();

		if ( $errors || '' === $name ) {
			return $errors;
		}

		$booking = method_exists( $form, 'get_model' ) ? $form->get_model() : null;

		if ( ! $booking instanceof \HivePress\Models\Booking ) {
			return $errors;
		}

		$listing = $booking->get_listing();

		if ( ! $listing ) {
			return $errors;
		}

		$quote = $this->get_travel_quote( $listing, $form->get_value( $name . '_latitude' ), $form->get_value( $name . '_longitude' ) );

		if ( $quote && ! $quote['allowed'] ) {
			$errors[] = sprintf(
				/* translators: 1: the service radius, such as "10.0 miles"; 2: the distance to the address. */
				esc_html__( 'This Vendor travels up to %1$s, and this address is about %2$s away. Please choose an address closer by.', 'geolocation-plus-for-hivepress' ),
				$this->format_distance( $quote['radius'] ),
				$this->format_distance( $quote['distance'] )
			);
		}

		return $errors;
	}

	/**
	 * Adds a travel fee line for every booking in the cart that is carried out at the customer's address.
	 *
	 * HivePress Bookings links a cart item to its booking through the `hp_booking` key
	 * (hivepress-bookings/includes/components/class-booking.php, get_cart_meta(); Marketplace
	 * prefixes the keys when it adds the item). The fee is a cart fee, so it is part of the cart
	 * total that the payment gateway charges.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return void
	 */
	public function add_travel_fees( $cart ) {
		$name = $this->get_address_attribute();

		if ( '' === $name ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['hp_booking'] ) ) {
				continue;
			}

			$booking = \HivePress\Models\Booking::query()->get_by_id( absint( $item['hp_booking'] ) );
			$listing = $booking ? $booking->get_listing() : null;

			if ( ! $listing ) {
				continue;
			}

			$quote = $this->get_travel_quote(
				$listing,
				get_post_meta( $booking->get_id(), 'hp_' . $name . '_latitude', true ),
				get_post_meta( $booking->get_id(), 'hp_' . $name . '_longitude', true )
			);

			if ( ! $quote || $quote['fee'] <= 0 ) {
				continue;
			}

			/* translators: %s: a distance such as "6.2 miles". */
			$cart->add_fee( sprintf( esc_html__( 'Travel fee (%s)', 'geolocation-plus-for-hivepress' ), $this->format_distance( $quote['distance'] ) ), $quote['fee'] );
		}
	}

	/**
	 * Checks whether service areas are switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( 'hp_geolocation_plus_service_area' );
	}

	/**
	 * Checks whether the site measures distances in miles.
	 *
	 * @return bool
	 */
	protected function uses_miles() {
		return (bool) get_option( 'hp_geolocation_use_miles' );
	}

	/**
	 * Gets the longest radius a vendor may enter, in the site's unit.
	 *
	 * A cleared number field is stored as an empty string, and `(int) ''` would silently mean
	 * "nobody travels at all", so anything that is not a positive number means the default.
	 *
	 * @return int
	 */
	public function get_max_radius() {
		$value = get_option( 'hp_geolocation_plus_service_area_max', null );

		if ( ! is_numeric( $value ) || (int) $value < 1 ) {
			return 50;
		}

		return min( 500, (int) $value );
	}

	/**
	 * Adds the vendor attributes.
	 *
	 * @param array $attributes Attributes.
	 * @return array
	 */
	public function add_vendor_attributes( $attributes ) {
		$miles = $this->uses_miles();

		$attributes[ self::ATTRIBUTE ] = [
			'editable'       => true,

			'display_format' => $miles
				/* translators: %s: distance. */
				? sprintf( esc_html__( 'Travels up to %s miles', 'geolocation-plus-for-hivepress' ), '%value%' )
				/* translators: %s: distance. */
				: sprintf( esc_html__( 'Travels up to %s km', 'geolocation-plus-for-hivepress' ), '%value%' ),

			'display_areas'  => [
				'view_block_primary',
				'view_page_primary',
			],

			'edit_field'     => [
				'label'       => $miles
					? esc_html__( 'Service Radius (miles)', 'geolocation-plus-for-hivepress' )
					: esc_html__( 'Service Radius (km)', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'How far you travel to customers. Customers inside this distance will find your listings when they search by location. Leave it empty if customers come to you.', 'geolocation-plus-for-hivepress' ),
				'type'        => 'number',
				'min_value'   => 1,
				'max_value'   => $this->get_max_radius(),
				'_order'      => 36,
			],
		];

		if ( $this->get_address_attribute() ) {
			$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '';

			$attributes['hpgp_travel_fee'] = [
				'editable'       => true,

				/* translators: %s: an amount of money. */
				'display_format' => sprintf( esc_html__( 'Travel fee from %s', 'geolocation-plus-for-hivepress' ), '%value%' ),

				'display_areas'  => [
					'view_page_primary',
				],

				'edit_field'     => [
					/* translators: %s: currency symbol. */
					'label'       => '' !== $symbol ? sprintf( esc_html__( 'Travel Fee (%s)', 'geolocation-plus-for-hivepress' ), $symbol ) : esc_html__( 'Travel Fee', 'geolocation-plus-for-hivepress' ),
					'description' => esc_html__( 'Added to every booking at a customer\'s address. Leave it empty to travel for free.', 'geolocation-plus-for-hivepress' ),
					'type'        => 'currency',
					'min_value'   => 0,
					'_order'      => 37,
				],
			];

			$miles    = $this->uses_miles();
			$included = max( 0, (float) get_option( 'hp_geolocation_plus_service_area_included', 0 ) );

			$attributes['hpgp_travel_fee_rate'] = [
				'editable'   => true,

				'edit_field' => [
					'label'       => $miles
						/* translators: %s: currency symbol. */
						? sprintf( esc_html__( 'Extra per Mile (%s)', 'geolocation-plus-for-hivepress' ), $symbol )
						/* translators: %s: currency symbol. */
						: sprintf( esc_html__( 'Extra per km (%s)', 'geolocation-plus-for-hivepress' ), $symbol ),
					'description' => $included > 0
						? sprintf(
							/* translators: %s: a distance such as "5 miles". */
							esc_html__( 'Optional. Added for every mile or km beyond the first %s, measured in a straight line from your listing.', 'geolocation-plus-for-hivepress' ),
							$miles ? sprintf( /* translators: %s: number. */ esc_html__( '%s miles', 'geolocation-plus-for-hivepress' ), number_format_i18n( $included ) ) : sprintf( /* translators: %s: number. */ esc_html__( '%s km', 'geolocation-plus-for-hivepress' ), number_format_i18n( $included ) )
						)
						: esc_html__( 'Optional. Added for every mile or km to the customer, measured in a straight line from your listing.', 'geolocation-plus-for-hivepress' ),
					'type'        => 'currency',
					'min_value'   => 0,
					'_order'      => 38,
				],
			];
		}

		return $attributes;
	}

	/**
	 * Fills the "Travelling Listings" choices with the options of the listing attributes.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public function alter_settings( $settings ) {
		if ( ! isset( $settings['geolocation']['sections']['hpgp_service']['fields']['geolocation_plus_service_area_option'] ) ) {
			return $settings;
		}

		$field = &$settings['geolocation']['sections']['hpgp_service']['fields']['geolocation_plus_service_area_option'];

		foreach ( $this->get_attribute_options() as $term_id => $label ) {
			$field['options'][ $term_id ] = $label;
		}

		unset( $field );

		// Travel fees need Bookings and a Location field on bookings; without either, the two
		// controls would be settings nobody can act on, so they are removed rather than explained.
		$locations = $this->get_booking_location_attributes();

		if ( ! hivepress()->get_version( 'bookings' ) || ! $locations ) {
			unset( $settings['geolocation']['sections']['hpgp_service']['fields']['geolocation_plus_service_area_address'], $settings['geolocation']['sections']['hpgp_service']['fields']['geolocation_plus_service_area_included'] );
		} elseif ( isset( $settings['geolocation']['sections']['hpgp_service']['fields']['geolocation_plus_service_area_address'] ) ) {
			$settings['geolocation']['sections']['hpgp_service']['fields']['geolocation_plus_service_area_address']['options'] = $locations;
		}

		return $settings;
	}

	/**
	 * Gets every option of every selectable listing attribute, as "Attribute: Option".
	 *
	 * Admin-defined select, radio and checkbox attributes store their options as terms in a
	 * `hp_listing_{name}` taxonomy. Categories, regions and tags are taxonomies too but are not
	 * attributes an owner would use to mark "done at the customer's address", so they are left
	 * out to keep the list short.
	 *
	 * @return array
	 */
	protected function get_attribute_options() {
		$options  = [];
		$excluded = [ 'hp_listing_category', 'hp_listing_region', 'hp_listing_tags' ];

		foreach ( get_object_taxonomies( 'hp_listing', 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, $excluded, true ) ) {
				continue;
			}

			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'number'     => 200,
				]
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {

				// Term names are stored HTML-encoded ("Home &amp; Garden") and the select escapes
				// its labels again, so decode first or the owner reads the entity.
				$options[ $term->term_id ] = wp_specialchars_decode( $taxonomy->label . ': ' . $term->name, ENT_QUOTES );
			}
		}

		return $options;
	}

	/**
	 * Gets the term taxonomy ID that marks a travelling listing.
	 *
	 * @return int|null Null means every listing of a travelling vendor; 0 means the chosen option
	 *                  no longer exists, so no listing travels (safer than suddenly all of them).
	 */
	protected function get_travelling_term() {
		$term_id = absint( get_option( 'hp_geolocation_plus_service_area_option' ) );

		if ( ! $term_id ) {
			return null;
		}

		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}

		return (int) $term->term_taxonomy_id;
	}

	/**
	 * Adds travelling vendors to a location search.
	 *
	 * The Geolocation extension turns a location search into two BETWEEN ranges on
	 * `hp_latitude` and `hp_longitude`, a box around the searcher
	 * (`hivepress-geolocation/includes/fields/class-latitude.php:84-98`). This rewrites each
	 * range to "inside the box, OR one of these travelling posts". Everything else about the
	 * query is untouched: other filters still apply to travelling posts, the distance sort
	 * still reads the same joined rows, and HivePress's featured-listing lookup, which copies
	 * the same meta_query into a second query, gets the same treatment because it runs through
	 * this filter too.
	 *
	 * It fails safe: if the SQL does not look exactly as expected, it is returned unchanged
	 * and the search behaves as it did before this plugin.
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query $query Query object.
	 * @return string
	 */
	public function widen_location_filter( $where, $query ) {
		global $wpdb;

		if ( ! $query instanceof \WP_Query || ! $query->meta_query instanceof \WP_Meta_Query ) {
			return $where;
		}

		// Only listing and vendor searches.
		$post_type = $query->get( 'post_type' );
		$models    = [
			'hp_listing' => 'listing',
			'hp_vendor'  => 'vendor',
		];

		if ( ! is_string( $post_type ) || ! isset( $models[ $post_type ] ) ) {
			return $where;
		}

		// Find the two location ranges.
		$ranges = [];

		foreach ( $query->meta_query->get_clauses() as $clause ) {
			if ( empty( $clause['key'] ) || empty( $clause['alias'] ) || ! isset( $clause['compare'] ) || 'BETWEEN' !== strtoupper( $clause['compare'] ) ) {
				continue;
			}

			if ( in_array( $clause['key'], [ 'hp_latitude', 'hp_longitude' ], true ) && is_array( $clause['value'] ) && 2 === count( $clause['value'] ) ) {
				$ranges[ $clause['key'] ] = $clause;
			}
		}

		if ( ! isset( $ranges['hp_latitude'], $ranges['hp_longitude'] ) ) {
			return $where;
		}

		// The searcher's point is the centre of the box.
		$latitude  = array_sum( array_map( 'floatval', array_values( $ranges['hp_latitude']['value'] ) ) ) / 2;
		$longitude = array_sum( array_map( 'floatval', array_values( $ranges['hp_longitude']['value'] ) ) ) / 2;

		$ids = $this->get_travelling_ids( $models[ $post_type ], $latitude, $longitude );

		if ( ! $ids ) {
			return $where;
		}

		$id_list = implode( ',', array_map( 'absint', $ids ) );
		$widened = $where;

		foreach ( $ranges as $clause ) {

			// WordPress writes a BETWEEN clause as CAST(alias.meta_value AS TYPE) BETWEEN 'a' AND 'b'
			// (wp-includes/class-wp-meta-query.php, get_sql_for_clause()).
			$pattern = '/CAST\(' . preg_quote( $clause['alias'], '/' ) . '\.meta_value AS [A-Z]+(?:\(\d+(?:,\s?\d+)?\))?\)\s+BETWEEN\s+\'[^\']*\'\s+AND\s+\'[^\']*\'/';
			$count   = 0;

			$widened = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $wpdb, $id_list ) {
					return '(' . $matches[0] . ' OR ' . $wpdb->posts . '.ID IN (' . $id_list . '))';
				},
				$widened,
				1,
				$count
			);

			// One range widened and the other not would be a half-changed search. All or nothing.
			if ( 1 !== $count ) {
				return $where;
			}
		}

		return $widened;
	}

	/**
	 * Gets the published posts whose own service area reaches a point.
	 *
	 * Distance is measured with the spherical law of cosines on the post's own coordinates, and
	 * compared with the radius the vendor entered (capped at the site's longest radius). A
	 * listing uses its vendor's radius, found through `post_parent`, which is where HivePress
	 * keeps a listing's vendor (`hivepress/includes/models/class-listing.php:137-141`).
	 *
	 * @param string $model Model name, listing or vendor.
	 * @param float  $latitude Latitude of the searcher.
	 * @param float  $longitude Longitude of the searcher.
	 * @return array
	 */
	public function get_travelling_ids( $model, $latitude, $longitude ) {
		global $wpdb;

		if ( $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			return [];
		}

		$key = $model . '|' . round( $latitude, 5 ) . '|' . round( $longitude, 5 );

		if ( isset( $this->found[ $key ] ) ) {
			return $this->found[ $key ];
		}

		$factor = $this->uses_miles() ? self::KM_PER_MILE : 1;
		$max    = $this->get_max_radius();

		// A cheap first cut on latitude alone: nobody can be further north or south than the
		// longest radius allows, and that keeps the trigonometry to a handful of rows.
		$span = ( $max * $factor ) / 110.574;

		// 0 below means "any listing": the SQL skips the option test when it is given no option.
		$term_id = 0;

		if ( 'listing' === $model ) {
			$term = $this->get_travelling_term();

			if ( 0 === $term ) {
				$this->found[ $key ] = [];

				return [];
			}

			$term_id = (int) $term;
		}

		// A vendor holds its own radius; a listing borrows its vendor's.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only $wpdb's own table names are interpolated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a per-search distance test cannot be cached ahead of the search; the result is kept for the rest of the request.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} r ON r.post_id = IF( p.post_type = 'hp_vendor', p.ID, p.post_parent ) AND r.meta_key = %s
				INNER JOIN {$wpdb->postmeta} la ON la.post_id = p.ID AND la.meta_key = 'hp_latitude'
				INNER JOIN {$wpdb->postmeta} lo ON lo.post_id = p.ID AND lo.meta_key = 'hp_longitude'
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND ( 0 = %d OR EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				) )
				AND CAST(r.meta_value AS DECIMAL(10,2)) > 0
				AND CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
				AND 6371 * ACOS( LEAST( 1, GREATEST( -1,
					COS( RADIANS( %f ) ) * COS( RADIANS( la.meta_value ) ) * COS( RADIANS( lo.meta_value ) - RADIANS( %f ) )
					+ SIN( RADIANS( %f ) ) * SIN( RADIANS( la.meta_value ) )
				) ) ) <= LEAST( CAST(r.meta_value AS DECIMAL(10,2)), %d ) * %f
				LIMIT %d",
				'hp_' . self::ATTRIBUTE,
				hp\prefix( $model ),
				$term_id,
				$term_id,
				$latitude - $span,
				$latitude + $span,
				$latitude,
				$longitude,
				$latitude,
				$max,
				$factor,
				self::MAX_RESULTS
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->found[ $key ] = array_map( 'absint', (array) $ids );

		return $this->found[ $key ];
	}
}
