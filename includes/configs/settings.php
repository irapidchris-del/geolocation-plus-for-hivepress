<?php
/**
 * Settings configuration.
 *
 * Everything here is merged into the Geolocation extension's own settings tab rather than
 * given a tab of its own, so an owner configuring locations finds all of it in one place.
 * The dynamic parts - the extra map providers, and the map styles that depend on which
 * provider is chosen - are added by the component instead, because they are not static.
 *
 * Copy style: descriptions carry the main points only. The evidence behind each rule lives in
 * code comments, not in the tooltip - a tooltip that scrolls is a tooltip nobody reads.
 *
 * @package GeolocationPlus\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Without the Geolocation extension there is no tab to extend, and every setting below
// would be an inert control on a screen the owner cannot act on. The admin notice in the
// main plugin file explains what is missing.
//
// Every section here belongs to the Geolocation tab, including the delete-data one. An earlier
// build put that single section on a "general" tab so it would survive the Geolocation extension
// being switched off - but HivePress has no such tab (its own are listings, vendors, users and
// integrations, `hivepress/includes/configs/settings.php`), so the key invented a brand-new
// titleless tab holding one orphaned control. Keeping our settings together where an owner
// expects them beats covering an edge case nobody has hit.
if ( ! hivepress()->get_version( 'geolocation' ) ) {
	return [];
}

// The per-model format overrides share one option list. "Same as Address Format" is the blank
// default so an existing site keeps behaving exactly as before the overrides existed, and
// "Full address" is a distinct value because blank already means "inherit".
$hpgp_override_formats = [
	''           => esc_html__( 'Same as the Address Format above', 'geolocation-plus-for-hivepress' ),
	'full'       => esc_html__( 'Full address', 'geolocation-plus-for-hivepress' ),
	'first'      => esc_html__( 'First part only (Edinburgh)', 'geolocation-plus-for-hivepress' ),
	'first_two'  => esc_html__( 'First two parts (Edinburgh, Scotland)', 'geolocation-plus-for-hivepress' ),
	'first_last' => esc_html__( 'First and last parts (Edinburgh, United Kingdom)', 'geolocation-plus-for-hivepress' ),
	'no_last'    => esc_html__( 'Everything except the last part (Edinburgh, Scotland)', 'geolocation-plus-for-hivepress' ),
	'last'       => esc_html__( 'Last part only (United Kingdom)', 'geolocation-plus-for-hivepress' ),
];

return [
	'geolocation'  => [
		'sections' => [
			'hpgp_display'     => [
				'title'       => esc_html__( 'Address Display', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'Shortens the addresses visitors see, such as "Edinburgh" instead of "Edinburgh, Scotland, United Kingdom". Stored addresses are left alone unless the Saved Value setting is ticked, so you can switch back at any time.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [
					'geolocation_plus_address_format'     => [
						'label'       => esc_html__( 'Address Format', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'How much of each saved address to show. Addresses are split on commas, counted from the left. Themes that draw their own address line (ExpertHive, JobHive, MeetingHive) keep it, so this has no effect there.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'statuses'    => [ 'optional' => null ],
						'_order'      => 10,

						'options'     => [
							''           => esc_html__( 'Full address', 'geolocation-plus-for-hivepress' ),
							'first'      => esc_html__( 'First part only (Edinburgh)', 'geolocation-plus-for-hivepress' ),
							'first_two'  => esc_html__( 'First two parts (Edinburgh, Scotland)', 'geolocation-plus-for-hivepress' ),
							'first_last' => esc_html__( 'First and last parts (Edinburgh, United Kingdom)', 'geolocation-plus-for-hivepress' ),
							'no_last'    => esc_html__( 'Everything except the last part (Edinburgh, Scotland)', 'geolocation-plus-for-hivepress' ),
							'last'       => esc_html__( 'Last part only (United Kingdom)', 'geolocation-plus-for-hivepress' ),
							'custom'     => esc_html__( 'A set number of parts', 'geolocation-plus-for-hivepress' ),
						],
					],

					'geolocation_plus_address_parts'      => [
						'label'       => esc_html__( 'Number of Parts (used with "A set number of parts")', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'How many comma separated parts to show, counted from the left.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 10,
						'default'     => 1,
						'_order'      => 20,
					],

					'geolocation_plus_dedupe_parts'       => [
						'label'       => esc_html__( 'Repeated Parts', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Some providers repeat a part, such as "Edinburgh, Edinburgh, Scotland". Tick this to show each repeated part once, wherever addresses appear.', 'geolocation-plus-for-hivepress' ),
						'caption'     => esc_html__( 'Remove repeated address parts', 'geolocation-plus-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 25,
					],

					'geolocation_plus_listing_format'     => [
						'label'       => esc_html__( 'Listing Address Format', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Overrides the Address Format above for listing locations only.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'statuses'    => [ 'optional' => null ],
						'options'     => $hpgp_override_formats,
						'_order'      => 40,
					],

					'geolocation_plus_listing_max_length' => [
						'label'       => esc_html__( 'Listing Address Length (characters)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Trims the displayed listing address to this many characters, ending with an ellipsis. Leave it empty for no limit.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 200,
						'statuses'    => [ 'optional' => null ],
						'_order'      => 50,
					],

					'geolocation_plus_vendor_format'      => [
						'label'       => esc_html__( 'Vendor Address Format', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Overrides the Address Format above for vendor locations only.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'statuses'    => [ 'optional' => null ],
						'options'     => $hpgp_override_formats,
						'_order'      => 60,
					],

					'geolocation_plus_vendor_max_length'  => [
						'label'       => esc_html__( 'Vendor Address Length (characters)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Trims the displayed vendor address to this many characters, ending with an ellipsis. Leave it empty for no limit.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 200,
						'statuses'    => [ 'optional' => null ],
						'_order'      => 70,
					],

					'geolocation_plus_format_input'       => [
						'label'       => esc_html__( 'Saved Value', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'By default the full address is saved and only shortened for display. Tick this to save the shortened address as people pick a suggestion. Existing entries are not changed. Uses the main Address Format above.', 'geolocation-plus-for-hivepress' ),
						'caption'     => esc_html__( 'Save the shortened address instead', 'geolocation-plus-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 80,
					],
				],
			],

			'hpgp_suggestions' => [
				'title'       => esc_html__( 'Location Suggestions', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'Controls the list of places offered while somebody types in a location field. Restricting it is the tidiest way to keep saved locations consistent.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'geolocation_plus_suggestion_types'   => [
						'label'       => esc_html__( 'Suggestion Types', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'The kinds of place people may choose. Leave it empty to allow everything. Applies to the main location field only, never to the Location attributes you create. Geocoders vary in how strictly they honour this.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'multiple'    => true,
						'statuses'    => [ 'optional' => null ],
						'_order'      => 10,

						'options'     => [
							'country'  => esc_html__( 'Country', 'geolocation-plus-for-hivepress' ),
							'region'   => esc_html__( 'Region', 'geolocation-plus-for-hivepress' ),
							'district' => esc_html__( 'Subregion', 'geolocation-plus-for-hivepress' ),
							'place'    => esc_html__( 'City', 'geolocation-plus-for-hivepress' ),
							'locality' => esc_html__( 'District', 'geolocation-plus-for-hivepress' ),
							'postcode' => esc_html__( 'Postcode', 'geolocation-plus-for-hivepress' ),
							'address'  => esc_html__( 'Street address', 'geolocation-plus-for-hivepress' ),
						],
					],

					'geolocation_plus_hide_pois'          => [
						'label'       => esc_html__( 'Places of Interest', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Hides named venues such as "Edinburgh Castle" from the suggestion list, so people pick addresses and place names instead. Applies to the main location field only.', 'geolocation-plus-for-hivepress' ),
						'caption'     => esc_html__( 'Hide places of interest from suggestions', 'geolocation-plus-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 15,
					],

					'geolocation_plus_format_suggestions' => [
						'label'       => esc_html__( 'Shorten Suggestions', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Shows each suggestion already shortened by the Address Format. With Google Maps or Mapbox selected the built-in location field draws its own list, which cannot be shortened.', 'geolocation-plus-for-hivepress' ),
						'caption'     => esc_html__( 'Apply the Address Format to suggestions', 'geolocation-plus-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 17,
					],

					'geolocation_plus_min_length'         => [
						'label'       => esc_html__( 'Minimum Length (characters)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'How many characters must be typed before suggestions are requested. Raising it cuts requests to your geocoder, which matters on the free plans. Google Maps and Mapbox ignore this for the built-in location field.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 10,
						'default'     => 3,
						'required'    => true,
						'_order'      => 20,
					],
				],
			],

			'hpgp_maps'        => [
				'title'       => esc_html__( 'Map Appearance', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'Applies to maps drawn by the map providers this plugin adds. Google Maps and Mapbox draw their own maps and are not affected.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 40,

				'fields'      => [
					'geolocation_plus_map_style'    => [
						'label'       => esc_html__( 'Map Style', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'The look of the map itself. The choices depend on which map provider is selected, so save the provider first.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'statuses'    => [ 'optional' => null ],
						'_order'      => 10,
						'options'     => [],
					],

					'geolocation_plus_marker_color' => [
						'label'       => esc_html__( 'Marker Colour', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'The colour of the pins on the map, as a six digit hex code such as #ff5a5f. Leave it empty for the default blue (#3a77ff).', 'geolocation-plus-for-hivepress' ),
						'type'        => class_exists( '\HivePress\Fields\Color' ) ? 'color' : 'text',
						'max_length'  => 7,
						'_order'      => 20,
					],
				],
			],

			'hpgp_data'        => [
				'title'       => esc_html__( 'Removing the Plugin', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'What happens to these settings if you ever delete Geolocation Plus. They are kept by default, so reinstalling restores everything. Ignore the generic warning WordPress shows when deleting a plugin; the setting below is what counts.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 50,

				'fields'      => [
					'geolocation_plus_delete_data' => [
						'label'       => esc_html__( 'Deleting the Plugin', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Tick this only if you want everything removed for good, which cannot be undone. It removes every setting on this page and the API keys in the Integrations section.', 'geolocation-plus-for-hivepress' ),
						'caption'     => esc_html__( 'Delete all data when this plugin is deleted', 'geolocation-plus-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],

		],
	],

	'integrations' => [
		'sections' => [
			'hpgp_maptiler'   => [
				'title'       => 'MapTiler',
				'description' => esc_html__( 'Only used when MapTiler is selected as the map provider. Create a free account at maptiler.com and copy the key from the Keys page. Restrict the key to your own domain first: it is readable by anyone who views the page. Widest choice of map styles. In the United Kingdom a listing in a large city can be filed under a named district of it, such as Old Town in Edinburgh.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 50,

				'fields'      => [
					'geolocation_plus_maptiler_key' => [
						'label'      => hivepress()->translator->get_string( 'api_key' ),
						'type'       => 'text',
						'max_length' => 256,
						'_order'     => 10,
					],
				],
			],

			'hpgp_geoapify'   => [
				'title'       => 'Geoapify',
				'description' => esc_html__( 'Only used when Geoapify is selected as the map provider. Create a free account at geoapify.com, add a project and copy the API key. Restrict the key to your own domain first: it is readable by anyone who views the page. Names places consistently, though it abbreviates some labels, such as England to "ENG".', 'geolocation-plus-for-hivepress' ),
				'_order'      => 60,

				'fields'      => [
					'geolocation_plus_geoapify_key' => [
						'label'      => hivepress()->translator->get_string( 'api_key' ),
						'type'       => 'text',
						'max_length' => 256,
						'_order'     => 10,
					],
				],
			],

			'hpgp_locationiq' => [
				'title'       => 'LocationIQ',
				'description' => esc_html__( 'Only used when LocationIQ is selected as the map provider. Create a free account at locationiq.com and copy the access token from the Dashboard. Restrict the key to your own domain first: it is readable by anyone who views the page. The most detailed addresses of the three; the free plan allows about one search a second. It sometimes names a city by its council area, such as "Aberdeen City".', 'geolocation-plus-for-hivepress' ),
				'_order'      => 70,

				'fields'      => [
					'geolocation_plus_locationiq_key' => [
						'label'      => hivepress()->translator->get_string( 'api_key' ),
						'type'       => 'text',
						'max_length' => 256,
						'_order'     => 10,
					],
				],
			],
		],
	],
];
