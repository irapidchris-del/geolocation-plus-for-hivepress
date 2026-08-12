<?php
/**
 * Map block.
 *
 * @package GeolocationPlus\Blocks
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders a map anywhere on the site.
 *
 * The Geolocation extension's own map block exists only inside HivePress page templates, and
 * only ever plots whatever the page has already queried. This one is a real editor block: it
 * runs its own query, so a map of every listing in one category can be dropped onto an
 * ordinary page, and it carries its own size, zoom and style.
 *
 * Registering it needs nothing but a truthy `label` meta - HivePress turns any labelled block
 * class into a Gutenberg block and a `[hivepress_hpgp_map]` shortcode
 * (`components/class-editor.php:346-362`). The editor renders text, number, select and
 * checkbox settings and nothing else (`assets/js/block.js:44-63`), which is why there is no
 * colour control here; the marker colour is a site-wide setting instead.
 *
 * @class Hpgp_Map
 */
class Hpgp_Map extends Block {

	/**
	 * What to plot.
	 *
	 * @var string
	 */
	protected $source;

	/**
	 * Listing category ID.
	 *
	 * @var int
	 */
	protected $category;

	/**
	 * Maximum number of markers.
	 *
	 * @var int
	 */
	protected $number;

	/**
	 * Show featured only?
	 *
	 * @var bool
	 */
	protected $featured;

	/**
	 * Single marker latitude.
	 *
	 * @var string
	 */
	protected $latitude;

	/**
	 * Single marker longitude.
	 *
	 * @var string
	 */
	protected $longitude;

	/**
	 * Single marker label.
	 *
	 * @var string
	 */
	protected $label;

	/**
	 * Map height in pixels.
	 *
	 * @var int
	 */
	protected $height;

	/**
	 * Fixed zoom level.
	 *
	 * @var int
	 */
	protected $zoom;

	/**
	 * Map style.
	 *
	 * @var string
	 */
	protected $style;

	/**
	 * HTML attributes.
	 *
	 * @var array
	 */
	protected $attributes = [];

	/**
	 * Scattering flag.
	 *
	 * @var bool
	 */
	protected $scatter = false;

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {

		// Offer only what can actually be plotted. A content type without the location features
		// switched on in the Geolocation settings has no coordinates to draw, so listing it
		// here would just be a choice that renders an empty box.
		$models = (array) get_option( 'hp_geolocation_models', [ 'listing' ] );

		$sources = [];

		if ( in_array( 'listing', $models, true ) ) {
			$sources['listings'] = hivepress()->translator->get_string( 'listings' );
		}

		if ( in_array( 'vendor', $models, true ) ) {
			$sources['vendors'] = hivepress()->translator->get_string( 'vendors' );
		}

		$sources['point'] = esc_html__( 'A single place', 'geolocation-plus-for-hivepress' );

		$meta = hp\merge_arrays(
			[
				'label'    => esc_html__( 'Location Map', 'geolocation-plus-for-hivepress' ),

				'settings' => [
					'source'    => [
						'label'    => esc_html__( 'Show', 'geolocation-plus-for-hivepress' ),
						'type'     => 'select',
						'default'  => hp\get_first_array_value( array_keys( $sources ) ),
						'required' => true,
						'_order'   => 10,
						'options'  => $sources,
					],

					'category'  => [
						'label'       => esc_html__( 'Category (listings only)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Only used when listings are shown.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'options'     => 'terms',
						'option_args' => [ 'taxonomy' => 'hp_listing_category' ],
						'_order'      => 20,
					],

					'number'    => [
						'label'       => esc_html__( 'Maximum Markers', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Every marker is drawn at once, so keep this sensible on a large site.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 500,
						'default'     => 50,
						'required'    => true,
						'_order'      => 30,
					],

					// No caption. The block editor renders a checkbox with its label and nothing
					// else (`hivepress/assets/js/block.js:60-63`), so the guidance has to live in
					// the label or it is never seen.
					'featured'  => [
						'label'  => esc_html__( 'Show featured listings only', 'geolocation-plus-for-hivepress' ),
						'type'   => 'checkbox',
						'_order' => 40,
					],

					'latitude'  => [
						'label'       => esc_html__( 'Latitude (single place only)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Only used when a single place is shown, for example 55.953251.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 32,
						'_order'      => 50,
					],

					'longitude' => [
						'label'       => esc_html__( 'Longitude (single place only)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Only used when a single place is shown, for example -3.188267.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 32,
						'_order'      => 60,
					],

					'label'     => [
						'label'       => esc_html__( 'Marker label (single place only)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'The wording shown when the marker is clicked. Only used when a single place is shown.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 256,
						'_order'      => 70,
					],

					'height'    => [
						'label'       => esc_html__( 'Height in pixels (empty fits a square)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Leave this empty to make the map as tall as it is wide, which is what HivePress does elsewhere.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 100,
						'max_value'   => 2000,
						'_order'      => 80,
					],

					'zoom'      => [
						'label'       => esc_html__( 'Zoom level, 1 to 20 (empty fits the markers)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Leave this empty to fit the map around the markers, which is usually what you want. A number from 1 (the whole world) to 20 (a single building) holds the map at that level instead. Only the map providers this plugin adds honour a fixed zoom.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 20,
						'_order'      => 90,
					],

					'style'     => [
						'label'       => esc_html__( 'Map style (empty uses the site setting)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Leave this empty to use the style set on the HivePress Geolocation settings page. Only the map providers this plugin adds have styles.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'options'     => 'hpgp_map_styles',
						'_order'      => 100,
					],
				],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * Seeds each setting's declared default. HivePress applies block defaults in the editor only
	 * (they become the Gutenberg attribute defaults, `components/class-editor.php:158-160`), so a
	 * shortcode arrives with nothing set: `[hivepress_hpgp_map]` drew a single marker instead of
	 * the fifty its own setting advertises, and had no source, height, zoom or style either.
	 *
	 * @param array $args Block arguments.
	 */
	public function __construct( $args = [] ) {
		foreach ( (array) static::get_meta( 'settings' ) as $setting_name => $setting_field ) {
			$default = $setting_field->get_arg( 'default' );

			if ( ! isset( $args[ $setting_name ] ) && ! is_null( $default ) && '' !== $default ) {
				$args[ $setting_name ] = $default;
			}
		}

		parent::__construct( $args );
	}

	/**
	 * Bootstraps block properties.
	 */
	protected function boot() {
		$attributes = [];

		// Set zoom.
		$attributes['data-max-zoom'] = absint( get_option( 'hp_geolocation_max_zoom', 18 ) );

		if ( $this->zoom ) {
			$attributes['data-zoom'] = min( 20, max( 1, absint( $this->zoom ) ) );
		}

		// Set height.
		if ( $this->height ) {
			$attributes['data-height'] = min( 2000, max( 100, absint( $this->height ) ) );
		}

		// Set style.
		if ( $this->style ) {
			$component = hivepress()->hpgp_geolocation;
			$provider  = $component ? $component->get_provider() : null;

			if ( $provider ) {
				$attributes['data-tiles'] = wp_json_encode( $component->get_provider_style( $provider, $this->style ) );
			}
		}

		// Set scattering. A single place the owner typed in themselves is never nudged and is
		// not somebody's home address, so drawing it as a privacy circle would only claim a
		// precision problem that does not exist.
		$this->scatter = (bool) get_option( 'hp_geolocation_hide_address' );

		if ( $this->scatter && 'point' !== $this->source ) {
			$attributes['data-scatter'] = 'true';
		}

		// Reuses the component name the Geolocation extension already binds to, so this block
		// is drawn by whichever script is in charge - theirs on a Google Maps or Mapbox site,
		// ours on the providers this plugin adds.
		$attributes['data-component'] = 'map';

		// Set class.
		$attributes['class'] = [ 'hp-map', 'hp-block' ];

		$this->attributes = hp\merge_arrays( $this->attributes, $attributes );

		parent::boot();
	}

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		$markers = array_filter( $this->get_markers() );

		if ( $this->is_editor_preview() ) {
			return $this->render_placeholder( count( $markers ) );
		}

		if ( ! $markers ) {
			return '';
		}

		return '<div data-markers="' . hp\esc_json( wp_json_encode( array_values( $markers ) ) ) . '" ' . hp\html_attributes( $this->attributes ) . '></div>';
	}

	/**
	 * Whether this render is the block editor asking for a preview.
	 *
	 * Mirrors the check HivePress makes for its own blocks
	 * (`components/class-editor.php:90-94`), which is protected and so cannot be called.
	 *
	 * @return bool
	 */
	protected function is_editor_preview() {
		global $wp;

		if ( ! hp\is_rest() || ! isset( $wp->request ) ) {
			return false;
		}

		return false !== strpos( (string) $wp->request, '/wp/v2/block-renderer/hivepress/' );
	}

	/**
	 * Renders the stand-in shown inside the block editor.
	 *
	 * A real map cannot draw here. The editor asks for this markup over REST and injects it into
	 * the page afterwards, so nothing enqueues Leaflet and nothing fires `hivepress:init` over the
	 * new nodes - the container arrived with no height and no tiles, which read as the block being
	 * broken rather than as a preview limitation. Core has the same limitation and answers it the
	 * same way, with a labelled placeholder (`components/class-editor.php:390`). This one is more
	 * useful than a grey box: it says what the map will contain, so the settings can be checked
	 * without leaving the editor.
	 *
	 * @param int $count Number of markers the map would draw.
	 * @return string
	 */
	protected function render_placeholder( $count ) {
		$lines = [];

		if ( 'point' === $this->source ) {
			$lines[] = $count
				? esc_html__( 'A single place', 'geolocation-plus-for-hivepress' )
				: esc_html__( 'Enter a latitude and longitude to show a place here.', 'geolocation-plus-for-hivepress' );
		} else {
			$lines[] = sprintf(
				/* translators: %s: number of map markers. */
				esc_html( _n( '%s marker', '%s markers', $count, 'geolocation-plus-for-hivepress' ) ),
				esc_html( number_format_i18n( $count ) )
			);

			if ( ! $count ) {
				$lines[] = esc_html__( 'Nothing with coordinates matches these settings yet.', 'geolocation-plus-for-hivepress' );
			}
		}

		$lines[] = $this->height
			? sprintf(
				/* translators: %s: map height in pixels. */
				esc_html__( '%spx tall', 'geolocation-plus-for-hivepress' ),
				esc_html( (string) absint( $this->height ) )
			)
			: esc_html__( 'As tall as it is wide', 'geolocation-plus-for-hivepress' );

		return '<div class="hp-map hpgp-map-placeholder"><strong>' . esc_html__( 'Location Map', 'geolocation-plus-for-hivepress' ) . '</strong><span>' . implode( ' &middot; ', $lines ) . '</span><small>' . esc_html__( 'The map itself is drawn on the published page.', 'geolocation-plus-for-hivepress' ) . '</small></div>';
	}

	/**
	 * Gets the markers to plot.
	 *
	 * @return array
	 */
	protected function get_markers() {
		if ( 'point' === $this->source ) {
			return [ $this->get_point_marker() ];
		}

		$number = min( 500, max( 1, absint( $this->number ) ) );

		if ( 'vendors' === $this->source ) {

			$query = Models\Vendor::query()->filter(
				[
					'status' => 'publish',
				]
			)->order( [ 'registered_date' => 'desc' ] )
			->limit( $number )
			->set_args( $this->get_coordinate_args() );

			return array_map(
				function ( $vendor ) {
					return $this->get_model_marker( 'vendor', $vendor );
				},
				$query->get()->serialize()
			);
		}

		$query = Models\Listing::query()->filter(
			[
				'status' => 'publish',
			]
		)->order( [ 'created_date' => 'desc' ] )
		->limit( $number )
		->set_args( $this->get_coordinate_args() );

		if ( $this->category ) {
			$query->filter( [ 'categories__in' => absint( $this->category ) ] );
		}

		if ( $this->featured ) {
			$query->filter( [ 'featured' => true ] );
		}

		return array_map(
			function ( $listing ) {
				return $this->get_model_marker( 'listing', $listing );
			},
			$query->get()->serialize()
		);
	}

	/**
	 * Gets the query arguments that keep records without coordinates out of the result.
	 *
	 * Written as a raw meta clause rather than the obvious `filter([ 'latitude__exists' => true ])`,
	 * which is a silent no-op. Query::filter() resolves a name against
	 * `$this->model->_get_fields()` (`queries/class-query.php:205`), and the model a static
	 * `Model::query()` builds carries only the model's own declared fields - `latitude` is an
	 * attribute, and attribute fields are attached per loaded object rather than to the class.
	 * The criterion matches nothing, is dropped without error, and the map then spends its
	 * limit on whatever happens to be newest: asked for five markers on a site with eighteen
	 * mapped listings it drew one.
	 *
	 * @return array
	 */
	protected function get_coordinate_args() {

		// An EXISTS clause on an indexed meta key, bounded by the block's own marker limit. The
		// alternative is fetching everything and discarding most of it in PHP, which is slower.
		return [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => [
				'hpgp_latitude' => [
					'key'     => 'hp_latitude',
					'compare' => 'EXISTS',
				],
			],
		];
	}

	/**
	 * Gets the marker for a single place.
	 *
	 * @return array|null
	 */
	protected function get_point_marker() {
		if ( ! is_numeric( $this->latitude ) || ! is_numeric( $this->longitude ) ) {
			return null;
		}

		$latitude  = round( (float) $this->latitude, 6 );
		$longitude = round( (float) $this->longitude, 6 );

		if ( $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			return null;
		}

		$label = (string) $this->label;

		return [
			'title'     => $label,
			'latitude'  => $latitude,
			'longitude' => $longitude,

			// Escaped here because the front-end script writes marker content as HTML, exactly
			// as the Geolocation extension's own markers do.
			'content'   => $label ? '<div class="hp-listing hp-listing--map-block"><h5 class="hp-listing__title"><span>' . esc_html( $label ) . '</span></h5></div>' : '',
		];
	}

	/**
	 * Gets the marker for a model object.
	 *
	 * @param string $model Model name.
	 * @param object $object Model object.
	 * @return array|null
	 */
	protected function get_model_marker( $model, $object ) {
		if ( ! $object || is_null( $object->get_latitude() ) || is_null( $object->get_longitude() ) ) {
			return null;
		}

		$latitude  = (float) $object->get_latitude();
		$longitude = (float) $object->get_longitude();

		// Nudges a marker by up to 100 metres so an exact address cannot be read off the map,
		// as the extension's own block does (blocks/class-listing-map.php:160-163) - but with the
		// range built by hand, because wp_rand() absints its result (wp-includes/pluggable.php)
		// and a negative minimum is silently ignored. Copying the extension verbatim moved every
		// marker north-east only, so anyone who knew the rule could draw a 100 m square to the
		// south-west and be certain the real address was inside it.
		if ( $this->scatter ) {
			$longitude += round( ( wp_rand( 0, 200 ) - 100 ) / 111320, 6 );
			$latitude  += round( ( wp_rand( 0, 200 ) - 100 ) / 110574, 6 );
		}

		$content = '';

		if ( array_key_exists( $model . '_map_block', hivepress()->get_classes( 'templates' ) ) ) {
			$content = ( new Template(
				[
					'template' => $model . '_map_block',

					'context'  => [
						$model => $object,
					],
				]
			) )->render();
		}

		$title = 'vendor' === $model ? $object->get_name() : $object->get_title();

		if ( ! $content ) {
			$content = '<div class="hp-listing hp-listing--map-block"><h5 class="hp-listing__title"><a href="' . esc_url( hivepress()->router->get_url( $model . '_view_page', [ $model . '_id' => $object->get_id() ] ) ) . '">' . esc_html( $title ) . '</a></h5></div>';
		}

		return [
			'title'     => $title,
			'latitude'  => $latitude,
			'longitude' => $longitude,
			'content'   => $content,
		];
	}
}
