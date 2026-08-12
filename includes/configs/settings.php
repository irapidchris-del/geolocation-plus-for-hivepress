<?php
/**
 * Settings configuration.
 *
 * Everything here is merged into the Geolocation extension's own settings tab rather than
 * given a tab of its own, so an owner configuring locations finds all of it in one place.
 * The dynamic parts - the extra map providers, and the map styles that depend on which
 * provider is chosen - are added by the component instead, because they are not static.
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

return [
	'geolocation'  => [
		'sections' => [
			'hpgp_display'     => [
				'title'       => esc_html__( 'Address Display', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'Locations are usually saved in full, such as "Edinburgh, Scotland, United Kingdom". These settings shorten what visitors see. What is already stored is left alone, so you can switch back at any time and nothing has to be re-entered, unless you tick the last setting in this section, which shortens new entries as they are saved.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [
					'geolocation_plus_address_format' => [
						'label'       => esc_html__( 'Address Format', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Choose how much of each saved address to show. Addresses are split on commas, so "Edinburgh, Scotland, United Kingdom" has three parts, counted from the left. A theme that supplies its own address markup keeps it, so this has no effect on ExpertHive, JobHive or MeetingHive.', 'geolocation-plus-for-hivepress' ),
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

					'geolocation_plus_address_parts'  => [
						'label'       => esc_html__( 'Number of Parts (used with "A set number of parts")', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'How many comma separated parts to show, counted from the left. Only used when the format above is set to a set number of parts.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 10,
						'default'     => 1,
						'_order'      => 20,
					],

					'geolocation_plus_format_input'   => [
						'label'       => esc_html__( 'Saved Value', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'By default the full address is saved and only shortened when it is displayed. Tick this to shorten it as people pick a suggestion, so the short version is what gets saved. Existing entries are not changed. This applies to every location attribute you create, on any map provider, and to the built-in location field when one of the providers this plugin adds is selected.', 'geolocation-plus-for-hivepress' ),
						'caption'     => esc_html__( 'Save the shortened address instead', 'geolocation-plus-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 30,
					],
				],
			],

			'hpgp_suggestions' => [
				'title'       => esc_html__( 'Location Suggestions', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'Controls the list of places that drops down while somebody types in a location field. Restricting it is the tidiest way to keep every saved location consistent, because people can no longer pick a street address when you wanted a city.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'geolocation_plus_suggestion_types' => [
						'label'       => esc_html__( 'Suggestion Types', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Select the kinds of place people are allowed to choose. Leave this empty to allow everything, which is the standard behaviour. Geocoders vary in how strictly they honour this, so a few unexpected results can still appear.', 'geolocation-plus-for-hivepress' ),
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

					'geolocation_plus_min_length'       => [
						'label'       => esc_html__( 'Minimum Length (characters)', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'How many characters somebody must type before suggestions are requested. Raising this cuts the number of requests your geocoder receives, which matters on the free plans. Google Maps and Mapbox run their own suggestion box and ignore this, so it applies to the location attributes you create and, when one of the providers this plugin adds is selected, to the built-in location field as well.', 'geolocation-plus-for-hivepress' ),
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
				'description' => esc_html__( 'Applies to maps drawn by the map providers this plugin adds. Google Maps and Mapbox draw their own maps and are not affected by these settings.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 40,

				'fields'      => [
					'geolocation_plus_map_style'    => [
						'label'       => esc_html__( 'Map Style', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'The look of the map itself. The choices change depending on which map provider is selected above, so save the provider first.', 'geolocation-plus-for-hivepress' ),
						'type'        => 'select',
						'statuses'    => [ 'optional' => null ],
						'_order'      => 10,
						'options'     => [],
					],

					'geolocation_plus_marker_color' => [
						'label'       => esc_html__( 'Marker Colour', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'The colour of the pins on the map, as a six digit hex code such as #ff5a5f. Leave it empty for the default blue (#3a77ff), which is the marker colour the HivePress Geolocation extension uses on Google Maps.', 'geolocation-plus-for-hivepress' ),
						'type'        => class_exists( '\HivePress\Fields\Color' ) ? 'color' : 'text',
						'max_length'  => 7,
						'_order'      => 20,
					],
				],
			],

			'hpgp_data'        => [
				'title'       => esc_html__( 'Removing the Plugin', 'geolocation-plus-for-hivepress' ),
				'description' => esc_html__( 'What happens to these settings if you ever delete Geolocation Plus. They are kept by default, so reinstalling restores everything. WordPress will warn you that deleting a plugin also deletes its data whichever way the setting below is left, so ignore that wording and trust this setting.', 'geolocation-plus-for-hivepress' ),
				'_order'      => 50,

				'fields'      => [
					'geolocation_plus_delete_data' => [
						'label'       => esc_html__( 'Deleting the Plugin', 'geolocation-plus-for-hivepress' ),
						'description' => esc_html__( 'Tick this only if you want the settings removed for good, which cannot be undone. It removes the address, suggestion and map settings on this page, and the MapTiler, Geoapify and LocationIQ API keys in the Integrations section.', 'geolocation-plus-for-hivepress' ),
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
				'description' => esc_html__( 'Only used when MapTiler is selected as the map provider. Create a free account at maptiler.com, then copy the key from the Keys page of your account. Good coverage and seven map styles, the widest choice here. Worth knowing before you choose it: MapTiler has no single "city" level in the United Kingdom, so a listing in the middle of a large city can be filed under a named district of it, such as Old Town in Edinburgh. Smaller towns and cities are named as you would expect, and this plugin checks with MapTiler whether a county is also a city so that places like Cardiff and Nottingham come out right.', 'geolocation-plus-for-hivepress' ),
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
				'description' => esc_html__( 'Only used when Geoapify is selected as the map provider. Create a free account at geoapify.com, add a project, then copy the API key it gives you. Names places consistently and handles cities well. It writes some labels its own way, abbreviating England to "ENG" and occasionally beginning a building result with "yes", which comes from the underlying map data rather than from this plugin.', 'geolocation-plus-for-hivepress' ),
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
				'description' => esc_html__( 'Only used when LocationIQ is selected as the map provider. Create a free account at locationiq.com, then copy the access token from the Dashboard. The most detailed addresses of the three, often naming the neighbourhood as well as the town. Its free plan allows about one search a second, which is ample for typing but can be reached by automated tools. It sometimes names a city by its council area, such as "Aberdeen City" rather than "Aberdeen", so a site that has already built region pages under another provider may end up with both.', 'geolocation-plus-for-hivepress' ),
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
