<?php
/**
 * Latitude field.
 *
 * @package GeolocationPlus\Fields
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores the latitude of a custom location attribute.
 *
 * A near copy of the Geolocation extension's own Latitude field, kept separate for two
 * reasons. It must not carry that field's `data-coordinate` attribute, because the extension's
 * script matches on it across the whole form and would otherwise mix up the coordinates of two
 * location fields on the same page. And extending a class from another plugin would turn that
 * plugin being deactivated into a fatal error rather than a missing feature.
 *
 * `label => null` keeps it out of the attribute "Field Type" drop-down - it is only ever
 * registered alongside a location attribute, never chosen on its own.
 *
 * @class Hpgp_Latitude
 */
class Hpgp_Latitude extends Number {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Field meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'      => null,
				'filterable' => false,
				'sortable'   => false,
				'settings'   => null,
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
		$args = hp\merge_arrays(
			$args,
			[
				'display_type' => 'hidden',
				'decimals'     => 6,
				'min_value'    => -90,
				'max_value'    => 90,
			]
		);

		parent::__construct( $args );
	}

	/**
	 * Bootstraps field properties.
	 */
	protected function boot() {
		$this->attributes = hp\merge_arrays(
			$this->attributes,
			[
				'data-hpgp-coordinate' => 'lat',
			]
		);

		Field::boot();
	}
}
