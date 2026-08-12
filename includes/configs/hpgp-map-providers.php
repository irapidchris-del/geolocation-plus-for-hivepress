<?php
/**
 * Map providers configuration.
 *
 * Every provider this plugin adds to the HivePress "Map Provider" setting is described here
 * once, and both PHP and JavaScript read the same description. Read it as: how do we draw
 * tiles, how do we ask for suggestions, and how do we recognise what kind of place came back.
 *
 * The three suggestion keys need care because no two geocoders agree on their vocabulary:
 *
 * - "types" maps our type keys onto the value the provider's own filter parameter wants.
 *   Some providers accept a list, some only accept one value, which is what "multiple" says.
 * - "kinds" maps our type keys onto the classifications that appear in the RESPONSE. The
 *   browser filters on these, which is the only restriction that works everywhere. It is why
 *   we over-fetch (see "limit") and trim afterwards.
 *
 * Filterable as `hivepress/v1/hpgp_map_providers`.
 *
 * @package GeolocationPlus\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'osm'        => [
		'label'       => 'OpenStreetMap',
		'key_option'  => '',

		// Tiles come from OpenStreetMap; the address search comes from Photon, which is the
		// same OpenStreetMap data indexed for type-ahead. OpenStreetMap's own Nominatim search
		// was tried first and is the wrong tool: it matches whole words, so "Edinbur" returns
		// nothing at all and a suggestion list stays empty until the visitor happens to finish
		// spelling the place (measured against the live service, 2026-08-06). Photon returns
		// Edinburgh on the fourth character.
		'geocoder'    => 'photon',
		'search_url'  => 'https://photon.komoot.io/api/',
		'reverse_url' => 'https://photon.komoot.io/reverse',
		'limit'       => 5,
		'max_limit'   => 20,
		'max_zoom'    => 19,

		// Twice the default. Photon is a free community service and its latency is measured in
		// seconds, not milliseconds - fifteen on a live site, answering 200 rather than rate
		// limiting - so a ten second ceiling turned region generation into a silent no-op there.
		// See the note in request_reverse() for why this is not simply raised everywhere.
		'reverse_timeout' => 20,

		// Photon has no country parameter, so the Countries setting is applied in the browser
		// instead - which means the request has to be wide enough to contain the countries the
		// site allows. See the over-fetch note in get_script_data().
		'country_param'   => false,

		// Photon accepts only these language codes, and an unsupported one is a hard 400 rather
		// than an ignored parameter: `?q=edinburgh&lang=es` answers
		// {"lang":[{"message":"Language is not supported. Supported are: default, de, en, fr"}]}
		// (measured 2026-08-11). WordPress hands us the raw two-letter locale prefix, so without
		// this list every request from a Spanish, Italian, Polish or Dutch site would fail and
		// the provider would be completely unusable there. Anything not listed falls back to
		// "default", which returns each name in its own local language.
		'languages'   => [ 'de', 'en', 'fr' ],

		'styles'      => [
			'standard'     => [
				'label'       => esc_html__( 'Standard', 'geolocation-plus-for-hivepress' ),
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			],

			'humanitarian' => [
				'label'       => esc_html__( 'Humanitarian', 'geolocation-plus-for-hivepress' ),
				'url'         => 'https://tile-{s}.openstreetmap.fr/hot/{z}/{x}/{y}.png',
				'subdomains'  => 'ab',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, tiles by <a href="https://www.hotosm.org/">HOT</a>',
			],
		],

		// Photon takes a repeatable "layer" parameter. There is no postcode layer - asking for
		// one is a 400 - and a postcode comes back typed as "other", so a site restricted to
		// postcodes is filtered in the browser instead.
		'types'       => [
			'multiple' => true,
			'repeat'   => true,
			'param'    => 'layer',

			'values'   => [
				'country'  => 'country',
				'region'   => 'state',
				'district' => 'county',
				'place'    => 'city',
				'locality' => 'district',
				'address'  => 'street',
			],
		],

		// "other" is deliberately absent. It is Photon's catch-all for anything it cannot
		// classify - mountains, shops, tree rows - so listing it under postcode both offered
		// those as postcode suggestions and let the browser mint a "postcode:arthurs-seat"
		// region code that no term could ever match. A real postcode is recovered instead by
		// reading Photon's own osm_key/osm_value tags in common.js.
		'kinds'       => [
			'country'  => [ 'country' ],
			'region'   => [ 'state' ],
			'district' => [ 'county' ],
			'place'    => [ 'city', 'town', 'village', 'hamlet' ],
			'locality' => [ 'district', 'locality', 'suburb', 'neighbourhood' ],
			'postcode' => [ 'postcode' ],
			'address'  => [ 'street', 'house' ],
		],
	],

	'maptiler'   => [
		'label'       => 'MapTiler',
		'key_option'  => 'geolocation_plus_maptiler_key',
		'geocoder'    => 'maptiler',
		'search_url'  => 'https://api.maptiler.com/geocoding/',
		'reverse_url' => 'https://api.maptiler.com/geocoding/',
		'limit'       => 5,

		// MapTiler documents 10 as the ceiling for its geocoding limit. The browser over-fetches
		// when it has to filter suggestions itself, so the multiplier has to be capped or the
		// request either 400s or is silently clamped, which quietly breaks that filtering.
		'max_limit'   => 10,
		'max_zoom'    => 20,

		'styles'      => [
			'streets-v2'   => [
				'label' => esc_html__( 'Streets', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key={key}',
			],

			'basic-v2'     => [
				'label' => esc_html__( 'Basic', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key={key}',
			],

			'bright-v2'    => [
				'label' => esc_html__( 'Bright', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://api.maptiler.com/maps/bright-v2/{z}/{x}/{y}.png?key={key}',
			],

			'dataviz'      => [
				'label' => esc_html__( 'Light', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://api.maptiler.com/maps/dataviz/{z}/{x}/{y}.png?key={key}',
			],

			'dataviz-dark' => [
				'label' => esc_html__( 'Dark', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://api.maptiler.com/maps/dataviz-dark/{z}/{x}/{y}.png?key={key}',
			],

			'outdoor-v2'   => [
				'label' => esc_html__( 'Outdoor', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://api.maptiler.com/maps/outdoor-v2/{z}/{x}/{y}.png?key={key}',
			],

			'satellite'    => [
				'label'    => esc_html__( 'Satellite', 'geolocation-plus-for-hivepress' ),
				'url'      => 'https://api.maptiler.com/tiles/satellite-v2/{z}/{x}/{y}.jpg?key={key}',
				'max_zoom' => 20,
			],
		],

		'attribution' => '<a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',

		// A value may be a list, and several of these are, because MapTiler's vocabulary splits
		// one of our types across more than one of its own and which one a place lands in varies
		// by country. "City" was mapped to `municipality` alone, and measured against the live
		// API on 2026-08-11 that returns ZERO features for Edinburgh, Manchester or any other UK
		// city - they are `place` there, and "City of Edinburgh" is additionally a `county`. So
		// restricting suggestions to City made every major UK city impossible to choose. Sending
		// the alternatives together is always at least as permissive as sending one.
		'types'       => [
			'multiple' => true,
			'param'    => 'types',

			'values'   => [
				'country'  => 'country',
				'region'   => 'region',
				'district' => [ 'subregion', 'county' ],
				'place'    => [ 'place', 'municipality', 'municipal_district' ],
				'locality' => [ 'locality', 'neighbourhood' ],
				'postcode' => 'postal_code',
				'address'  => 'address',
			],
		],

		// ORDER MATTERS in this table, unlike the others: parse_maptiler_reverse() walks each list
		// in order, so the most-wanted value comes first. Read the note on that method before
		// changing any of this - the order is measured against live reverse lookups at seven
		// places, and both of the obvious orderings are wrong somewhere.
		//
		// Settlement first. MapTiler's `place` is its LOCALITY level and holds whatever is mapped
		// nearby (measured: "Castle Road Allotments" in Newport, "St James Quarter" in Edinburgh,
		// "City Centre" in Manchester), while the name MapTiler itself puts in a formatted address
		// is the municipality or the joint_submunicipality.
		//
		// `joint_municipality` sits under district because that is what it holds: Greater London.
		'kinds'       => [
			'country'  => [ 'country' ],
			'region'   => [ 'region' ],
			'district' => [ 'county', 'subregion', 'joint_municipality' ],
			'place'    => [ 'municipality', 'joint_submunicipality', 'place', 'municipal_district' ],
			'locality' => [ 'locality', 'neighbourhood' ],
			'postcode' => [ 'postal_code' ],
			'address'  => [ 'address', 'poi', 'road' ],
		],
	],

	'geoapify'   => [
		'label'       => 'Geoapify',
		'key_option'  => 'geolocation_plus_geoapify_key',
		'geocoder'    => 'geoapify',
		'search_url'  => 'https://api.geoapify.com/v1/geocode/autocomplete',
		'reverse_url' => 'https://api.geoapify.com/v1/geocode/reverse',
		'limit'       => 5,
		'max_limit'   => 20,
		'max_zoom'    => 20,

		'styles'      => [
			'osm-bright'       => [
				'label' => esc_html__( 'Bright', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://maps.geoapify.com/v1/tile/osm-bright/{z}/{x}/{y}.png?apiKey={key}',
			],

			'osm-carto'        => [
				'label' => esc_html__( 'Standard', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://maps.geoapify.com/v1/tile/osm-carto/{z}/{x}/{y}.png?apiKey={key}',
			],

			'osm-bright-grey'  => [
				'label' => esc_html__( 'Grey', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://maps.geoapify.com/v1/tile/osm-bright-grey/{z}/{x}/{y}.png?apiKey={key}',
			],

			'positron'         => [
				'label' => esc_html__( 'Light', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://maps.geoapify.com/v1/tile/positron/{z}/{x}/{y}.png?apiKey={key}',
			],

			'dark-matter'      => [
				'label' => esc_html__( 'Dark', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://maps.geoapify.com/v1/tile/dark-matter/{z}/{x}/{y}.png?apiKey={key}',
			],

			'klokantech-basic' => [
				'label' => esc_html__( 'Basic', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://maps.geoapify.com/v1/tile/klokantech-basic/{z}/{x}/{y}.png?apiKey={key}',
			],
		],

		'attribution' => 'Powered by <a href="https://www.geoapify.com/">Geoapify</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',

		'types'       => [
			'multiple' => false,
			'param'    => 'type',

			'values'   => [
				'country'  => 'country',
				'region'   => 'state',
				'place'    => 'city',
				'locality' => 'locality',
				'postcode' => 'postcode',
				'address'  => 'street',
			],
		],

		'kinds'       => [
			'country'  => [ 'country' ],
			'region'   => [ 'state' ],
			'district' => [ 'county' ],
			'place'    => [ 'city' ],
			'locality' => [ 'district', 'suburb', 'locality' ],
			'postcode' => [ 'postcode' ],
			'address'  => [ 'street', 'building', 'amenity' ],
		],
	],

	'locationiq' => [
		'label'       => 'LocationIQ',
		'key_option'  => 'geolocation_plus_locationiq_key',
		'geocoder'    => 'locationiq',
		'search_url'  => 'https://api.locationiq.com/v1/autocomplete',
		'reverse_url' => 'https://us1.locationiq.com/v1/reverse',
		'limit'       => 5,
		'max_limit'   => 20,
		'max_zoom'    => 18,

		'styles'      => [
			'streets' => [
				'label' => esc_html__( 'Streets', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key={key}',
			],

			'light'   => [
				'label' => esc_html__( 'Light', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://tiles.locationiq.com/v3/light/r/{z}/{x}/{y}.png?key={key}',
			],

			'dark'    => [
				'label' => esc_html__( 'Dark', 'geolocation-plus-for-hivepress' ),
				'url'   => 'https://tiles.locationiq.com/v3/dark/r/{z}/{x}/{y}.png?key={key}',
			],
		],

		'attribution' => '&copy; <a href="https://locationiq.com/">LocationIQ</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',

		// Deliberately empty, so no server-side restriction is sent and the browser filters on
		// "kinds" instead (the same fallback Photon uses for postcodes). An earlier version sent
		// `layer=` with a vocabulary that could not be confirmed against a live key, and getting
		// a geocoder's filter parameter wrong fails in the worst possible way: either it is
		// ignored, so the setting silently does nothing, or the request 400s and the whole
		// suggestion box reports itself unavailable. Browser-side filtering costs one wider
		// request and is correct on every plan. Revisit with a real key.
		'types'       => [],

		// "highway" is a CLASS, not a type, and it is here on purpose. LocationIQ returns raw
		// OpenStreetMap tagging, so a street's `type` is its road classification (primary,
		// residential, service and a dozen more) rather than anything meaning "street".
		// locationiqKind() in common.js collapses that whole family to its class so one entry
		// covers all of it - see the note there for what the gap broke.
		'kinds'       => [
			'country'  => [ 'country' ],
			'region'   => [ 'state', 'region', 'province' ],
			'district' => [ 'county', 'state_district', 'district' ],
			'place'    => [ 'city', 'town', 'village', 'municipality', 'hamlet' ],
			'locality' => [ 'suburb', 'neighbourhood', 'quarter', 'city_district', 'borough' ],
			'postcode' => [ 'postcode' ],
			'address'  => [ 'highway', 'poi', 'road', 'house_number', 'house', 'building', 'residential', 'amenity' ],
		],
	],
];
