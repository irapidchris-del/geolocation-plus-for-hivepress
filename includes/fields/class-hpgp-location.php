<?php
/**
 * Location field.
 *
 * @package GeolocationPlus\Fields
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A location field that can be chosen for an admin-defined attribute.
 *
 * The Geolocation extension has a location field of its own, but it declares `label => null`
 * (`includes/fields/class-location.php:37`), and HivePress only offers a field type in the
 * attribute "Field Type" drop-down when the class has a label and the meta the drop-down asks
 * for (`components/class-form.php:352-356`). That is the entire reason a Location attribute
 * cannot be created by hand today, and the entire reason this class exists.
 *
 * It deliberately extends core's Text rather than the extension's Location: HivePress builds
 * its field list by globbing the directory and calling a static method on every class it
 * finds, so a parent class from a deactivated extension would be a fatal error rather than a
 * missing feature.
 *
 * @class Hpgp_Location
 */
class Hpgp_Location extends Text {

	/**
	 * Whether companion coordinate fields exist for this attribute.
	 *
	 * @var bool
	 */
	protected $coordinates = true;

	/**
	 * Class initializer.
	 *
	 * @param array $meta Field meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'      => esc_html__( 'Location', 'geolocation-plus-for-hivepress' ),

				// Not offered as a Search field type. Chosen there it would render a location box
				// with a working suggestion list but no coordinate inputs behind it - those are
				// registered only for the EDIT field - so picking a place would write nowhere and
				// the text would be wiped on blur. Distance filtering already comes from the
				// latitude and longitude search fields the component registers alongside.
				// It also stops Text::add_filter() adding a LIKE clause on the address string on
				// top of the coordinate BETWEEN clauses, which would have excluded every nearby
				// listing whose address is not character-identical.
				'filterable' => false,
				'sortable'   => false,

				'settings'   => [

					// A length or a regular expression makes no sense for a value the visitor
					// picks from a list rather than types. Nulls are dropped by Field::set_meta().
					'min_length' => null,
					'max_length' => null,
					'pattern'    => null,
				],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Field arguments.
	 */
	public function __construct( $args = [] ) {

		// Defaults FIRST, so a caller's arguments still win - hp\merge_arrays() lets the last
		// array win for scalars (helpers.php:152-170). With the order reversed this pinned
		// display_type to 'location' permanently, which overrode the 'hidden' that core sets for
		// a field on the sort form (components/class-attribute.php:1383) and rendered a full
		// labelled location input, locate-me arrow and all, inside the sort bar.
		$args = hp\merge_arrays(
			[

				// Reuses the Geolocation extension's own field styling, which lays the "locate
				// me" button over the right-hand end of the input (assets/css/frontend.less:8).
				'display_type' => 'location',
				'max_length'   => 256,
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Bootstraps field properties.
	 */
	protected function boot() {
		$attributes = [];

		// Our own component name, not the extension's. Sharing "location" would hand this field
		// to the extension's script, which looks up its coordinate inputs by a form-wide
		// selector (assets/js/common.js:11-12) and would write this field's coordinates into
		// the listing's main location, and vice versa, whenever both appear on one form.
		$attributes['data-component'] = 'hpgp-location';

		// Coordinates are stored in two more attributes named after this one, registered by the
		// component. Naming them here is what keeps several location fields on one form apart.
		//
		// The component sets `coordinates => false` when those names were already taken by an
		// attribute the owner created. That case must say so EXPLICITLY rather than just omitting
		// the names: with no names to go on the script falls back to the extension's form-wide
		// `input[data-coordinate=lat]` selector, which on a listing form is the listing's OWN
		// latitude and longitude - so the guard meant to protect the owner's data would instead
		// have this field quietly rewrite the listing's real position every time somebody picked
		// a suggestion.
		if ( false !== $this->coordinates ) {
			$attributes['data-lat-field'] = $this->name . '_latitude';
			$attributes['data-lng-field'] = $this->name . '_longitude';
		} else {
			$attributes['data-no-coordinates'] = 'true';
		}

		// Set countries.
		//
		// Deduplicated because the stored option is not guaranteed to be. A live site was seen
		// rendering data-countries as ["GB","GB"]; harmless to Google, but LocationIQ and Geoapify
		// receive this list as a comma-separated filter string, and a repeated code is wasted
		// characters at best. Not reproducible from the option on a clean site, so this hardens
		// our own field rather than claiming to fix the cause.
		$countries = array_values( array_unique( array_filter( (array) get_option( 'hp_geolocation_countries', [] ) ) ) );

		if ( $countries ) {
			$attributes['data-countries'] = wp_json_encode( $countries );
		}

		$this->attributes = hp\merge_arrays( $this->attributes, $attributes );

		Field::boot();
	}

	/**
	 * Gets the value shown to visitors.
	 *
	 * @return mixed
	 */
	public function get_display_value() {
		$component = hivepress()->hpgp_geolocation;

		if ( ! $component || is_null( $this->value ) ) {
			return $this->value;
		}

		return $component->format_address( $this->value );
	}

	/**
	 * Renders field HTML.
	 *
	 * @return string
	 */
	public function render() {
		$output = '<div ' . hp\html_attributes( $this->attributes ) . '>';

		// Render field.
		$output .= ( new Text(
			array_merge(
				$this->args,
				[
					'display_type' => 'text',
					'default'      => $this->value,

					'attributes'   => [
						'autocomplete' => 'off',
					],
				]
			)
		) )->render();

		// Render button.
		$output .= '<a href="#" title="' . esc_attr__( 'Locate Me', 'geolocation-plus-for-hivepress' ) . '"><i class="hp-icon fas fa-location-arrow"></i></a>';

		$output .= '</div>';

		return $output;
	}
}
