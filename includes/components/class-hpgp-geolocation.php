<?php
/**
 * Geolocation Plus component.
 *
 * @package GeolocationPlus\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Extends the HivePress Geolocation extension.
 *
 * @class Hpgp_Geolocation
 */
final class Hpgp_Geolocation extends Component {

	/**
	 * Models that can display a location part.
	 *
	 * The Geolocation extension ships a location template part for these three only
	 * (`templates/{model}/view/{model}-location.php`), so these are the only ones whose part
	 * we can re-point at a formatted copy.
	 *
	 * @var array
	 */
	const PART_MODELS = [ 'listing', 'vendor', 'request' ];

	/**
	 * Suggestion types for the providers the Geolocation extension handles itself.
	 *
	 * Kept here rather than in the provider config because that config doubles as the list of
	 * providers this plugin ADDS to the drop-down, and Google Maps and Mapbox are already on it.
	 *
	 * Google is the modern Places API vocabulary. Its `(cities)` and `(regions)` collections are
	 * deliberately not used: a request may carry at most five primary types, and mixing a
	 * collection with a plain type is rejected outright.
	 *
	 * @var array
	 */
	const NATIVE_TYPES = [
		''       => [
			'country'  => 'country',
			'region'   => 'administrative_area_level_1',
			'district' => 'administrative_area_level_2',
			'place'    => 'locality',
			'locality' => 'sublocality',
			'postcode' => 'postal_code',
			'address'  => 'street_address',
		],

		'mapbox' => [
			'country'  => 'country',
			'region'   => 'region',
			'district' => 'district',
			'place'    => 'place',
			'locality' => 'locality',
			'postcode' => 'postcode',
			'address'  => 'address',
		],
	];

	/**
	 * Default marker colour.
	 *
	 * Matches the scattered-marker colour the Geolocation extension uses for Google Maps
	 * (`assets/js/common.js:350`), so an unconfigured site looks the same on either provider.
	 *
	 * @var string
	 */
	const DEFAULT_MARKER_COLOR = '#3a77ff';

	/**
	 * Models whose next search form should have its location field removed.
	 *
	 * Written by alter_search_block() when a search block carries the hide-location attribute,
	 * and consumed one-shot by alter_search_form_fields() when that block builds its form
	 * moments later, inside the same render call. Keyed by model so two different models'
	 * blocks on one page cannot bleed into each other.
	 *
	 * @var array
	 */
	protected $hide_search_location = [];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Manage assets.
		add_filter( 'hivepress/v1/scripts', [ $this, 'alter_scripts' ], 20 );
		add_filter( 'hivepress/v1/styles', [ $this, 'alter_styles' ], 20 );

		add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_native_scripts' ], 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'dequeue_native_scripts' ], 2 );

		// Restrict location suggestions.
		add_filter( 'hivepress/v1/fields/location', [ $this, 'alter_location_field' ] );

		// Take over region generation.
		add_action( 'init', [ $this, 'replace_region_generation' ], 0 );

		// Give the region terms that already existed a code this plugin can match.
		add_action( 'admin_init', [ $this, 'backfill_region_codes' ] );

		// The background half of region generation. Registered unconditionally, because the
		// Action Scheduler runner is a separate request from the one that queued the job.
		add_action( 'hpgp_update_regions', [ $this, 'run_region_update' ], 10, 2 );

		// Add coordinates to custom location attributes. Deferred to `hivepress/v1/setup`
		// because the list of attribute-enabled models is only settled there - the Attribute
		// component applies its own models filter from that action
		// (`components/class-attribute.php:60`), which is how Bookings and Requests get on it,
		// so asking for the list from a constructor returns nothing at all.
		add_action( 'hivepress/v1/setup', [ $this, 'register_attribute_filters' ], 100 );

		// Add a "hide the location field" toggle to the HivePress search blocks. Registered
		// unconditionally, not inside is_admin(): the meta filter has to run wherever the block
		// classes initialise (the block editor, the REST block-renderer preview and the front
		// end all do), and the instance filter is what carries the choice through to the form.
		foreach ( self::PART_MODELS as $model ) {
			add_filter( 'hivepress/v1/blocks/' . $model . '_search_form/meta', [ $this, 'alter_search_block_meta' ] );
			add_filter( 'hivepress/v1/blocks/' . $model . '_search_form', [ $this, 'alter_search_block' ], 20 );
			add_filter( 'hivepress/v1/forms/' . $model . '_search', [ $this, 'alter_search_form_fields' ], 300 );
		}

		if ( is_admin() ) {

			// Alter settings.
			add_filter( 'hivepress/v1/settings', [ $this, 'alter_settings' ], 20 );

			// Warn about a missing key.
			add_action( 'admin_notices', [ $this, 'render_key_notice' ] );

			// Say so when region generation last failed.
			add_action( 'admin_notices', [ $this, 'render_region_notice' ] );

			// Add the colour picker.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_color_picker' ] );

			// Add the quick links and dividers on the settings screen.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		} else {

			// Link the address to the chosen provider.
			add_filter( 'hivepress/v1/routes', [ $this, 'alter_routes' ], 20 );

			// Format displayed addresses. Per model, because the format, the length limit and
			// the repeated-parts cleanup can each apply to one model and not another.
			foreach ( self::PART_MODELS as $model ) {
				if ( ! $this->is_address_formatted( $model ) ) {
					continue;
				}

				add_filter( 'hivepress/v1/templates/' . $model . '_view_block', [ $this, 'alter_model_template' ], 20 );
				add_filter( 'hivepress/v1/templates/' . $model . '_view_page', [ $this, 'alter_model_template' ], 20 );
			}
		}

		parent::__construct( $args );
	}

	/**
	 * Gets a stored option, falling back to a default when it has never been saved.
	 *
	 * WordPress stores an unticked checkbox and a cleared text box as an empty string, and
	 * HivePress only seeds a field's default on its own activation, so a site that saved the
	 * settings screen once has an empty string where the default should be. Absent means
	 * "use the default", empty string means the owner deliberately cleared it.
	 *
	 * @param string $name Option name without the hp_ prefix.
	 * @param mixed  $fallback Value to use when the option has never been saved.
	 * @return mixed
	 */
	protected function get_option_value( $name, $fallback = null ) {
		$value = get_option( hp\prefix( $name ), null );

		if ( is_null( $value ) || false === $value ) {
			return $fallback;
		}

		return $value;
	}

	/**
	 * Gets a stored number option.
	 *
	 * A cleared number field stores an empty string, and `(int) ''` is zero - which would
	 * silently mean "no minimum length" rather than "use the default". An explicit zero is
	 * numeric and is respected.
	 *
	 * @param string $name Option name without the hp_ prefix.
	 * @param int    $fallback Value to use when the option is not a number.
	 * @return int
	 */
	protected function get_number_option( $name, $fallback ) {
		$value = get_option( hp\prefix( $name ), null );

		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return (int) $value;
	}

	/**
	 * Gets the models that support admin-defined attributes.
	 *
	 * Read from the Attribute component rather than hard-coded, so Bookings and Requests are
	 * included exactly when those extensions are active - both register their models through
	 * the `hivepress/v1/components/attribute/models` filter.
	 *
	 * @return array
	 */
	public function get_attribute_models() {
		$component = hivepress()->attribute;

		if ( ! $component ) {
			return [];
		}

		return array_values( (array) $component->get_models() );
	}

	/**
	 * Registers the attribute filters for every model that supports attributes.
	 */
	public function register_attribute_filters() {
		foreach ( $this->get_attribute_models() as $model ) {
			add_filter( 'hivepress/v1/models/' . $model . '/attributes', [ $this, 'add_location_attributes' ], 200 );
		}
	}

	/**
	 * Gets the configured map providers.
	 *
	 * @return array
	 */
	public function get_providers() {
		return (array) hivepress()->get_config( 'hpgp_map_providers' );
	}

	/**
	 * Gets the selected map provider name.
	 *
	 * An empty string means Google Maps, which is how the Geolocation extension stores its own
	 * default (`includes/configs/settings.php:43` sets it as a placeholder, not a value).
	 *
	 * @return string
	 */
	public function get_provider_name() {
		return (string) get_option( 'hp_geolocation_provider', '' );
	}

	/**
	 * Gets the selected provider's arguments, or null when the Geolocation extension handles it.
	 *
	 * @return array|null
	 */
	public function get_provider() {
		$providers = $this->get_providers();
		$name      = $this->get_provider_name();

		if ( ! isset( $providers[ $name ] ) ) {
			return null;
		}

		return array_merge( $providers[ $name ], [ 'name' => $name ] );
	}

	/**
	 * Gets the API key for the selected provider.
	 *
	 * @param array $provider Provider arguments.
	 * @return string
	 */
	public function get_provider_key( $provider ) {
		$option = hp\get_array_value( $provider, 'key_option' );

		if ( ! $option ) {
			return '';
		}

		return (string) get_option( hp\prefix( $option ), '' );
	}

	/**
	 * Gets the selected map style for a provider.
	 *
	 * @param array  $provider Provider arguments.
	 * @param string $style Requested style name.
	 * @return array
	 */
	public function get_provider_style( $provider, $style = null ) {
		$styles = (array) hp\get_array_value( $provider, 'styles', [] );

		if ( ! $styles ) {
			return [];
		}

		if ( ! $style ) {
			$style = (string) $this->get_option_value( 'geolocation_plus_map_style', '' );
		}

		if ( ! isset( $styles[ $style ] ) ) {
			$style = hp\get_first_array_value( array_keys( $styles ) );
		}

		$args = $styles[ $style ];

		return [
			'url'         => str_replace( '{key}', rawurlencode( $this->get_provider_key( $provider ) ), $args['url'] ),
			'subdomains'  => (string) hp\get_array_value( $args, 'subdomains', 'abc' ),
			'attribution' => (string) hp\get_array_value( $args, 'attribution', hp\get_array_value( $provider, 'attribution', '' ) ),
			'maxZoom'     => absint( hp\get_array_value( $args, 'max_zoom', hp\get_array_value( $provider, 'max_zoom', 19 ) ) ),
		];
	}

	/**
	 * Gets the configured suggestion types.
	 *
	 * @return array
	 */
	public function get_suggestion_types() {
		return array_filter( (array) get_option( 'hp_geolocation_plus_suggestion_types', [] ) );
	}

	/**
	 * Gets the configured address format.
	 *
	 * @return string
	 */
	public function get_address_format() {
		return (string) $this->get_option_value( 'geolocation_plus_address_format', '' );
	}

	/**
	 * Gets the address format for one model, honouring its override.
	 *
	 * Only listings and vendors have an override control; every other model inherits the main
	 * Address Format, which is exactly how the plugin behaved before the overrides existed.
	 * The stored value "full" means "no trimming" and maps to the empty format string - blank
	 * cannot mean that here, because blank already means "inherit".
	 *
	 * @param string $model Model name.
	 * @return string
	 */
	public function get_model_address_format( $model ) {
		if ( in_array( $model, [ 'listing', 'vendor' ], true ) ) {
			$format = (string) $this->get_option_value( 'geolocation_plus_' . $model . '_format', '' );

			if ( 'full' === $format ) {
				return '';
			}

			if ( $format ) {
				return $format;
			}
		}

		return $this->get_address_format();
	}

	/**
	 * Gets the display length limit for one model, zero meaning no limit.
	 *
	 * @param string $model Model name.
	 * @return int
	 */
	public function get_model_max_length( $model ) {
		if ( ! in_array( $model, [ 'listing', 'vendor' ], true ) ) {
			return 0;
		}

		return max( 0, $this->get_number_option( 'geolocation_plus_' . $model . '_max_length', 0 ) );
	}

	/**
	 * True when a model's displayed address differs from the stored one in any way.
	 *
	 * This is what decides whether the model's location template part is re-pointed at our
	 * formatted copy; with nothing to change, the extension's own output renders untouched.
	 *
	 * @param string $model Model name.
	 * @return bool
	 */
	public function is_address_formatted( $model ) {
		return $this->get_model_address_format( $model )
			|| $this->get_model_max_length( $model )
			|| get_option( 'hp_geolocation_plus_dedupe_parts' );
	}

	/**
	 * Removes consecutive repeated address parts.
	 *
	 * Some providers repeat a part back to back - "Edinburgh, Edinburgh, Scotland, United
	 * Kingdom" - and showing it twice reads as a fault. Only CONSECUTIVE repeats go: a suburb
	 * legitimately named after its city ("Manchester Road, Manchester") keeps both, because
	 * removing a non-adjacent repeat changes meaning rather than tidying noise. Byte-wise
	 * comparison with an ASCII case fold, which matches identical provider output exactly.
	 *
	 * @param array $parts Address parts.
	 * @return array
	 */
	protected function dedupe_parts( $parts ) {
		$deduped = [];

		foreach ( $parts as $part ) {
			$last = end( $deduped );

			if ( false === $last || 0 !== strcasecmp( (string) $last, (string) $part ) ) {
				$deduped[] = $part;
			}
		}

		return $deduped;
	}

	/**
	 * Shortens an address for display.
	 *
	 * Purely textual: geocoders return their addresses as comma separated parts ordered from
	 * the most specific to the least, so "Edinburgh, Scotland, United Kingdom" is three parts
	 * and taking the first gives the city. Nothing here touches what is stored, so switching
	 * the setting back restores the full address for every existing entry.
	 *
	 * The model-less form is kept in step with formatAddress() in assets/js/common.js - change
	 * one and change both. The per-model format and length limit are display-time only, so
	 * they have no JavaScript twin.
	 *
	 * @param string $address Full address.
	 * @param string $model Model name, for the per-model overrides. Null applies the main format.
	 * @return string
	 */
	public function format_address( $address, $model = null ) {
		if ( ! is_string( $address ) || '' === trim( $address ) ) {
			return $address;
		}

		$format = $model ? $this->get_model_address_format( $model ) : $this->get_address_format();
		$dedupe = (bool) get_option( 'hp_geolocation_plus_dedupe_parts' );
		$max    = $model ? $this->get_model_max_length( $model ) : 0;

		if ( ! $format && ! $dedupe && ! $max ) {
			return $address;
		}

		// "Everything except the last part" is the one format that is not idempotent: applied to
		// a value the browser already shortened at save time it removes a second part, so the
		// page showed less than was stored and less than the setting describes. Every other
		// format counts from the left and lands on the same answer twice. Only the trimming is
		// skipped; the cleanup and the length limit still apply.
		if ( 'no_last' === $format && get_option( 'hp_geolocation_plus_format_input' ) ) {
			$format = '';
		}

		// Split, trim and drop any empty parts a trailing comma would leave behind. A closure
		// rather than 'strlen', so a part reading "0" is kept.
		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $address ) ),
				function ( $part ) {
					return '' !== $part;
				}
			)
		);

		if ( ! $parts ) {
			return $address;
		}

		if ( $dedupe ) {
			$parts = $this->dedupe_parts( $parts );
		}

		if ( $format && count( $parts ) >= 2 ) {
			switch ( $format ) {
				case 'first':
					$parts = array_slice( $parts, 0, 1 );

					break;

				case 'first_two':
					$parts = array_slice( $parts, 0, 2 );

					break;

				case 'first_last':
					$parts = [ hp\get_first_array_value( $parts ), end( $parts ) ];

					break;

				case 'no_last':
					$parts = array_slice( $parts, 0, -1 );

					break;

				case 'last':
					$parts = [ end( $parts ) ];

					break;

				case 'custom':
					$parts = array_slice( $parts, 0, max( 1, $this->get_number_option( 'geolocation_plus_address_parts', 1 ) ) );

					break;
			}
		}

		$address = implode( ', ', $parts );

		// The length limit runs last, over the already-shortened text, and always leaves the
		// ellipsis so a trimmed address never reads as a complete one.
		if ( $max ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $address ) : strlen( $address );

			if ( $length > $max ) {
				$address = function_exists( 'mb_substr' ) ? mb_substr( $address, 0, $max ) : substr( $address, 0, $max );
				$address = rtrim( $address, " ,\t" ) . '…';
			}
		}

		return $address;
	}

	/**
	 * Gets the data passed to the front-end script.
	 *
	 * @return array
	 */
	public function get_script_data() {
		$name     = $this->get_provider_name();
		$provider = $this->get_provider();
		$types    = $this->get_suggestion_types();

		$data = [
			'provider'          => $name,
			'native'            => is_null( $provider ),
			'minLength'         => max( 1, $this->get_number_option( 'geolocation_plus_min_length', 3 ) ),
			'format'            => $this->get_address_format(),
			'parts'             => max( 1, $this->get_number_option( 'geolocation_plus_address_parts', 1 ) ),
			'formatInput'       => (bool) get_option( 'hp_geolocation_plus_format_input' ),
			'dedupe'            => (bool) get_option( 'hp_geolocation_plus_dedupe_parts' ),

			// Whether the suggestion LIST is drawn already shortened. Display only: what a picked
			// suggestion saves is still governed by formatInput above.
			'formatSuggestions' => (bool) get_option( 'hp_geolocation_plus_format_suggestions' ),

			// Whether named venues are dropped from the suggestion list. The browser filters on
			// each result's own classification, because only some providers can be told in the
			// request.
			'hidePois'          => (bool) get_option( 'hp_geolocation_plus_hide_pois' ),
			'markerColor'       => $this->get_marker_color(),
			'maxZoom'           => absint( get_option( 'hp_geolocation_max_zoom', 18 ) ),
			'scatter'           => (bool) get_option( 'hp_geolocation_hide_address' ),
			// Deduplicated: a live site was seen storing the same code twice, and this list becomes
			// a comma-separated filter on LocationIQ and Geoapify.
			'countries'         => array_values( array_unique( array_filter( (array) get_option( 'hp_geolocation_countries', [] ) ) ) ),
			'language'          => hivepress()->translator->get_language(),

			// Which kinds of place count as a region, so the browser can fill the hidden field
			// that switches a search over to a region page.
			'regionTypes'       => get_option( 'hp_geolocation_generate_regions' ) ? array_values( (array) get_option( 'hp_geolocation_region_types', [ 'place', 'district', 'region', 'country' ] ) ) : [],

			// Not escaped. Their only consumer is jQuery's .text() in common.js, which escapes
			// on its own; running esc_html__() here as well would turn a translated apostrophe
			// into a literal &#039; on screen. Invisible in English, because none of the three
			// source strings contains an escapable character.
			'strings'           => [
				'searching' => __( 'Searching…', 'geolocation-plus-for-hivepress' ),
				'noResults' => __( 'No matching places found', 'geolocation-plus-for-hivepress' ),
				'failed'    => __( 'Location search is unavailable, please type the address instead', 'geolocation-plus-for-hivepress' ),
			],
		];

		if ( is_null( $provider ) ) {

			// Google Maps and Mapbox are drawn by the Geolocation extension. Only our own
			// location fields need a geocoder here, so pass just what one needs.
			$data['geocoder'] = 'mapbox' === $name ? 'mapbox' : 'google';
			$data['key']      = 'mapbox' === $name ? (string) get_option( 'hp_mapbox_api_key', '' ) : (string) get_option( 'hp_gmaps_api_key', '' );
			$data['limit']    = 5;
			$data['types']    = $this->map_types( $types, hp\get_array_value( self::NATIVE_TYPES, $name, [] ) );
			$data['kinds']    = [];

			// Always present, even though nothing fills it here: the browser reads it to decide
			// whether a chosen place is a region, and an undefined value made every lookup
			// return "not a region", which then CLEARED the search form's region field.
			$data['kindMap'] = [];

			return $data;
		}

		// Clamp the language to what this provider accepts. Photon answers an unsupported code
		// with a 400 rather than ignoring it, which would take the whole provider down on any
		// site not running in English, German or French.
		$languages = (array) hp\get_array_value( $provider, 'languages', [] );

		if ( $languages && ! in_array( $data['language'], $languages, true ) ) {
			$data['language'] = 'default';
		}

		$type_args = (array) hp\get_array_value( $provider, 'types', [] );
		$mapped    = $this->map_types( $types, (array) hp\get_array_value( $type_args, 'values', [] ) );

		$data['geocoder']   = (string) hp\get_array_value( $provider, 'geocoder', '' );
		$data['searchUrl']  = (string) hp\get_array_value( $provider, 'search_url', '' );
		$data['reverseUrl'] = (string) hp\get_array_value( $provider, 'reverse_url', '' );
		$data['key']        = $this->get_provider_key( $provider );
		$data['tiles']      = $this->get_provider_style( $provider );
		$data['kinds']      = $this->map_kinds( $types, (array) hp\get_array_value( $provider, 'kinds', [] ) );
		$data['types']      = $mapped;

		// The full table, so the browser can also work out WHICH kind of place a result is -
		// needed to build the region code and to label a suggestion.
		$data['kindMap'] = (array) hp\get_array_value( $provider, 'kinds', [] );

		// The transliteration WordPress would apply to a region name on this site. remove_accents()
		// is locale-aware (German maps u-umlaut to "ue", Danish keeps o-slash as "o"), and no
		// JavaScript approximation reproduces that - a browser that guessed produced
		// "place:munchen" where the server had written "place:muenchen", so the region page was
		// never reached and the search silently fell back to a radius. Sending the table means
		// both sides agree by construction. See get_region_code().
		$data['translit'] = $this->get_translit_map();

		// Tell the provider about the restriction only when it can be told the WHOLE of it.
		// Two things get in the way: some providers accept a single value in their filter
		// parameter, and no provider has a name for every one of our types. Sending a partial
		// restriction is worse than sending none, because the provider would then exclude the
		// types it does not recognise - a site asking for cities and postcodes on Photon, which
		// has no postcode layer, would silently stop offering postcodes. Everything else is
		// trimmed in the browser against "kinds", which is why the request over-fetches.
		$values = (array) hp\get_array_value( $type_args, 'values', [] );

		$data['typeParam'] = (string) hp\get_array_value( $type_args, 'param', '' );
		$data['typeValue'] = '';

		// Photon wants its layers as a repeated parameter; MapTiler and LocationIQ want one
		// comma separated value.
		$data['typeRepeat'] = (bool) hp\get_array_value( $type_args, 'repeat' );

		$covered = ! array_diff( $types, array_keys( array_filter( array_intersect_key( $values, array_flip( $types ) ) ) ) );

		if ( $mapped && $covered ) {
			if ( hp\get_array_value( $type_args, 'multiple' ) ) {
				$data['typeValue'] = $mapped;
			} elseif ( 1 === count( $mapped ) ) {
				$data['typeValue'] = hp\get_first_array_value( $mapped );
			}
		}

		// Over-fetch when the browser has to do the filtering, but never past what the provider
		// accepts - MapTiler documents 10 as its ceiling, and a request over it either 400s or is
		// clamped, which would quietly break the filtering the over-fetch exists to feed.
		//
		// The COUNTRY restriction counts as browser-side filtering too, but only on a provider that
		// cannot be told about it in the request. Photon is the one, and asking it for five results
		// and then discarding the foreign ones is how "Richmond" came back as "No matching places
		// found" on a United Kingdom site: Photon's first five are Virginia, North Carolina,
		// British Columbia, Indiana and Kentucky, and the three British Richmonds sit at positions
		// 8, 10 and 12 (measured live, 2026-08-12). Nothing was wrong with the filter - it simply
		// had nothing left to keep. Ludlow and Boston hid it, because their British entry happens
		// to fall inside the first five.
		$limit    = absint( hp\get_array_value( $provider, 'limit', 5 ) );
		$filtered = (bool) $data['kinds'] || $data['hidePois'];

		if ( $data['countries'] && ! hp\get_array_value( $provider, 'country_param', true ) ) {
			$filtered = true;
		}

		$data['limit'] = $filtered ? min( absint( hp\get_array_value( $provider, 'max_limit', 20 ) ), $limit * 4 ) : $limit;

		return $data;
	}

	/**
	 * Gets the character replacements WordPress would apply to a region name on this site.
	 *
	 * Built by asking remove_accents() itself, one character at a time, rather than by copying a
	 * table: it is filterable and locale-sensitive, so the answer differs between a German site
	 * and a Danish one and only WordPress knows it. Only characters it actually changes are sent.
	 *
	 * @return array
	 */
	public function get_translit_map() {
		$map = [];

		// The Latin letters remove_accents() rewrites: accented forms plus the ones that have no
		// decomposition at all and so can never be handled by the browser's own normalisation.
		$source = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿĀāĂăĄąĆćĈĉĊċČčĎďĐđĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĦħĨĩĪīĬĭĮįİıĲĳĴĵĶķĹĺĻļĽľĿŀŁłŃńŅņŇňŌōŎŏŐőŒœŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŦŧŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽž';

		foreach ( preg_split( '//u', $source, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$replacement = remove_accents( $char );

			if ( $replacement !== $char ) {
				$map[ $char ] = $replacement;
			}
		}

		return $map;
	}

	/**
	 * Maps our suggestion type keys onto a provider's own vocabulary.
	 *
	 * @param array $types Selected type keys.
	 * @param array $values Provider type values.
	 * @return array
	 */
	protected function map_types( $types, $values ) {
		if ( ! $types || ! $values ) {
			return [];
		}

		$mapped = [];

		// A provider value may be a list: MapTiler splits our "City" across `place`,
		// `municipality` and `municipal_district` depending on the country, and naming only one
		// of them silently excludes whole countries.
		foreach ( array_intersect_key( $values, array_flip( $types ) ) as $value ) {
			$mapped = array_merge( $mapped, array_filter( (array) $value ) );
		}

		return array_values( array_unique( $mapped ) );
	}

	/**
	 * Maps our suggestion type keys onto the classifications a provider returns.
	 *
	 * @param array $types Selected type keys.
	 * @param array $kinds Provider result kinds.
	 * @return array
	 */
	protected function map_kinds( $types, $kinds ) {
		if ( ! $types || ! $kinds ) {
			return [];
		}

		$matched = [];

		foreach ( $types as $type ) {
			$matched = array_merge( $matched, (array) hp\get_array_value( $kinds, $type, [] ) );
		}

		return array_values( array_unique( $matched ) );
	}

	/**
	 * Gets the marker colour.
	 *
	 * @return string
	 */
	public function get_marker_color() {
		$color = sanitize_hex_color( (string) get_option( 'hp_geolocation_plus_marker_color', '' ) );

		if ( ! $color ) {
			return self::DEFAULT_MARKER_COLOR;
		}

		return $color;
	}

	/**
	 * Alters the script configuration.
	 *
	 * @param array $scripts Scripts configuration.
	 * @return array
	 */
	public function alter_scripts( $scripts ) {
		if ( ! isset( $scripts['hpgp_geolocation'] ) ) {
			return $scripts;
		}

		if ( is_null( $this->get_provider() ) ) {

			// Google Maps or Mapbox: the Geolocation extension keeps every one of its scripts,
			// and ours simply loads after it so it can wrap hivepress.initGeolocation.
			unset( $scripts['hpgp_leaflet'], $scripts['hpgp_cluster'] );

			if ( isset( $scripts['geolocation'] ) ) {
				$scripts['hpgp_geolocation']['deps'][] = $scripts['geolocation']['handle'];
			}

			return $scripts;
		}

		// One of our providers: the extension's own script would call into a `google` global
		// that is never defined, and its three helper libraries all declare google-maps as a
		// dependency, which would drag the unusable Maps API back in however it was dequeued.
		unset( $scripts['geolocation'], $scripts['geocomplete'], $scripts['markerclustererplus'], $scripts['markerspiderfier'] );

		$scripts['hpgp_geolocation']['deps'][] = 'hpgp-leaflet-markercluster';

		return $scripts;
	}

	/**
	 * Alters the style configuration.
	 *
	 * @param array $styles Styles configuration.
	 * @return array
	 */
	public function alter_styles( $styles ) {
		if ( is_null( $this->get_provider() ) ) {
			unset( $styles['hpgp_leaflet'], $styles['hpgp_cluster'], $styles['hpgp_cluster_default'] );
		}

		return $styles;
	}

	/**
	 * Dequeues the map scripts the Geolocation extension enqueues directly.
	 *
	 * Its Google Maps and Mapbox tags are added from the component on `wp_enqueue_scripts`
	 * at priority 1 rather than through the scripts config, so filtering that config does not
	 * reach them (`hivepress-geolocation/includes/components/class-geolocation.php:258`). With
	 * one of our providers selected the Google tag would carry an empty key, which fails the
	 * request and leaves a console error on every page.
	 */
	public function dequeue_native_scripts() {
		if ( is_null( $this->get_provider() ) ) {
			return;
		}

		// Dequeued rather than deregistered: the handles are generic enough that another
		// plugin may legitimately have registered them, and with the dependencies above
		// removed nothing pulls them back into the queue.
		foreach ( [ 'google-maps', 'mapbox', 'mapbox-geocoder', 'mapbox-language' ] as $handle ) {
			wp_dequeue_script( $handle );
		}

		foreach ( [ 'mapbox', 'mapbox-geocoder' ] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Alters the Geolocation extension's location field.
	 *
	 * Its JavaScript already reads a `data-types` attribute and passes it to whichever
	 * geocoder is in use (`assets/js/common.js:34`, `:87`, `:133`), but nothing ever sets one.
	 * Setting it here is what restricts the suggestion list on Google Maps and Mapbox sites,
	 * with no change to the extension itself.
	 *
	 * @param array $args Field arguments.
	 * @return array
	 */
	public function alter_location_field( $args ) {
		$name   = $this->get_provider_name();
		$types  = $this->get_suggestion_types();
		$mapped = $types ? $this->map_types( $types, hp\get_array_value( self::NATIVE_TYPES, $name, [] ) ) : [];

		// Sliced to five because Google rejects a request carrying more primary types than that.
		// Mapbox has no such ceiling, and slicing its list would break the POI exclusion below,
		// which needs all eight non-POI types named.
		if ( 'mapbox' !== $name ) {
			$mapped = array_slice( $mapped, 0, 5 );
		}

		// Hide places of interest on Google Maps and Mapbox, whose suggestion boxes are drawn by
		// the providers themselves - the browser-side filter in common.js never sees them, so
		// the exclusion has to travel in the request. Only needed when no Suggestion Types are
		// set: the mapped vocabularies above never include a POI type, so any restriction at all
		// already excludes venues. Google's "geocode" collection is its own name for "everything
		// except establishments" (valid for both the modern and the legacy autocomplete);
		// Mapbox has no such collection, so every non-POI type is named instead.
		if ( ! $mapped && is_null( $this->get_provider() ) && get_option( 'hp_geolocation_plus_hide_pois' ) ) {
			$mapped = 'mapbox' === $name
				? [ 'country', 'region', 'postcode', 'district', 'place', 'locality', 'neighborhood', 'address' ]
				: [ 'geocode' ];
		}

		if ( ! $mapped ) {
			return $args;
		}

		// JSON encoded, not a bare array: hp\html_attributes() joins an array with spaces
		// (`helpers.php:395-411`), which is right for a class list and wrong here - the
		// extension's script expects jQuery to hand it back as an array and calls join(',') on
		// it. The extension encodes its own data-countries the same way.
		$args['attributes']['data-types'] = wp_json_encode( $mapped );

		return $args;
	}

	/**
	 * Adds the hide-location toggle to a search block's editor settings.
	 *
	 * A block's settings double as its Gutenberg attributes (`components/class-editor.php`
	 * builds both from the same table), so this one filter gives every Listing Search Form and
	 * Vendor Search Form block a checkbox in the sidebar and stores the choice per block. Per
	 * block rather than a global setting, because the usual reason to hide the field is one
	 * specific placement - a hero already scoped to a city - while the header search keeps it.
	 *
	 * @param array $meta Block meta values.
	 * @return array
	 */
	public function alter_search_block_meta( $meta ) {
		$meta['settings']['hpgp_hide_location'] = [
			'label'   => esc_html__( 'Hide the location field', 'geolocation-plus-for-hivepress' ),
			'caption' => esc_html__( 'Hide the location field', 'geolocation-plus-for-hivepress' ),
			'type'    => 'checkbox',
			'_order'  => 100,
		];

		return $meta;
	}

	/**
	 * Reads the hide-location choice off a search block instance.
	 *
	 * The block builds its form inside its own render() a moment after this filter runs, so
	 * the choice is parked on the component for alter_search_form_fields() to consume. Blocks
	 * rendered from templates never carry the attribute and change nothing here.
	 *
	 * @param array $args Block arguments.
	 * @return array
	 */
	public function alter_search_block( $args ) {
		if ( hp\get_array_value( $args, 'hpgp_hide_location' ) ) {
			$model = explode( '/', current_filter() )[3];
			$model = preg_replace( '/_search_form$/', '', $model );

			$this->hide_search_location[ $model ] = true;
		}

		return $args;
	}

	/**
	 * Removes the location field from a search form the owner asked to hide it on.
	 *
	 * The coordinate and region fields go with it: left behind they are dead weight at best,
	 * and at worst a stale hidden pair that keeps radius-filtering a search whose location box
	 * no longer exists. Priority 300, after the Geolocation extension has added its `_region`
	 * and `_radius` fields at 200 - removing them any earlier would only see them re-added.
	 *
	 * One-shot: the flag is cleared as it is consumed, so a second search form for the same
	 * model on the same page - the header's, say - keeps its location field.
	 *
	 * @param array $form_args Form arguments.
	 * @return array
	 */
	public function alter_search_form_fields( $form_args ) {
		$model = explode( '/', current_filter() )[3];
		$model = preg_replace( '/_search$/', '', $model );

		if ( empty( $this->hide_search_location[ $model ] ) ) {
			return $form_args;
		}

		$this->hide_search_location[ $model ] = false;

		unset(
			$form_args['fields']['location'],
			$form_args['fields']['latitude'],
			$form_args['fields']['longitude'],
			$form_args['fields']['_region'],
			$form_args['fields']['_radius']
		);

		return $form_args;
	}

	/**
	 * Adds coordinate attributes for every custom location attribute.
	 *
	 * An admin-defined attribute is a single model field, but a location needs three values to
	 * be useful: the address people read, and the pair of coordinates that puts it on a map and
	 * puts it on a map. So for each attribute using our location field type we register two more,
	 * hidden, exactly as the Geolocation extension does for its own.
	 *
	 * @param array $attributes Model attributes.
	 * @return array
	 */
	public function add_location_attributes( $attributes ) {
		foreach ( $attributes as $name => $args ) {
			$edit_field = (array) hp\get_array_value( $args, 'edit_field', [] );

			if ( 'hpgp_location' !== hp\get_array_value( $edit_field, 'type' ) ) {
				continue;
			}

			// Fail closed on a name clash. If the owner already has an attribute called
			// "venue_latitude", registering half the pair would leave the location field writing
			// geocoded coordinates straight into THEIR field and wiping it whenever somebody
			// retyped the address - their data destroyed, with no warning. Better to leave the
			// location as a plain address and tell the field to stop looking for coordinates.
			if ( isset( $attributes[ $name . '_latitude' ] ) || isset( $attributes[ $name . '_longitude' ] ) ) {
				$attributes[ $name ]['edit_field']['coordinates'] = false;

				if ( isset( $attributes[ $name ]['search_field'] ) ) {
					$attributes[ $name ]['search_field']['coordinates'] = false;
				}

				continue;
			}

			// Edit fields only. No search field, deliberately.
			//
			// These carried a `latitude`/`longitude` search field at first, meaning to give the
			// attribute a radius search of its own. It could not work and it broke the built-in
			// one. Those extension field classes stamp `data-coordinate`, which the extension's
			// script matches ACROSS THE WHOLE FORM - so searching the main location box wrote its
			// coordinates into the custom attribute's hidden inputs too, and the query then added
			// a BETWEEN clause on `hp_{name}_latitude`. Every listing whose owner had left the
			// custom field blank has no such meta row, so it vanished from the results: a search
			// for Edinburgh returning almost nothing, with no way to connect that to a checkbox
			// on an attribute. And there was never any way to enter a location for it separately,
			// because Hpgp_Location is not offered as a Search field type.
			//
			// Distance searching a second location per listing needs its own search fields and
			// its own location box in the filter form. That is a feature, not a fix, so the
			// attribute is edit-and-display for now and the readme says so.
			$coordinate_types = [
				'latitude'  => 'hpgp_latitude',
				'longitude' => 'hpgp_longitude',
			];

			foreach ( $coordinate_types as $suffix => $field_type ) {
				$coordinate = $name . '_' . $suffix;

				$attributes[ $coordinate ] = [
					'editable'   => true,
					'filterable' => false,
					'searchable' => false,
					'sortable'   => false,

					'edit_field' => [
						'label' => $args['edit_field']['label'] ?? $name,
						'type'  => $field_type,
					],
				];
			}
		}

		return $attributes;
	}

	/**
	 * Points the location link at the selected map provider.
	 *
	 * The Geolocation extension always links a listing's address to a Google Maps search
	 * (`includes/controllers/class-geolocation.php:52`), which is a jarring destination on a
	 * site that deliberately chose another provider. Replacing the route's URL callback fixes
	 * every link at once, including any an extension renders itself.
	 *
	 * @param array $routes Route arguments.
	 * @return array
	 */
	public function alter_routes( $routes ) {
		if ( is_null( $this->get_provider() ) || ! isset( $routes['location_view_page'] ) ) {
			return $routes;
		}

		$routes['location_view_page']['url'] = [ $this, 'get_location_view_url' ];

		return $routes;
	}

	/**
	 * Gets the location link URL.
	 *
	 * @param array $params URL parameters.
	 * @return string
	 */
	public function get_location_view_url( $params ) {
		if ( get_option( 'hp_geolocation_hide_address' ) ) {
			return '#';
		}

		$latitude  = round( (float) hp\get_array_value( $params, 'latitude' ), 6 );
		$longitude = round( (float) hp\get_array_value( $params, 'longitude' ), 6 );

		if ( ! $latitude && ! $longitude ) {
			return '#';
		}

		return 'https://www.openstreetmap.org/?' . http_build_query(
			[
				'mlat' => $latitude,
				'mlon' => $longitude,
			]
		) . '#map=16/' . $latitude . '/' . $longitude;
	}

	/**
	 * Points a model's location part at our formatted copy.
	 *
	 * The part is re-pointed rather than overridden by file name, because parts resolve to the
	 * first matching path across every registered extension (`blocks/class-part.php:39`) and
	 * shipping a file at the same relative path would leave which one wins down to registration
	 * order. Only the `path` argument is merged, so everything else about the block - its order,
	 * its label in the template editor - stays as the extension set it.
	 *
	 * @param array $args Template arguments.
	 * @return array
	 */
	public function alter_model_template( $args ) {
		$model = explode( '/', current_filter() )[3];
		$model = preg_replace( '/_view_(block|page)$/', '', $model );

		// Stand down where the active theme ships its own copy of the part. ExpertHive, JobHive
		// and MeetingHive all do (their address line carries `hp-listing__attribute--location`,
		// which they style), and Part::render() prefers a theme file over every extension path
		// (`blocks/class-part.php:36`) - so re-pointing at ours would throw the theme's markup
		// away and change the look of every card, from a setting called "Address Format".
		// The cost is that the format does not apply on those themes; the setting's description
		// says so.
		if ( locate_template( 'hivepress/' . $model . '/view/' . $model . '-location.php' ) ) {
			return $args;
		}

		return hivepress()->template->merge_blocks(
			$args,
			[
				$model . '_location' => [
					'path' => $model . '/view/hpgp-' . $model . '-location',
				],
			]
		);
	}

	/**
	 * Alters the settings configuration.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function alter_settings( $settings ) {
		if ( ! isset( $settings['geolocation']['sections']['restrictions']['fields']['geolocation_provider'] ) ) {
			return $settings;
		}

		// Add the providers.
		$field = &$settings['geolocation']['sections']['restrictions']['fields']['geolocation_provider'];

		foreach ( $this->get_providers() as $name => $provider ) {
			$field['options'][ $name ] = $provider['label'];
		}

		$field['description'] = hp\get_array_value( $field, 'description', '' ) . ' ' . esc_html__( 'OpenStreetMap needs no account, but it is a free community service, so move to MapTiler, Geoapify or LocationIQ once you have real traffic. Those three need a free API key, entered in the Integrations section. Providers name places slightly differently, so switching on a site with region pages can create duplicates. Suggestions come back in English, German or French only.', 'geolocation-plus-for-hivepress' );

		unset( $field );

		$provider = $this->get_provider();

		// Google Maps and Mapbox draw their own maps, so neither the style nor the marker colour
		// can do anything on those sites. Removing the whole section beats rendering two controls
		// that silently ignore the owner and explaining why in a paragraph and a hover tooltip -
		// a tooltip a phone cannot show at all.
		if ( is_null( $provider ) ) {
			unset( $settings['geolocation']['sections']['hpgp_maps'] );

			return $settings;
		}

		// Add the map styles for the selected provider.
		if ( isset( $settings['geolocation']['sections']['hpgp_maps']['fields']['geolocation_plus_map_style'] ) ) {
			$style_field = &$settings['geolocation']['sections']['hpgp_maps']['fields']['geolocation_plus_map_style'];

			foreach ( (array) hp\get_array_value( $provider, 'styles', [] ) as $name => $style ) {
				$style_field['options'][ $name ] = $style['label'];
			}

			unset( $style_field );
		}

		return $settings;
	}

	/**
	 * Checks whether the settings tab being rendered is one this plugin owns.
	 *
	 * READ THIS BEFORE "FIXING" IT TO USE $_GET['tab']. It cannot: HivePress
	 * falls back to the FIRST tab whenever `tab` is absent from the address
	 * (`hivepress/includes/components/class-admin.php`, `get_settings_tab()`),
	 * so `admin.php?page=hp_settings` renders a real tab that the address does
	 * not name. What the address cannot say, the registered fields can.
	 * `Admin::register_settings()` builds the sections and fields for exactly
	 * one tab and calls `add_settings_field()` with the prefixed option name
	 * (same file, :275-325, verified against the installed core 1.7.31), so
	 * after `admin_init` the `wp_settings_fields` global holds this plugin's
	 * `hp_geolocation_plus_*` keys on the tabs it registers fields on and on no
	 * other - the no-tab fallback included. This plugin owns two of them, the
	 * Geolocation tab and its section of Integrations, and both answer true
	 * here without either being named.
	 *
	 * Timing is the only thing to get right: HivePress registers on
	 * `admin_init` priority 10, and `admin_enqueue_scripts` fires later, from
	 * `admin-header.php`. Call this any earlier and it answers false and the
	 * tab silently loses its assets, which is a worse failure than the one it
	 * fixes. Full rule: resources/hivepress-settings.md, "The tab IS knowable
	 * server-side: ask the registered fields".
	 *
	 * @return bool
	 */
	protected function is_settings_tab() {
		if ( ! isset( $GLOBALS['wp_settings_fields']['hp_settings'] ) || ! is_array( $GLOBALS['wp_settings_fields']['hp_settings'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_settings_fields']['hp_settings'] as $hp_section ) {
			foreach ( array_keys( (array) $hp_section ) as $hp_field ) {
				if ( 0 === strpos( (string) $hp_field, 'hp_geolocation_plus_' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Enqueues the colour picker on the settings screen.
	 *
	 * HivePress ships a Color field but renders no picker for it at all - grepping core for
	 * `wp-color-picker` returns nothing - so the picker is always ours to add. Scoped to the
	 * settings screen rather than declared in the scripts config, which would load WordPress's
	 * picker on every admin page for the sake of one field, and then to this plugin's own tabs,
	 * so no other extension's settings screen carries it.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_color_picker( $hook ) {
		if ( false === strpos( (string) $hook, 'hp_settings' ) || ! $this->is_settings_tab() ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'hpgp-color-picker',
			hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/js/admin-color.js',
			[ 'jquery', 'wp-color-picker' ],
			HPGP_VERSION,
			true
		);

		wp_localize_script(
			'hpgp-color-picker',
			'hpgpColorData',
			[
				'fields'  => [ 'hp_geolocation_plus_marker_color' ],
				'default' => self::DEFAULT_MARKER_COLOR,
			]
		);
	}

	/**
	 * Enqueues the shared settings-screen chrome on this plugin's own tabs.
	 *
	 * The quick-links anchor nav, the sideways floating Save control and the back-to-top button,
	 * copied from the reference implementation in Account Menu Enhancer for HivePress so every
	 * extension in this family puts the same controls in the same places
	 * (resources/hivepress-settings.md, "The settings anchor nav: one shared marker class").
	 *
	 * HivePress renders each settings section as a plain h2 with no anchor, and the screen is
	 * built from config arrays, so none of this can come from PHP; the script reads the headings
	 * WordPress printed and builds the nav around them.
	 *
	 * Two gates, and neither replaces the other: this one decides whether the files load, and the
	 * script's own `[name^="hp_geolocation_plus_"]` test decides whether it acts. Dropping the
	 * second would make the chrome depend on this enqueue never regressing.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_settings_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'hp_settings' ) || ! $this->is_settings_tab() ) {
			return;
		}

		wp_enqueue_style(
			'hpgp-admin-settings',
			hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/css/admin-settings.css',
			[],
			HPGP_VERSION
		);

		wp_enqueue_script(
			'hpgp-admin-settings',
			hivepress()->get_url( 'geolocation_plus_for_hivepress' ) . '/assets/js/admin-settings.js',
			[ 'jquery' ],
			HPGP_VERSION,
			true
		);

		wp_localize_script(
			'hpgp-admin-settings',
			'hpgpBackendData',
			[
				'labels' => [
					// The colon is part of the wording: it reads as a lead-in to the links that
					// follow it, not as a heading over them.
					'jumpTo'          => esc_html__( 'Jump to a section:', 'geolocation-plus-for-hivepress' ),
					'save'            => esc_html__( 'Save Changes', 'geolocation-plus-for-hivepress' ),
					'backToTop'       => esc_html__( 'Back to top', 'geolocation-plus-for-hivepress' ),

					// The quick link for HivePress's own geolocation settings, which open
					// this tab under no heading of their own, so there is no wording on the
					// page to name the link after.
					'defaultSettings' => esc_html__( 'Default Settings', 'geolocation-plus-for-hivepress' ),
				],
			]
		);
	}

	/**
	 * Gets the models the Geolocation extension is switched on for.
	 *
	 * @return array
	 */
	protected function get_geolocation_models() {
		return array_values( array_intersect( self::PART_MODELS, (array) get_option( 'hp_geolocation_models', [ 'listing' ] ) ) );
	}

	/**
	 * Takes over region generation for the providers this plugin adds.
	 *
	 * The Geolocation extension builds its region taxonomies by reverse geocoding through
	 * Google or Mapbox and nothing else (`components/class-geolocation.php:378-401`), so with
	 * one of our providers selected its request would go to Google with an empty key, fail
	 * quietly, and leave "Generate regions from locations" ticked but doing nothing at all.
	 * Swapping the listener keeps the feature working rather than letting it rot.
	 *
	 * Run on `init` rather than from the constructor because component construction order
	 * across extensions is not something to rely on, and these events only fire on a save.
	 */
	public function replace_region_generation() {
		if ( is_null( $this->get_provider() ) || ! get_option( 'hp_geolocation_generate_regions' ) ) {
			return;
		}

		$component = hivepress()->geolocation;

		if ( ! $component ) {
			return;
		}

		foreach ( $this->get_geolocation_models() as $model ) {
			remove_action( 'hivepress/v1/models/' . $model . '/update_longitude', [ $component, 'update_location' ] );

			add_action( 'hivepress/v1/models/' . $model . '/update_longitude', [ $this, 'update_location' ] );
		}
	}

	/**
	 * Gives every region term that already existed a code this plugin can match.
	 *
	 * Region search matches by code alone, and each provider's codes are its own. Generation
	 * adopts a term and adds our code as it goes, but only for regions a listing happens to be
	 * saved into - so on a site with an established tree, searching for any region nobody has
	 * re-saved silently falls through to a radius search. Measured on staging: picking "Scotland"
	 * worked once a listing had been saved there, while "Wales", untouched, returned a 15-mile
	 * radius around the Welsh centroid instead of the twelve listings on the region page.
	 *
	 * A term's type is read from a Mapbox-style code where there is one (`region.9295` carries it
	 * in the prefix), and otherwise from its DEPTH against the site's own Region Types order -
	 * which is exactly the order the tree was built in, so it is a reading rather than a guess.
	 *
	 * Runs once per MODEL, not once per site. The loop below skips any model whose region taxonomy
	 * does not exist yet - a model the owner has not switched on - and the completion flag used to
	 * be written at the end regardless, so switching that model on afterwards left its region
	 * terms permanently without codes and every search of one of them silently fell through to a
	 * radius search. Nothing ever ran again to notice. Recording which models were actually
	 * finished is what makes "once" mean once per thing done rather than once per attempt.
	 *
	 * The option used to hold a version string; anything that is not an array is read as "nothing
	 * recorded" and the backfill runs again, which also repairs a site left half-done by the old
	 * behaviour. That is safe because this only ever ADDS a meta row, never edits or removes one.
	 */
	public function backfill_region_codes() {
		if ( ! get_option( 'hp_geolocation_generate_regions' ) ) {
			return;
		}

		$backfilled = get_option( 'hp_geolocation_plus_codes_backfilled' );
		$backfilled = is_array( $backfilled ) ? array_map( 'strval', $backfilled ) : [];

		$models = $this->get_geolocation_models();

		if ( ! array_diff( $models, $backfilled ) ) {
			return;
		}

		// The order the extension walks when it builds the tree, narrowed to the types this site
		// actually creates. Depth 0 is the first of these, depth 1 the second, and so on.
		$wanted = array_values(
			array_intersect(
				[ 'country', 'region', 'district', 'place', 'locality', 'postcode' ],
				(array) get_option( 'hp_geolocation_region_types', [ 'place', 'district', 'region', 'country' ] )
			)
		);

		if ( ! $wanted ) {
			return;
		}

		// Mapbox and MapTiler both prefix their ids with the type, which is better evidence than
		// depth when it is there.
		$prefixes = [
			'country'  => 'country',
			'region'   => 'region',
			'district' => 'district',
			'place'    => 'place',
			'locality' => 'locality',
			'postcode' => 'postcode',
		];

		foreach ( $models as $model ) {
			if ( in_array( $model, $backfilled, true ) ) {
				continue;
			}

			$taxonomy = hp\prefix( $model . '_region' );

			// Not switched on yet. Left unmarked on purpose, so switching it on later still gets
			// its codes; this costs one taxonomy_exists() per admin request until it does.
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				]
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$codes = (array) get_term_meta( $term->term_id, 'hp_code', false );

				// Work out this term's type.
				$type = '';

				foreach ( $codes as $code ) {
					$prefix = strtok( (string) $code, '.' );

					if ( isset( $prefixes[ $prefix ] ) ) {
						$type = $prefixes[ $prefix ];

						break;
					}
				}

				if ( ! $type ) {
					$depth = 0;
					$cur   = $term;

					while ( $cur && $cur->parent ) {
						++$depth;

						$cur = get_term( $cur->parent, $taxonomy );

						if ( is_wp_error( $cur ) || $depth > 10 ) {
							break;
						}
					}

					$type = isset( $wanted[ $depth ] ) ? $wanted[ $depth ] : '';
				}

				if ( ! $type ) {
					continue;
				}

				$code = $this->get_region_code( $type, $term->name );

				if ( ! in_array( $code, $codes, true ) ) {
					add_term_meta( $term->term_id, 'hp_code', $code );
				}
			}

			// Marked here, inside the loop, so a model that was skipped above is not recorded as
			// finished on the strength of the models that were.
			$backfilled[] = $model;
		}

		update_option( 'hp_geolocation_plus_codes_backfilled', array_values( array_unique( $backfilled ) ) );
	}

	/**
	 * Records why region generation last failed, so it is not a silent no-op.
	 *
	 * Region generation writes nothing when anything goes wrong - a missing key, a rate limit, a
	 * geocoder that returns no recognisable levels - and until this existed the only symptom was
	 * an empty Regions screen. Two separate staging sessions lost time to that, one of them
	 * unable to tell a plugin fault from a site condition without deactivating the plugin to
	 * compare. An owner has even less to go on, so the reason is kept and shown on the settings
	 * screen. Cleared on the next success.
	 *
	 * @param string $reason Plain-English reason, or an empty string on success.
	 */
	protected function set_region_status( $reason = '' ) {
		if ( ! $reason ) {
			if ( get_transient( 'hpgp_region_status' ) ) {
				delete_transient( 'hpgp_region_status' );
			}

			return;
		}

		set_transient( 'hpgp_region_status', $reason, WEEK_IN_SECONDS );
	}

	/**
	 * Shows why region generation is producing nothing.
	 */
	public function render_region_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'hp_settings' ) ) {
			return;
		}

		$reason = get_transient( 'hpgp_region_status' );

		if ( ! $reason || ! get_option( 'hp_geolocation_generate_regions' ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';

		printf(
			/* translators: %s: reason region generation failed. */
			esc_html__( 'Regions are not being created from locations. The last attempt failed because %s. Until this is resolved, listings are saved with their coordinates but are not filed under a region.', 'geolocation-plus-for-hivepress' ),
			esc_html( $reason )
		);

		echo '</p></div>';
	}

	/**
	 * Queues a model's regions to be rebuilt from its coordinates.
	 *
	 * Queues, never looks up. This handler runs INSIDE the visitor's save request, and the lookup
	 * behind it is a blocking call to a third-party geocoder - up to twenty seconds on the free
	 * OpenStreetMap service, which was measured answering in 15 to 38 seconds on real days. One
	 * slow save holds one PHP worker for that whole time, and shared hosting runs a handful of
	 * workers: a few vendors saving listings while the geocoder has a slow day ties up the pool,
	 * and every OTHER visitor's request then queues at the gateway until it gives up. That is how
	 * a per-save delay presents as site-wide 504 errors on a busy site, which is exactly what a
	 * real site with hundreds of daily visitors reported within a week of the first release
	 * (2026-08-19).
	 *
	 * So the save request now only records that the work is needed. Action Scheduler - bundled
	 * with HivePress core and used the same way by the Bookings extension - runs the lookup in the
	 * background moments later, and `Scheduler::add_action()` already refuses a duplicate of a
	 * pending job, so two quick saves of the same listing coalesce into one lookup. The handler
	 * reads the listing's coordinates fresh when it runs, so it always files the LATEST position,
	 * whichever save queued it.
	 *
	 * @param int $model_id Model ID.
	 */
	public function update_location( $model_id ) {
		$action = explode( '/', current_action() );

		$model_name = isset( $action[3] ) ? $action[3] : '';

		if ( ! in_array( $model_name, $this->get_geolocation_models(), true ) ) {
			return;
		}

		$scheduler = hivepress()->scheduler;

		if ( $scheduler ) {
			$scheduler->add_action( 'hpgp_update_regions', [ $model_name, (int) $model_id ] );

			return;
		}

		// No scheduler component means something unusual about the install; a slow save there
		// beats regions silently never being generated at all.
		$this->run_region_update( $model_name, (int) $model_id );
	}

	/**
	 * Rebuilds a model's regions from its coordinates, in the background.
	 *
	 * @param string $model_name Model name.
	 * @param int    $model_id   Model ID.
	 */
	public function run_region_update( $model_name, $model_id ) {
		if ( ! in_array( $model_name, $this->get_geolocation_models(), true ) ) {
			return;
		}

		// Re-checked at RUN time rather than trusted from queue time: the owner may have switched
		// provider or unticked region generation in the gap, and a queued job must not resurrect a
		// setting they just turned off.
		if ( is_null( $this->get_provider() ) || ! get_option( 'hp_geolocation_generate_regions' ) ) {
			return;
		}

		// Get model object.
		$model = hivepress()->model->get_model_object( $model_name, $model_id );

		if ( ! $model ) {
			return;
		}

		// Get coordinates.
		$latitude  = $model->get_latitude();
		$longitude = $model->get_longitude();

		if ( ! $latitude || ! $longitude ) {
			return;
		}

		// Get regions, broadest first.
		$regions = $this->get_regions( (float) $latitude, (float) $longitude );

		if ( ! $regions ) {
			return;
		}

		// Get region taxonomy.
		$taxonomy = hp\prefix( $model_name . '_region' );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->set_region_status( __( 'the region taxonomy is not registered on this site', 'geolocation-plus-for-hivepress' ) );

			return;
		}

		// Walk the hierarchy, adopting or creating each level.
		//
		// Matching by NAME as well as by code is what makes this work on a real site. Any site
		// that has run the Geolocation extension on Google Maps or Mapbox already has a region
		// tree whose terms carry THEIR codes - "England" with `hp_code` of `region.9295`, for
		// instance. A code-only lookup misses every one of those, and `wp_insert_term()` then
		// refuses the name with a `term_exists` error, so the walk gave up and the listing was
		// never filed under any region at all. Silent, and invisible on a fresh install where
		// ours are the only terms there - which is exactly how it reached staging (2026-08-11).
		$region_id = 0;

		foreach ( $regions as $code => $name ) {
			$term_id = $this->get_region_term( $taxonomy, $code, $name, $region_id );

			if ( ! $term_id ) {
				break;
			}

			$region_id = $term_id;
		}

		if ( ! $region_id ) {
			$this->set_region_status( esc_html__( 'the region could not be created in WordPress', 'geolocation-plus-for-hivepress' ) );

			return;
		}

		wp_set_object_terms( $model->get_id(), $region_id, $taxonomy );

		$this->set_region_status();
	}

	/**
	 * Finds or creates one level of the region tree.
	 *
	 * Three passes, in this order:
	 *
	 * 1. By our own `hp_code`, which is the fast path for a term this plugin made.
	 * 2. By name under the same parent, which finds a term the Geolocation extension created on
	 *    Google Maps or Mapbox. Those carry the provider's own opaque id as their code, so pass 1
	 *    can never match them, and there is no sense in having two "England" terms.
	 * 3. Create it.
	 *
	 * An adopted term gets our code ADDED as a second `hp_code` row rather than replacing the one
	 * it already has. Both are needed and neither may be lost:
	 *
	 * - The extension's own code has to survive, or its region archive breaks and a switch back
	 *   to Google Maps or Mapbox stops working.
	 * - Ours has to exist, or region SEARCH is dead. The search matches by code alone
	 *   (`hivepress-geolocation/includes/components/class-geolocation.php:581-596`) and the
	 *   browser mints `region:scotland`, which can never equal Mapbox's `region.17487`. Leaving
	 *   the adopted term uncoded for our format meant that on precisely the migrated sites this
	 *   adoption was written for, picking "Scotland" in the search box matched nothing, fell
	 *   through to a 15-mile radius search around the Scottish centroid, and returned nothing -
	 *   while the region archive page still held every listing.
	 *
	 * Two rows are safe. `get_term_meta( $id, 'hp_code', true )` returns the FIRST by meta_id
	 * (`wp-includes/meta.php`, update_meta_cache orders ASC), so the extension keeps reading its
	 * own; and a meta_value lookup matches either row, with WP_Term_Query adding DISTINCT
	 * whenever a meta clause is present, so the term comes back once.
	 *
	 * @param string $taxonomy Region taxonomy.
	 * @param string $code Region code.
	 * @param string $name Region name.
	 * @param int    $ancestor Parent term ID.
	 * @return int Term ID, or 0 on failure.
	 */
	protected function get_region_term( $taxonomy, $code, $name, $ancestor ) {
		$parent = (int) $ancestor;

		// 1. Ours, by code.
		$term_id = (int) hp\get_first_array_value(
			get_terms(
				[
					'taxonomy'   => $taxonomy,
					'fields'     => 'ids',
					'number'     => 1,
					'hide_empty' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'   => 'hp_code',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => $code,
					'parent'     => $parent,
				]
			)
		);

		if ( $term_id ) {
			return $term_id;
		}

		// 2. Anybody's, by name under the same parent.
		$term_id = (int) hp\get_first_array_value(
			get_terms(
				[
					'taxonomy'   => $taxonomy,
					'fields'     => 'ids',
					'number'     => 1,
					'hide_empty' => false,
					'name'       => $name,
					'parent'     => $parent,
				]
			)
		);

		if ( $term_id ) {
			$codes = (array) get_term_meta( $term_id, 'hp_code', false );

			// add_term_meta, not update_term_meta: the guard keeps repeat saves from piling rows up.
			if ( ! in_array( $code, $codes, true ) ) {
				add_term_meta( $term_id, 'hp_code', $code );
			}

			return $term_id;
		}

		// 3. New.
		$term = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent ] );

		if ( is_wp_error( $term ) ) {

			// Last resort. A race, or a name that collides on slug rather than on name, still
			// hands back the existing ID - which is more useful than giving up on the whole tree.
			$existing = (int) $term->get_error_data( 'term_exists' );

			return $existing ? $existing : 0;
		}

		$term_id = (int) $term['term_id'];

		update_term_meta( $term_id, 'hp_code', $code );

		return $term_id;
	}

	/**
	 * Reverse geocodes a pair of coordinates into an ordered region hierarchy.
	 *
	 * @param float $latitude Latitude.
	 * @param float $longitude Longitude.
	 * @return array Region code => region name, broadest first.
	 */
	protected function get_regions( $latitude, $longitude ) {
		$provider = $this->get_provider();

		if ( is_null( $provider ) ) {
			return [];
		}

		// Only the region types the owner ticked on the Geolocation settings page, in the order
		// a hierarchy has to be built: country first, then everything under it.
		$wanted = array_values(
			array_intersect(
				[ 'country', 'region', 'district', 'place', 'locality', 'postcode' ],
				(array) get_option( 'hp_geolocation_region_types', [ 'place', 'district', 'region', 'country' ] )
			)
		);

		if ( ! $wanted ) {
			$this->set_region_status( __( 'no region types are ticked in the Geolocation settings', 'geolocation-plus-for-hivepress' ) );

			return [];
		}

		$response = $this->request_reverse( $provider, $latitude, $longitude );

		if ( ! $response ) {
			return [];
		}

		$names = $this->parse_reverse( $provider, $response );

		$regions = [];

		foreach ( $wanted as $type ) {
			$name = trim( (string) hp\get_array_value( $names, $type, '' ) );

			if ( '' === $name ) {
				continue;
			}

			$regions[ $this->get_region_code( $type, $name ) ] = $name;
		}

		if ( ! $regions ) {
			$this->set_region_status(
				sprintf(
					/* translators: %s: map provider name. */
					__( '%s did not return any of the region types you have ticked for this location', 'geolocation-plus-for-hivepress' ),
					$provider['label']
				)
			);
		}

		return $regions;
	}

	/**
	 * Builds the stable code stored against a region term.
	 *
	 * The browser has to be able to produce the same code when somebody picks a place from the
	 * suggestion list, because that is what switches a search over to a region page
	 * (`hivepress-geolocation/includes/components/class-geolocation.php:583-594` matches on it).
	 * So it is derived from the type and the name rather than a provider's own identifier,
	 * which differs between a search response and a reverse geocoding response. The same
	 * normalisation is implemented in assets/js/common.js - change one and change both.
	 *
	 * @param string $type Region type.
	 * @param string $name Region name.
	 * @return string
	 */
	public function get_region_code( $type, $name ) {
		$name = (string) $name;
		$slug = remove_accents( $name );

		// remove_accents() only recomposes a decomposed letter when the intl extension is loaded
		// (`wp-includes/formatting.php:1611-1628` runs its normalizer step inside a
		// function_exists() guard). So on a host WITHOUT intl, a name that arrives decomposed -
		// "Zürich" as u + combining diaeresis, which is how several geocoders return it - keeps its
		// combining mark, and the next line turns that mark into its own separator: `place:zu-rich`
		// on that host and `place:zurich` on the identical site next door. The browser always
		// normalises, so the server was the odd one out and the region search silently fell back to
		// a radius on half the hosting estate.
		//
		// Stripping the marks ourselves makes the two agree everywhere. \p{Mn} is exactly the
		// combining-mark category the browser's NFD pass discards.
		if ( preg_match( '/\p{Mn}/u', $slug ) ) {
			$stripped = preg_replace( '/\p{Mn}/u', '', $slug );

			if ( null !== $stripped ) {
				$slug = $stripped;
			}
		}

		$slug = strtolower( $slug );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );

		// A name with no Latin characters at all - 東京, Москва, القاهرة - reduces to nothing, and
		// an empty slug made every region of that type share one code: the second Japanese city
		// saved would find the first city's term and be filed under it, collapsing the whole
		// region tree into a single term. Fall back to the name itself, which term meta stores as
		// UTF-8 without trouble and which the extension's exact-match lookup compares byte for
		// byte anyway.
		if ( '' === $slug ) {
			$slug = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $name ), 'UTF-8' ) : strtolower( trim( $name ) );
		}

		return $type . ':' . $slug;
	}

	/**
	 * Gets the language to ask a provider for, clamped to what it accepts.
	 *
	 * Photon answers a language it does not know with a hard HTTP 400 rather than ignoring the
	 * parameter, so without this every request from a Spanish, Italian or Polish site fails and
	 * the provider is silently unusable there. Shared by the browser payload and every server-side
	 * request so the two can never drift apart.
	 *
	 * @param array $provider Provider arguments.
	 * @return string
	 */
	protected function get_provider_language( $provider ) {
		$language  = hivepress()->translator->get_language();
		$languages = (array) hp\get_array_value( $provider, 'languages', [] );

		if ( $languages && ! in_array( $language, $languages, true ) ) {
			return 'default';
		}

		return $language;
	}

	/**
	 * Requests a reverse geocoding lookup.
	 *
	 * @param array $provider  Provider arguments.
	 * @param float $latitude  Latitude.
	 * @param float $longitude Longitude.
	 * @return array|null
	 */
	protected function request_reverse( $provider, $latitude, $longitude ) {
		$key      = $this->get_provider_key( $provider );
		$geocoder = hp\get_array_value( $provider, 'geocoder' );
		$language = $this->get_provider_language( $provider );
		$url      = hp\get_array_value( $provider, 'reverse_url', '' );

		if ( ! $url || ( hp\get_array_value( $provider, 'key_option' ) && ! $key ) ) {
			$this->set_region_status(
				sprintf(
					/* translators: %s: map provider name. */
					__( 'no API key is saved for %s', 'geolocation-plus-for-hivepress' ),
					$provider['label']
				)
			);

			return null;
		}

		switch ( $geocoder ) {
			case 'maptiler':
				// No `limit`, deliberately. MapTiler rejects it on a REVERSE lookup unless it is
				// paired with exactly one `types` value: the response is
				// HTTP 400 "ERR_VALIDATION: Parameter limit must be combined with a single type
				// parameter when reverse geocoding" (measured against the live API, 2026-08-11).
				// We want every level of the hierarchy back, so a single type is exactly what we
				// cannot send - and asking for a limit at all bought nothing, since a reverse
				// lookup returns one place and its parents either way.
				$url .= rawurlencode( $longitude . ',' . $latitude ) . '.json?' . http_build_query(
					[
						'key'      => $key,
						'language' => $language,
					]
				);

				break;

			case 'geoapify':
				$url .= '?' . http_build_query(
					[
						'lat'    => $latitude,
						'lon'    => $longitude,
						'apiKey' => $key,
						'lang'   => $language,
						'format' => 'json',
					]
				);

				break;

			case 'locationiq':
				$url .= '?' . http_build_query(
					[
						'key'             => $key,
						'lat'             => $latitude,
						'lon'             => $longitude,
						'format'          => 'json',
						'addressdetails'  => 1,
						'accept-language' => $language,
					]
				);

				break;

			default:
				// Photon.
				$url .= '?' . http_build_query(
					[
						'lat'  => $latitude,
						'lon'  => $longitude,
						'lang' => $language,
					]
				);
		}

		$response = wp_remote_get(
			$url,
			[
				// Per provider, because the free community services are slower than the paid ones
				// and ten seconds is not enough for them. Measured on a live site: Photon answered
				// a suggestion request in roughly fifteen seconds - HTTP 200, not a rate limit,
				// just latency - while this lookup gave up at ten and the listing was saved with
				// its coordinates but filed under no region at all (2026-08-12).
				//
				// That failure is asymmetric and easy to miss, which is what makes it worth paying
				// for: the address saves, the region silently does not, and the listing quietly
				// drifts out of its own archive. The settings notice is the only thing that says
				// so.
				//
				// Not raised across the board, and not retried. This call blocks a listing save, so
				// every second here is a second the visitor waits, and a retry doubles the worst
				// case for no gain against a service that is simply slow rather than flaky. The
				// real answer is to stop doing this during the save at all - see the readme's note
				// on region generation - which is a bigger change than a patch release should
				// carry.
				'timeout'    => max( 5, min( 30, absint( hp\get_array_value( $provider, 'reverse_timeout', 10 ) ) ) ),

				// Left to itself WordPress sends "WordPress/{version}; {site url}", handing a
				// third-party geocoder the site's address AND its exact core version on every
				// listing save - a ready-made target list if those logs are ever scraped. Naming
				// the plugin identifies the caller, which is all these services ask for.
				'user-agent' => 'geolocation-plus-for-hivepress/' . HPGP_VERSION,

				// Sent deliberately: it identifies the site to the free community services, and
				// it is what lets an owner restrict a MapTiler or LocationIQ key by referrer and
				// still have these server-side lookups work.
				'headers'    => [
					'Referer' => home_url( '/' ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->set_region_status(
				sprintf(
					/* translators: 1: map provider name, 2: error message. */
					__( 'this site could not reach %1$s (%2$s)', 'geolocation-plus-for-hivepress' ),
					$provider['label'],
					$response->get_error_message()
				)
			);

			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {

			// Quote the provider rather than guessing at the cause. An earlier version blamed the
			// API key for every non-200, which was actively misleading when MapTiler returned 400
			// for a malformed request and the key was perfectly good - it sent a staging session
			// looking at the wrong thing. The body is where the answer actually is.
			$detail = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );

			if ( strlen( $detail ) > 200 ) {
				$detail = substr( $detail, 0, 200 ) . '…';
			}

			$this->set_region_status(
				sprintf(
					/* translators: 1: map provider name, 2: HTTP status code, 3: response body. */
					__( '%1$s answered with HTTP %2$d. It said: %3$s', 'geolocation-plus-for-hivepress' ),
					$provider['label'],
					$status,
					$detail ? $detail : __( '(nothing)', 'geolocation-plus-for-hivepress' )
				)
			);

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Turns a MapTiler reverse response into region names.
	 *
	 * MapTiler is the only provider that answers with an ordered list of features rather than a
	 * keyed address, and its ordering is not a ranking. Two rules, both measured rather than
	 * assumed - the whole method is built on live reverse lookups at seven places on 2026-08-12,
	 * because every attempt to reason about it from one example produced a rule that was wrong
	 * somewhere else:
	 *
	 *   place              Cardiff | Castle Road Allotments | St James Quarter | City Centre
	 *   municipality       Castle  | Newport                | Old Town         | -
	 *   joint_submunicip.  -       | -                      | -                | Manchester
	 *   county             Cardiff | Isle of Wight          | City of Edinburgh| -
	 *
	 * Taking the response's own order gave "Castle" for Cardiff and "Butetown" for Bute Street:
	 * civil parishes, not cities, each with its own archive page (reported from live staging).
	 * Simply preferring `place` instead then gave **Castle Road Allotments** for Newport and
	 * "City Centre" for Manchester, which is the same bug wearing a different hat - `place` is
	 * MapTiler's LOCALITY level, not its city level, and what it holds depends on what happens to
	 * be mapped nearby.
	 *
	 * So:
	 *
	 * 1. A name MapTiler repeats at another level of the same response is the significant one.
	 *    Cardiff is both the `place` and the `county`; that repetition is the provider telling us
	 *    this name matters, and it is what makes "Cardiff" win over "Castle" without hard-coding
	 *    anything about Cardiff.
	 * 2. Otherwise the declared order in the `kinds` table decides, and it now runs settlement
	 *    first: municipality, joint_submunicipality, place. That is MapTiler's own preference -
	 *    the name it puts in its formatted `place_name` is the municipality (Newport, Rhayader,
	 *    Old Town) or the joint_submunicipality (Manchester), never the `place`.
	 *
	 * Scored against the seven measurements this gets six right. The one it does not is a
	 * coordinate in the middle of a large city whose districts are named: Edinburgh city centre
	 * files as "Old Town" under the district "City of Edinburgh". That is a coherent tree and a
	 * documented limit, not a silent wrong answer - the readme says so.
	 *
	 * @param array $provider Provider arguments.
	 * @param array $response Decoded response.
	 * @return array
	 */
	protected function parse_maptiler_reverse( $provider, $response ) {
		$names    = [];
		$kinds    = (array) hp\get_array_value( $provider, 'kinds', [] );
		$features = (array) hp\get_array_value( $response, 'features', [] );

		// How many levels each name appears at, for rule 1.
		$repeats = [];

		foreach ( $features as $feature ) {
			$text = (string) hp\get_array_value( $feature, 'text', '' );

			if ( '' !== $text ) {
				$repeats[ $text ] = hp\get_array_value( $repeats, $text, 0 ) + 1;
			}
		}

		foreach ( $kinds as $type => $values ) {
			$fallback = '';

			foreach ( (array) $values as $value ) {
				foreach ( $features as $feature ) {
					$text = (string) hp\get_array_value( $feature, 'text', '' );

					if ( '' === $text || ! in_array( $value, (array) hp\get_array_value( $feature, 'place_type', [] ), true ) ) {
						continue;
					}

					if ( hp\get_array_value( $repeats, $text, 0 ) > 1 ) {
						$names[ $type ] = $text;

						continue 3;
					}

					if ( '' === $fallback ) {
						$fallback = $text;
					}
				}
			}

			if ( '' !== $fallback ) {
				$names[ $type ] = $fallback;
			}
		}

		// Every feature carries the country it is in, and the city check below is worthless
		// without it - see the note there.
		$country = '';

		foreach ( $features as $feature ) {
			$code = (string) hp\get_array_value( (array) hp\get_array_value( $feature, 'properties', [] ), 'country_code', '' );

			if ( $code ) {
				$country = $code;

				break;
			}
		}

		return $this->resolve_maptiler_city( $provider, $names, $country );
	}

	/**
	 * Uses the district name as the city where MapTiler says the district is also a city.
	 *
	 * The repetition rule above needs the city to appear at two levels, and a few hundred metres
	 * decides whether it does. Picking the city "Cardiff" from the suggestion list gives a point
	 * where `place` is "Cardiff" and `county` is "Cardiff", so it fires. A plain Cardiff street
	 * address - St Mary Street, which is what somebody submitting a listing would actually enter -
	 * gives `place` = "Cardiff City Centre", `municipality` = "Castle", `county` = "Cardiff". No
	 * name is repeated, so the parish "Castle" won again (live staging, 2026-08-12). Nottingham is
	 * the same shape: `place` = "Chapel Quarter", `county` = "Nottingham".
	 *
	 * What Cardiff and Nottingham have in common is that the district IS the city - both are
	 * unitary authorities - while the Isle of Wight, Powys and Greater Manchester are not, and
	 * there is nothing in the reverse response that says which is which. Three string rules were
	 * tried against the measurements and each fixed one town and broke another.
	 *
	 * So ask MapTiler, in its own vocabulary. A forward lookup restricted to city types answers
	 * exactly this question, measured 2026-08-12:
	 *
	 *   Cardiff YES (place)      Isle of Wight no        Greater Manchester no
	 *   Nottingham YES (place)   Powys no                City of Edinburgh no
	 *   Bristol YES (place)      Perth and Kinross no    North East YES, but as a MUNICIPALITY
	 *
	 * Hence the `place` requirement rather than any city-ish match: without it "North East" would
	 * override "Newcastle upon Tyne", which is precisely the mistake this is here to prevent.
	 *
	 * One request per distinct district name, cached for a month. Districts repeat across every
	 * listing on a site, so in practice this is a handful of requests in the life of the site, and
	 * a failed lookup changes nothing and is not cached.
	 *
	 * @param array  $provider Provider arguments.
	 * @param array  $names    Region names keyed by our type.
	 * @param string $country  Two-letter country code the coordinates fell in.
	 * @return array
	 */
	protected function resolve_maptiler_city( $provider, $names, $country ) {
		$city     = (string) hp\get_array_value( $names, 'place', '' );
		$district = (string) hp\get_array_value( $names, 'district', '' );

		if ( '' === $city || '' === $district || $city === $district ) {
			return $names;
		}

		if ( ! $this->is_maptiler_city( $provider, $district, $country ) ) {
			return $names;
		}

		$names['place'] = $district;

		// Leaving both would nest a term inside another of the same name - "Cardiff" in "Cardiff" -
		// on any site that also ticked District. The city is the one to keep, because it is what
		// the browser mints a code from when somebody searches for it.
		unset( $names['district'] );

		return $names;
	}

	/**
	 * Asks MapTiler whether a name is one of its cities, and remembers the answer.
	 *
	 * The country is not optional. Asked without one, "Isle of Wight" and "Powys" both come back
	 * as cities - there are places of those names elsewhere in the world - and the Isle of Wight
	 * promptly replaced Newport as the city of every listing on it. Restricted to the country the
	 * coordinates actually fell in, both correctly answer no while Cardiff and Nottingham still
	 * answer yes (measured 2026-08-12). Without a country code the question cannot be answered
	 * safely, so it is not asked.
	 *
	 * @param array  $provider Provider arguments.
	 * @param string $name     Place name.
	 * @param string $country  Two-letter country code.
	 * @return bool
	 */
	protected function is_maptiler_city( $provider, $name, $country ) {
		if ( ! $country ) {
			return false;
		}

		$cache = 'hpgp_maptiler_city_' . md5( strtolower( $country . '|' . $name ) );
		$known = get_transient( $cache );

		if ( false !== $known ) {
			return '1' === $known;
		}

		$key = $this->get_provider_key( $provider );

		if ( ! $key ) {
			return false;
		}

		$response = wp_remote_get(
			hp\get_array_value( $provider, 'search_url', '' ) . rawurlencode( $name ) . '.json?' . http_build_query(
				[
					'key'      => $key,
					'language' => $this->get_provider_language( $provider ),
					'limit'    => 5,
					'types'    => 'place',
					'country'  => $country,
				]
			),
			[
				'timeout'    => 10,
				'user-agent' => 'geolocation-plus-for-hivepress/' . HPGP_VERSION,
			]
		);

		// Not cached. A timeout or a rate limit is not an answer, and remembering it as "no" would
		// keep a whole site's cities wrong for a month.
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$match = false;

		foreach ( (array) hp\get_array_value( (array) $body, 'features', [] ) as $feature ) {
			if ( 0 === strcasecmp( trim( (string) hp\get_array_value( $feature, 'text', '' ) ), $name ) ) {
				$match = true;

				break;
			}
		}

		set_transient( $cache, $match ? '1' : '0', MONTH_IN_SECONDS );

		return $match;
	}

	/**
	 * Turns a reverse geocoding response into region names keyed by our region types.
	 *
	 * @param array $provider Provider arguments.
	 * @param array $response Decoded response.
	 * @return array
	 */
	protected function parse_reverse( $provider, $response ) {
		$names = [];

		if ( 'maptiler' === hp\get_array_value( $provider, 'geocoder' ) ) {
			return $this->parse_maptiler_reverse( $provider, $response );
		}

		// Photon answers with a GeoJSON feature whose properties carry the hierarchy; Geoapify
		// with a results list; LocationIQ with an address object. All three use the same key
		// names inside, so one lookup table covers them.
		$address = (array) hp\get_array_value( $response, 'address', [] );

		if ( ! $address ) {
			$features = (array) hp\get_array_value( $response, 'features', [] );
			$feature  = (array) hp\get_first_array_value( $features );

			$address = (array) hp\get_array_value( $feature, 'properties', [] );
		}

		if ( ! $address ) {
			$address = (array) hp\get_array_value( $response, 'properties', [] );
		}

		if ( ! $address ) {
			$results = (array) hp\get_array_value( $response, 'results', [] );

			$address = (array) hp\get_first_array_value( $results );
		}

		if ( ! $address ) {
			return $names;
		}

		$mapping = [
			'country'  => [ 'country' ],
			'region'   => [ 'state', 'region', 'province' ],
			'district' => [ 'county', 'state_district' ],
			'place'    => [ 'city', 'town', 'village', 'municipality', 'hamlet' ],
			'locality' => [ 'suburb', 'neighbourhood', 'city_district', 'district', 'quarter' ],
			'postcode' => [ 'postcode' ],
		];

		foreach ( $mapping as $type => $keys ) {
			foreach ( $keys as $key ) {
				$value = hp\get_array_value( $address, $key );

				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$names[ $type ] = trim( $value );

					break;
				}
			}
		}

		// A record often describes ITSELF rather than listing itself among its parents. Reverse
		// geocoding the centre of Edinburgh returns the city record - `type: city`, `name:
		// Edinburgh`, a state and a country, and no `city` key at all - so the loop above finds
		// Scotland and the United Kingdom and loses the city, which is exactly the region most
		// sites care about. Read the record's own classification through the provider's kinds
		// table and fill in whatever it turns out to be.
		// Read from the response as well as the narrowed address object. Photon and Geoapify put
		// the classification inside the properties we already extracted; LocationIQ puts `type`
		// and `display_name` at the top level with only the parts inside `address`, so looking
		// only at $address made this whole fallback dead code there - and it is exactly the
		// hamlet-with-no-city case it was written for.
		$self_kind = hp\get_array_value( $address, 'type', hp\get_array_value( $address, 'result_type', hp\get_array_value( $response, 'type' ) ) );
		$self_name = hp\get_array_value( $address, 'name', hp\get_array_value( $response, 'name' ) );

		if ( ! $self_name ) {
			$display = (string) hp\get_array_value( $response, 'display_name', '' );

			$self_name = $display ? trim( (string) hp\get_first_array_value( explode( ',', $display ) ) ) : '';
		}

		if ( $self_kind && is_string( $self_name ) && '' !== trim( $self_name ) ) {
			foreach ( (array) hp\get_array_value( $provider, 'kinds', [] ) as $type => $kinds ) {
				if ( in_array( $self_kind, (array) $kinds, true ) && ! isset( $names[ $type ] ) ) {
					$names[ $type ] = trim( $self_name );

					break;
				}
			}
		}

		return $names;
	}

	/**
	 * Warns when the selected provider has no API key.
	 *
	 * Shown only on the HivePress settings screen, which is both where the problem is visible
	 * and where it is fixed - a map that silently renders grey tiles gives no clue at all.
	 */
	public function render_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'hp_settings' ) ) {
			return;
		}

		$provider = $this->get_provider();

		if ( is_null( $provider ) || ! hp\get_array_value( $provider, 'key_option' ) || $this->get_provider_key( $provider ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';

		printf(
			/* translators: %s: map provider name. */
			esc_html__( '%s is selected as the map provider but no API key has been saved for it yet, so maps and location suggestions will not work. Add the key in the Integrations section.', 'geolocation-plus-for-hivepress' ),
			esc_html( $provider['label'] )
		);

		echo '</p></div>';
	}
}
