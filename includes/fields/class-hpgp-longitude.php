<?php
/**
 * Longitude field.
 *
 * @package GeolocationPlus\Fields
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores the longitude of a custom location attribute.
 *
 * See Hpgp_Latitude for why this is a separate class rather than the extension's own field.
 *
 * @class Hpgp_Longitude
 */
class Hpgp_Longitude extends Number {

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
				'min_value'    => -180,
				'max_value'    => 180,
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
				'data-hpgp-coordinate' => 'lng',
			]
		);

		Field::boot();
	}
}
