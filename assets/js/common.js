/**
 * Geolocation Plus for HivePress.
 *
 * Two jobs, and which one runs depends on the selected map provider.
 *
 * With Google Maps or Mapbox selected, the HivePress Geolocation extension draws everything as
 * it always has and this file only adds behaviour for the custom location attributes, which
 * the extension knows nothing about.
 *
 * With one of the providers this plugin adds, the extension's own script is never enqueued -
 * it would call into a `google` global that does not exist - and this file takes over the
 * location fields and the maps completely, using Leaflet.
 *
 * The hand-off works because the extension assigns hivepress.initGeolocation as a property and
 * its own listener looks that property up when the event fires (common.js:426), so replacing
 * the property here replaces the behaviour without touching the extension.
 */
(function ($) {
	'use strict';

	// Read from `config` rather than the root: WordPress turns every top-level value in a
	// localised object into a string, so booleans and numbers only survive one level down.
	var data = (window.hivepressGeolocationPlusData || {}).config || {},
		nativeInit = hivepress.initGeolocation;

	data.strings = data.strings || {};
	data.minLength = parseInt(data.minLength, 10) || 3;
	data.parts = parseInt(data.parts, 10) || 1;
	data.maxZoom = parseInt(data.maxZoom, 10) || 18;

	// Gives every suggestion list a unique id, which is what lets a screen reader be told which
	// option is highlighted.
	var menuCount = 0;

	// The OpenStreetMap top-level keys that mark a named venue - a thing AT an address rather
	// than a place or an address in its own right. Shared by Photon (osm_key) and LocationIQ
	// (class), which both hand back raw OSM tagging; each entry was measured carrying a full
	// street address in its label (see the note at locationiqKind). `place`, `boundary`,
	// `natural` and `landuse` are deliberately absent: those are places, not venues.
	var POI_KEYS = ['tourism', 'amenity', 'shop', 'leisure', 'building', 'man_made', 'railway', 'aeroway', 'historic', 'office', 'craft', 'healthcare', 'emergency', 'military'];

	/**
	 * Splits an address into trimmed, non-empty comma parts.
	 */
	function splitParts(address) {
		return String(address).split(',').map(function (part) {
			return part.trim();
		}).filter(function (part) {
			return part.length > 0;
		});
	}

	/**
	 * Removes consecutive repeated address parts.
	 *
	 * Mirrors Hpgp_Geolocation::dedupe_parts(): only back-to-back repeats go, so a suburb
	 * legitimately named after its city keeps both mentions.
	 */
	function dedupeParts(parts) {
		var deduped = [];

		$.each(parts, function (index, part) {
			if (!deduped.length || deduped[deduped.length - 1].toLowerCase() !== part.toLowerCase()) {
				deduped.push(part);
			}
		});

		return deduped;
	}

	/**
	 * Shortens an address the same way the PHP side does.
	 *
	 * Kept in step with Hpgp_Geolocation::format_address(); change one and change both. The
	 * per-model overrides and length limits are display-time PHP concerns and have no twin here.
	 */
	function formatAddress(address) {
		if (typeof address !== 'string' || !address.trim() || (!data.format && !data.dedupe)) {
			return address;
		}

		var parts = splitParts(address);

		if (!parts.length) {
			return address;
		}

		if (data.dedupe) {
			parts = dedupeParts(parts);
		}

		if (data.format && parts.length >= 2) {
			switch (data.format) {
				case 'first':
					parts = parts.slice(0, 1);
					break;
				case 'first_two':
					parts = parts.slice(0, 2);
					break;
				case 'first_last':
					parts = [parts[0], parts[parts.length - 1]];
					break;
				case 'no_last':
					parts = parts.slice(0, -1);
					break;
				case 'last':
					parts = [parts[parts.length - 1]];
					break;
				case 'custom':
					parts = parts.slice(0, Math.max(1, parseInt(data.parts, 10) || 1));
					break;
			}
		}

		return parts.join(', ');
	}

	/**
	 * What a suggestion row displays, as opposed to what picking it saves.
	 *
	 * The Address Format only reshapes the LIST when the owner asked for that ("Shorten
	 * Suggestions"), and only on the main location field - custom attributes are exempt from
	 * every suggestion setting, for the reasons noted at initLocationField(). The repeated-part
	 * cleanup applies everywhere, because it removes noise rather than information.
	 */
	function suggestionText(label, allowFormat) {
		if (allowFormat && data.formatSuggestions) {
			return formatAddress(label);
		}

		if (data.dedupe) {
			var parts = splitParts(label);

			return parts.length ? dedupeParts(parts).join(', ') : label;
		}

		return label;
	}

	/**
	 * True when a comma part of an address IS the street, with or without a house number in front.
	 *
	 * Deliberately anchored to the end and requiring a space, so "Waterloo Place" and
	 * "6 Waterloo Place" both count while "Waterloo Place Gardens" does not.
	 */
	function isStreetPart(part, street) {
		if (!part || !street) {
			return false;
		}

		return part === street || part.slice(-street.length - 1) === ' ' + street;
	}

	/**
	 * Drops the street part of a chosen place when exact addresses are meant to be hidden.
	 *
	 * "Hide the exact address" is not only about the map: the Geolocation extension also keeps only
	 * coarse components in the location box on Google Maps (`assets/js/common.js`), so the exact
	 * address is never stored. Without an equivalent here, our providers saved full street addresses
	 * while the map still drew a privacy circle.
	 *
	 * Only street-level results are coarsened; a visitor who picked a city gets the city. Two guards,
	 * because this throws information away:
	 *
	 * 1. An UNRECOGNISED kind is left alone. On a native provider kindMap is empty by design, and
	 *    coarsening unknown kinds reduced "Newcastle upon Tyne, United Kingdom" to "United Kingdom".
	 * 2. Never leave fewer than two parts. A country on its own is never a useful location.
	 *
	 * Where a provider names the street outright it is used instead of counting parts, because
	 * counting assumes the street comes first and on some results it does not.
	 */
	function privacyLabel(result) {
		if (!data.scatter || !result || !result.label) {
			return result ? result.label : '';
		}

		if ('address' !== kindType(result.kind)) {
			return result.label;
		}

		var parts = String(result.label).split(',').map(function (part) {
			return part.trim();
		}).filter(function (part) {
			return part.length > 0;
		});

		// How many leading parts are the exact address. Usually one - "10 Waterloo Place" - but
		// the Nominatim-shaped providers put a bare house number in its own part, so "12, London
		// Road, Meadowbank, …" needs two dropped or the street name survives, which is most of
		// what "hide the exact address" is trying to remove.
		var numbered = /^\d+[a-z]?$/i.test(parts[0]),
			drop = numbered ? 2 : 1;

		// A point of interest is a NAME, and the leading part is that name rather than a street.
		// Coarsen one only when the provider says it carries a street: "The Balmoral, 1 Princes Street,
		// Edinburgh" does, "Hyde Park, London, ENG, United Kingdom" does not, and stripping "Hyde Park"
		// would remove the only part identifying the place. Street-classified results are exempt: for
		// those the leading part IS the street.
		if (!result.street && !numbered && $.inArray(result.kind, ['poi', 'amenity', 'building']) !== -1) {
			return result.label;
		}

		// Better than counting when the provider names the street outright, because the street is often
		// not the first part: LocationIQ can return "East End, Waterloo Place, Waterloo Place, ...",
		// where dropping one part leaves the street twice. Only a run STARTING at the street's first
		// appearance is dropped; the last appearance would over-reach when a suburb shares its name with
		// its road.
		if (result.street) {
			var first = -1;

			// Not an exact comparison, and the same test governs the RUN as well as its start: a POI result
			// can label its street part with the number attached (Geoapify: "6 Waterloo Place" while naming
			// the street "Waterloo Place"), and an exact test would leave the house number published.
			$.each(parts, function (index, part) {
				if (first === -1 && isStreetPart(part, result.street)) {
					first = index;
				}
			});

			if (first !== -1) {
				var run = first + 1;

				while (run < parts.length && isStreetPart(parts[run], result.street)) {
					run++;
				}

				drop = Math.max(drop, run);
			}
		}

		// Never coarsen down to a country on its own.
		if (parts.length < drop + 2) {
			return result.label;
		}

		return parts.slice(drop).join(', ');
	}

	/**
	 * Builds the code stored against a region term.
	 *
	 * Must produce exactly what Hpgp_Geolocation::get_region_code() produces, or the search never
	 * matches the region page it belongs to and silently falls back to a radius search.
	 *
	 * normalize('NFD') alone is NOT equivalent to remove_accents(). It splits a letter from its
	 * combining mark, so it handles \u00e9 and \u00fc, but it cannot touch a letter that has no
	 * decomposition at all - \u00f8, \u00e6, \u00df, \u0142, \u0111, \u00fe pass straight through and are then stripped as
	 * non-ASCII. And remove_accents() is locale-aware: on a German site it maps \u00fc to "ue", not
	 * "u". So the table WordPress itself would use is sent from PHP in `translit` and applied
	 * first; NFD stays as a fallback for anything the table does not name.
	 */
	function regionCode(type, name) {
		var slug = String(name),
			map = data.translit || {};

		slug = slug.replace(/[^\u0000-\u007f]/g, function (ch) {
			return Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ch;
		});

		if (String.prototype.normalize) {
			slug = slug.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}

		slug = slug.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

		// Mirrors the PHP fallback: a name with no Latin characters at all reduces to nothing,
		// and an empty slug would make every region of that type share one code.
		if (!slug) {
			slug = String(name).trim().toLowerCase();
		}

		return type + ':' + slug;
	}

	/**
	 * Works out which of our region types a provider's own classification belongs to.
	 */
	function kindType(kind) {
		var map = data.kindMap || {},
			match = '';

		if (!kind) {
			return '';
		}

		$.each(map, function (type, kinds) {
			if (!match && $.inArray(kind, kinds) !== -1) {
				match = type;
			}
		});

		return match;
	}

	/**
	 * True when a result is one of the kinds the site allows.
	 */
	function isAllowedKind(kind) {
		if (!data.kinds || !data.kinds.length) {
			return true;
		}

		return $.inArray(kind, data.kinds) !== -1;
	}

	/**
	 * True when a result is in one of the countries the site allows.
	 *
	 * Only ever applied to a result that states its country. Every provider but Photon is told the
	 * restriction in the request itself, so their results carry nothing here and pass straight
	 * through - and a result of unknown country is kept rather than dropped, because hiding a place
	 * the visitor can see on the map is worse than showing one extra.
	 */
	function isAllowedCountry(country) {
		if (!country || !data.countries || !data.countries.length) {
			return true;
		}

		return $.inArray(String(country).toUpperCase(), $.map(data.countries, function (code) {
			return String(code).toUpperCase();
		})) !== -1;
	}

	/**
	 * Reads a coordinate that may be a method or a plain property.
	 */
	function coordinate(point, name) {
		if (!point) {
			return null;
		}

		return typeof point[name] === 'function' ? point[name]() : point[name];
	}

	function buildQuery(params) {
		var pairs = [];

		$.each(params, function (name, value) {
			if (value === null || value === '' || typeof value === 'undefined') {
				return;
			}

			// An array value becomes a repeated parameter, which is how Photon takes its
			// layers. Providers wanting a comma separated list join it before it gets here.
			if ($.isArray(value)) {
				$.each(value, function (index, item) {
					pairs.push(encodeURIComponent(name) + '=' + encodeURIComponent(item));
				});

				return;
			}

			pairs.push(encodeURIComponent(name) + '=' + encodeURIComponent(value));
		});

		return pairs.join('&');
	}

	/**
	 * Adds the configured suggestion-type restriction to a request.
	 */
	function addTypeParam(params, options) {
		if ((options && options.anyKind) || !data.typeParam || !data.typeValue || !data.typeValue.length) {
			return params;
		}

		params[data.typeParam] = (!data.typeRepeat && $.isArray(data.typeValue)) ? data.typeValue.join(',') : data.typeValue;

		return params;
	}

	/**
	 * Builds a readable address out of Photon's structured properties.
	 *
	 * Photon returns no formatted address of its own, only the pieces. They are assembled here
	 * from most specific to least, which is the order every other geocoder uses and the order
	 * the "Address Format" setting counts from.
	 */
	function photonLabel(props) {
		var parts = [],
			head = props.name;

		if (!head) {
			head = $.grep([props.housenumber, props.street], Boolean).join(' ');
		}

		if (head) {
			parts.push(head);
		}

		$.each(['district', 'city', 'county', 'state', 'country'], function (index, key) {
			if (props[key] && $.inArray(props[key], parts) === -1) {
				parts.push(props[key]);
			}
		});

		return parts.join(', ');
	}

	/**
	 * Reads what kind of place a LocationIQ REVERSE result is, from its address object.
	 *
	 * Its reverse payload has no `class` and no `type` to read - see the note at the call site.
	 * The address object is all there is, and for a reverse lookup that is enough: a road or a
	 * house number means street level, otherwise the finest administrative key present says what
	 * we are looking at.
	 */
	function locationiqReverseKind(result) {
		var address = (result && result.address) || {};

		if (address.road || address.house_number) {
			return 'highway';
		}

		var keys = ['suburb', 'neighbourhood', 'quarter', 'city_district', 'village', 'town', 'city', 'county', 'state', 'country'],
			match = '';

		$.each(keys, function (index, key) {
			if (!match && address[key]) {
				match = key;
			}
		});

		return match;
	}

	/**
	 * Reads what kind of place a Photon result is, preferring its OpenStreetMap tag where we
	 * understand it and falling back to Photon's own coarse type where we do not.
	 */
	function photonKind(props) {
		if ('place' === props.osm_key && props.osm_value && kindType(props.osm_value)) {
			return props.osm_value;
		}

		return props.type || '';
	}

	function photonResult(feature) {
		var props = feature.properties || {},
			point = (feature.geometry || {}).coordinates || [],

			// Prefer Photon's own OpenStreetMap tag over its coarse `type`. `type` collapses a
			// lot into "other" - postcodes, mountains, shops, tree rows - so reading it alone
			// both offered nonsense as postcode suggestions and let a region code be minted for
			// a place that is not a region at all. osm_key/osm_value carry the real tag.
			//
			// But only when the tag is one we can place. `osm_value` reaches well past our
			// vocabulary - "square", "islet", "farm", "isolated_dwelling" - and swapping a `type`
			// the table DOES match for an `osm_value` it does not turned a perfectly good result
			// into an unrecognised one: a site restricted to Neighbourhood stopped offering
			// Photon's squares, whose `type` was "locality" all along. So the override has to earn
			// its place, and falls back rather than losing information.
			kind = photonKind(props);

		return {
			label: photonLabel(props),
			latitude: point.length > 1 ? point[1] : null,
			longitude: point.length > 1 ? point[0] : null,
			kind: kind,
			street: props.street || '',

			// A named venue, for the "hide places of interest" filter. The name test matters:
			// an OSM building with no name is a plain address, not a place of interest.
			poi: !!props.name && $.inArray(props.osm_key, POI_KEYS) !== -1,

			// Photon is the one provider with no country parameter to send, so the Countries
			// setting had no effect on it at all: a UK-only directory offered Ludlow in Illinois,
			// Maine, Kentucky and Vermont above the Shropshire one. It does report the country per
			// result, so the restriction is applied
			// here instead - the same browser-side fallback the suggestion types already use where
			// a provider cannot be told.
			country: props.countrycode || ''
		};
	}

	/**
	 * Builds a suggestion out of one Mapbox feature.
	 *
	 * Shared by the two lookups, in the same shape as photonResult, so the forward and reverse
	 * paths cannot drift apart in what they hand back.
	 */
	function mapboxResult(feature) {
		return {
			label: feature.place_name || feature.text,
			latitude: feature.center ? feature.center[1] : null,
			longitude: feature.center ? feature.center[0] : null,
			kind: feature.place_type ? feature.place_type[0] : '',
			poi: !!feature.place_type && $.inArray('poi', feature.place_type) !== -1
		};
	}

	/**
	 * Reads what kind of place a LocationIQ result is.
	 *
	 * LocationIQ hands back raw OpenStreetMap tagging (`class` and `type`). Reading `type` alone was
	 * wrong for every street: a road is class "highway" with the type carrying the road
	 * classification (primary, residential, footway ...), which matches nothing in any table. So
	 * "Hide the exact address" left streets in, and a site restricting suggestions to Address got
	 * nothing. Collapsing the whole highway family to its class covers every road type at once,
	 * including future ones.
	 */
	function locationiqKind(result) {
		if (!result) {
			return '';
		}

		if ('highway' === result.class) {
			return 'highway';
		}

		// Everything in this list is a thing AT an address rather than a place in its own right,
		// and each arrives with a full street address in its label - measured live: a hotel is
		// class "tourism", a station "railway", a pub "amenity", a shopping centre "shop", a
		// stadium "leisure", a bridge "man_made", a museum "building". Left unrecognised, every one
		// of them walked straight past "Hide the exact address" carrying its house number and
		// postcode, and was dropped from a list restricted to Street address. For a HivePress
		// directory these are the most likely picks of all, which is what makes it worth listing
		// them rather than waiting to be surprised.
		//
		// `place`, `boundary`, `natural` and `landuse` are deliberately absent: those are places,
		// not addresses, and coarsening them would throw away the name that was the whole point.
		if ($.inArray(result.class, POI_KEYS) !== -1) {
			return 'poi';
		}

		return result.type || result.class || '';
	}

	/**
	 * Reads what kind of place a HERE result is.
	 *
	 * HERE gives a broad resultType and, for areas, a subtype that says which level. The subtype
	 * is the useful half: "locality" alone covers cities, districts and postcodes. A named business
	 * (`place`, from reverse lookups only) becomes "poi" so it cannot collide with our own "place".
	 */
	function hereKind(item) {
		if (!item) {
			return '';
		}

		if ('administrativeArea' === item.resultType && item.administrativeAreaType) {
			return item.administrativeAreaType;
		}

		if ('locality' === item.resultType && item.localityType) {
			return item.localityType;
		}

		if ('place' === item.resultType) {
			return 'poi';
		}

		return item.resultType || '';
	}

	/**
	 * The name a HERE result goes by at its own level, for its region code.
	 *
	 * Read from the same address fields Hpgp_Geolocation::parse_here_reverse() files regions
	 * under, so a place picked here and a place filed there produce the same code. The label is
	 * no use for this: a UK postcode's label starts with the town, not the postcode.
	 */
	function hereRegionName(item, kind) {
		var address = (item && item.address) || {},
			fields = {
				country: 'countryName',
				state: 'state',
				county: 'county',
				city: 'city',
				district: 'district',
				subdistrict: 'subdistrict',
				postalCode: 'postalCode',
				postalCodePoint: 'postalCode'
			};

		return fields[kind] ? (address[fields[kind]] || '') : '';
	}

	function hereResult(item) {
		var address = item.address || {},
			position = item.position || {},
			kind = hereKind(item);

		return {
			// The address label reads most specific first, which is what the Address Format
			// setting counts from; the title of an autocomplete item can run the other way.
			label: address.label || item.title || '',
			latitude: typeof position.lat === 'number' ? position.lat : null,
			longitude: typeof position.lng === 'number' ? position.lng : null,
			kind: kind,
			id: item.id || '',
			poi: 'poi' === kind,
			street: address.street || '',
			regionName: hereRegionName(item, kind)
		};
	}

	/**
	 * A promise that fails at once, for a request that cannot succeed.
	 */
	function rejected() {
		return $.Deferred().reject().promise();
	}

	/**
	 * Suggestion sources, one per geocoder.
	 *
	 * Each returns a jQuery promise resolving to a list of
	 * {label, latitude, longitude, kind} objects. Filtering by kind happens afterwards, in one
	 * place, because only some providers can be told about it in the request itself.
	 */
	var geocoders = {
		photon: {
			search: function (term, options) {
				var params = addTypeParam({
					q: term,
					limit: data.limit || 5,
					lang: data.language
				}, options);

				return $.getJSON(data.searchUrl + '?' + buildQuery(params)).then(function (response) {
					return $.map((response && response.features) || [], photonResult);
				});
			},

			reverse: function (latitude, longitude) {
				return $.getJSON(data.reverseUrl + '?' + buildQuery({
					lat: latitude,
					lon: longitude,
					lang: data.language
				})).then(function (response) {
					var feature = response && response.features && response.features[0];

					return feature ? photonResult(feature) : null;
				});
			}
		},

		locationiq: {
			search: function (term, options) {
				var params = addTypeParam({
					key: data.key,
					q: term,
					limit: data.limit || 5,
					dedupe: 1,
					'accept-language': data.language
				}, options);

				if (data.countries && data.countries.length) {
					params.countrycodes = data.countries.join(',');
				}

				return $.getJSON(data.searchUrl + '?' + buildQuery(params)).then(function (results) {
					return $.map(results || [], function (result) {
						var kind = locationiqKind(result);

						return {
							label: result.display_name,
							latitude: parseFloat(result.lat),
							longitude: parseFloat(result.lon),
							kind: kind,
							poi: 'poi' === kind,
							street: (result.address && result.address.road) || ''
						};
					});
				});
			},

			reverse: function (latitude, longitude) {
				return $.getJSON(data.reverseUrl + '?' + buildQuery({
					key: data.key,
					lat: latitude,
					lon: longitude,
					format: 'json',
					addressdetails: 1,
					'accept-language': data.language
				})).then(function (result) {
					if (!result || !result.display_name) {
						return null;
					}

					return {
						label: result.display_name,

						// Reverse is a different response shape from search, and assuming otherwise
						// left "Locate Me" publishing a full street address on a site with "Hide the
						// exact address" ticked. LocationIQ's reverse payload carries NO `class` and
						// NO `type` at all - only place_id, osm ids, lat, lon, display_name,
						// boundingbox and an `address` object (measured live, 2026-08-12). So the
						// kind was always empty, nothing recognised it as street-level, and
						// coarsening never ran.
						//
						// Reverse geocoding always answers with the most specific thing at that
						// point, so the address object is the classification: a `road` or a
						// `house_number` means we are standing in a street, whatever else is there.
						kind: locationiqReverseKind(result),

						latitude: parseFloat(result.lat),
						longitude: parseFloat(result.lon),
						street: (result.address && result.address.road) || ''
					};
				});
			}
		},

		geoapify: {
			search: function (term, options) {
				var params = addTypeParam({
					text: term,
					apiKey: data.key,
					limit: data.limit || 5,
					lang: data.language,
					format: 'json'
				}, options);

				if (data.countries && data.countries.length) {
					params.filter = 'countrycode:' + data.countries.join(',').toLowerCase();
				}

				return $.getJSON(data.searchUrl + '?' + buildQuery(params)).then(function (response) {
					return $.map((response && response.results) || [], function (result) {
						return {
							label: result.formatted,
							latitude: parseFloat(result.lat),
							longitude: parseFloat(result.lon),
							kind: result.result_type,

							// Geoapify's own vocabulary: "amenity" is its one venue type.
							poi: 'amenity' === result.result_type,
							street: result.street || ''
						};
					});
				});
			},

			reverse: function (latitude, longitude) {
				return $.getJSON(data.reverseUrl + '?' + buildQuery({
					lat: latitude,
					lon: longitude,
					apiKey: data.key,
					lang: data.language,
					format: 'json'
				})).then(function (response) {
					var result = response && response.results && response.results[0];

					if (!result) {
						return null;
					}

					return {
						label: result.formatted,
						latitude: parseFloat(result.lat),
						longitude: parseFloat(result.lon),
						kind: result.result_type,
						street: result.street || ''
					};
				});
			}
		},

		maptiler: {
			search: function (term, options) {
				var params = addTypeParam({
					key: data.key,
					language: data.language,
					limit: data.limit || 5,
					autocomplete: true
				}, options);

				if (data.countries && data.countries.length) {
					params.country = data.countries.join(',');
				}

				return $.getJSON(data.searchUrl + encodeURIComponent(term) + '.json?' + buildQuery(params)).then(function (response) {
					return $.map((response && response.features) || [], function (feature) {
						return {
							label: feature.place_name || feature.text,
							latitude: feature.center ? feature.center[1] : null,
							longitude: feature.center ? feature.center[0] : null,
							kind: feature.place_type ? feature.place_type[0] : '',
							poi: !!feature.place_type && $.inArray('poi', feature.place_type) !== -1
						};
					});
				});
			},

			// No `limit`, matching the server-side reverse lookup. MapTiler rejects it on reverse
			// unless it is paired with a single `types` value: "Parameter limit must be combined
			// with a single type parameter when reverse geocoding" (HTTP 400, measured 2026-08-12).
			// This call happened to be tolerated where the server's was not, which is a worse
			// position than either working or failing - the two paths hit the same endpoint and
			// must not disagree about what is legal. The first feature is the most specific one
			// either way, so nothing is lost by asking for the rest and ignoring them.
			reverse: function (latitude, longitude) {
				return $.getJSON(data.reverseUrl + encodeURIComponent(longitude + ',' + latitude) + '.json?' + buildQuery({
					key: data.key,
					language: data.language
				})).then(function (response) {
					var feature = response && response.features && response.features[0];

					if (!feature) {
						return null;
					}

					return {
						label: feature.place_name || feature.text,
						latitude: latitude,
						longitude: longitude,
						kind: feature.place_type ? feature.place_type[0] : ''
					};
				});
			}
		},

		/**
		 * HERE Autocomplete, then Lookup for the coordinates of the one picked.
		 *
		 * Without a key every request is a certain 401, so none is sent and the field says
		 * search is unavailable straight away.
		 */
		here: {
			search: function (term, options) {
				if (!data.key) {
					return rejected();
				}

				var params = addTypeParam({
					q: term,
					limit: data.limit || 5,
					lang: data.language,
					apiKey: data.key
				}, options);

				// Three-letter codes, converted on the server.
				if (data.countries && data.countries.length) {
					params['in'] = 'countryCode:' + data.countries.join(',');
				}

				return $.getJSON(data.searchUrl + '?' + buildQuery(params)).then(function (response) {
					return $.map((response && response.items) || [], hereResult);
				});
			},

			resolve: function (result) {
				if (!data.key || !data.lookupUrl || !result.id) {
					return $.Deferred().resolve(null).promise();
				}

				return $.getJSON(data.lookupUrl + '?' + buildQuery({
					id: result.id,
					lang: data.language,
					apiKey: data.key
				})).then(function (item) {
					var found = item && item.position ? hereResult(item) : null;

					if (!found) {
						return null;
					}

					// Keep what the visitor saw in the list; Lookup may word it differently.
					return $.extend({}, result, {
						latitude: found.latitude,
						longitude: found.longitude
					});
				}, function () {
					return null;
				});
			},

			reverse: function (latitude, longitude) {
				if (!data.key) {
					return rejected();
				}

				return $.getJSON(data.reverseUrl + '?' + buildQuery({
					at: latitude + ',' + longitude,
					lang: data.language,
					apiKey: data.key
				})).then(function (response) {
					var item = response && response.items && response.items[0];

					if (!item) {
						return null;
					}

					// The visitor's own position, as for mapbox and maptiler.
					return $.extend(hereResult(item), {
						latitude: latitude,
						longitude: longitude
					});
				});
			}
		},

		mapbox: {
			search: function (term, options) {
				var params = {
					access_token: data.key,
					language: data.language,

					// Was hardcoded to 5 while every other provider read the setting.
					limit: data.limit || 5,
					autocomplete: true
				};

				if (!(options && options.anyKind) && data.types && data.types.length) {
					params.types = data.types.join(',');
				}

				if (data.countries && data.countries.length) {
					params.country = data.countries.join(',');
				}

				return $.getJSON('https://api.mapbox.com/geocoding/v5/mapbox.places/' + encodeURIComponent(term) + '.json?' + buildQuery(params)).then(function (response) {
					return $.map((response && response.features) || [], mapboxResult);
				});
			},

			// Its own request, like every other provider here. This used to be a forward search on
			// "longitude,latitude", which meant the forward parameters travelled with it and Mapbox
			// refused the lot: "limit must be combined with a single type parameter when reverse
			// geocoding" (HTTP 422, measured 2026-08-28). The same failure MapTiler answers 400 to,
			// noted on its reverse above - so no limit here either, for the same reason. The first
			// feature is the most specific one, so nothing is lost by taking the rest and ignoring
			// them.
			//
			// Three things the forward request carried that a reverse lookup must not:
			//
			// 1. `limit`, which is what returned the 422 and made "Locate Me" dead on arrival for
			//    every custom location attribute on a Mapbox site.
			// 2. `types`, because Suggestion Types is deliberately not applied to this button - see
			//    the note at the call site. Delegating to search applied it anyway on any site that
			//    had set it, which is the exact regression that note describes being reverted.
			// 3. `country`, which cannot improve a reverse answer and can only empty it. Mapbox
			//    answers 200 with zero features rather than an error when the visitor is outside the
			//    allowed countries - a traveller at the Eiffel Tower on a GB-restricted site got no
			//    features and therefore no message, indistinguishable from a dead button (measured
			//    2026-08-28). Where the visitor is standing is not a matter of opinion.
			reverse: function (latitude, longitude) {
				return $.getJSON('https://api.mapbox.com/geocoding/v5/mapbox.places/' + encodeURIComponent(longitude + ',' + latitude) + '.json?' + buildQuery({
					access_token: data.key,
					language: data.language
				})).then(function (response) {
					var feature = response && response.features && response.features[0];

					if (!feature) {
						return null;
					}

					// The visitor's own position, not the centre of whatever matched it, matching
					// maptiler and google. A coarse match would otherwise move the pin off them.
					return $.extend(mapboxResult(feature), {
						latitude: latitude,
						longitude: longitude
					});
				});
			}
		},

		/**
		 * Google has to go through its own JavaScript SDK - its web services do not answer
		 * cross-origin browser requests. The modern Places classes are preferred, with the
		 * legacy autocomplete service as a fallback for keys that predate them.
		 */
		google: {
			search: function (term, options) {
				var deferred = $.Deferred();

				if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
					return deferred.resolve([]).promise();
				}

				var request = {
					input: term,
					language: data.language
				};

				if (!(options && options.anyKind) && data.types && data.types.length) {
					request.includedPrimaryTypes = data.types.slice(0, 5);
				}

				if (data.countries && data.countries.length) {
					request.includedRegionCodes = data.countries;
				}

				if (google.maps.places.AutocompleteSuggestion) {
					google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(request).then(function (response) {
						deferred.resolve($.map(response.suggestions || [], function (suggestion) {
							return {
								label: suggestion.placePrediction.text.toString(),
								prediction: suggestion.placePrediction,
								kind: ''
							};
						}));
					}, function () {
						deferred.resolve([]);
					});

					return deferred.promise();
				}

				var service = new google.maps.places.AutocompleteService(),
					legacy = {
						input: term
					};

				if (data.types && data.types.length) {
					legacy.types = data.types.slice(0, 5);
				}

				if (data.countries && data.countries.length) {
					legacy.componentRestrictions = { country: data.countries };
				}

				service.getPlacePredictions(legacy, function (predictions) {
					deferred.resolve($.map(predictions || [], function (prediction) {
						return {
							label: prediction.description,
							placeId: prediction.place_id,
							kind: ''
						};
					}));
				});

				return deferred.promise();
			},

			/**
			 * Google predictions carry no coordinates, so the chosen one is resolved separately.
			 */
			resolve: function (result) {
				var deferred = $.Deferred();

				if (result.prediction) {
					var place = result.prediction.toPlace();

					place.fetchFields({ fields: ['location'] }).then(function () {
						deferred.resolve($.extend({}, result, {
							// google.maps.LatLng exposes lat()/lng() as methods, a plain
							// LatLngLiteral as properties, and which one Places hands back has
							// changed between versions of the API. Accept either.
							latitude: coordinate(place.location, 'lat'),
							longitude: coordinate(place.location, 'lng')
						}));
					}, function () {
						deferred.resolve(null);
					});

					return deferred.promise();
				}

				if (result.placeId && google.maps.Geocoder) {
					new google.maps.Geocoder().geocode({ placeId: result.placeId }, function (results, status) {
						if (status === 'OK' && results.length) {
							deferred.resolve($.extend({}, result, {
								latitude: coordinate(results[0].geometry.location, 'lat'),
								longitude: coordinate(results[0].geometry.location, 'lng')
							}));
						} else {
							deferred.resolve(null);
						}
					});

					return deferred.promise();
				}

				return deferred.resolve(null).promise();
			},

			reverse: function (latitude, longitude) {
				var deferred = $.Deferred();

				if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
					return deferred.resolve(null).promise();
				}

				new google.maps.Geocoder().geocode({ location: { lat: latitude, lng: longitude } }, function (results, status) {
					if (status === 'OK' && results.length) {
						deferred.resolve({
							label: results[0].formatted_address,
							latitude: latitude,
							longitude: longitude,
							kind: results[0].types ? results[0].types[0] : ''
						});
					} else {
						deferred.resolve(null);
					}
				});

				return deferred.promise();
			}
		}
	};

	function getGeocoder() {
		return geocoders[data.geocoder] || null;
	}

	/**
	 * Finds the inputs a location field writes its coordinates into.
	 *
	 * Custom location attributes name theirs explicitly, because a form can carry several
	 * location fields and the extension's form-wide `[data-coordinate]` selector cannot tell
	 * them apart. The listing's own location field has no such attributes, so it falls back to
	 * exactly the selector the extension uses.
	 */
	function getCoordinateFields(container, form) {
		var latName = container.data('lat-field'),
			lngName = container.data('lng-field');

		// The field said outright that it has nowhere to write. Falling through to the selector
		// below would target the listing's own coordinates, which is the corruption the PHP-side
		// guard exists to prevent.
		if (container.data('no-coordinates')) {
			return { latitude: $(), longitude: $() };
		}

		if (latName && lngName && /^[A-Za-z0-9_-]+$/.test(latName) && /^[A-Za-z0-9_-]+$/.test(lngName)) {
			return {
				latitude: form.find('input[name="' + latName + '"]'),
				longitude: form.find('input[name="' + lngName + '"]')
			};
		}

		return {
			latitude: form.find('input[data-coordinate=lat]'),
			longitude: form.find('input[data-coordinate=lng]')
		};
	}

	/**
	 * Binds the suggestion list to one location field.
	 */
	function initLocationField(container) {
		if (container.data('hpgp-bound')) {
			return;
		}

		container.data('hpgp-bound', true);

		var geocoder = getGeocoder(),
			form = container.closest('form'),
			field = container.find('input[type=text]').first(),
			button = container.children('a').first(),
			coordinates = getCoordinateFields(container, form),

			// Only the model's OWN location field speaks for the search form's region. A custom
			// location attribute has no region semantics: letting it write that hidden field made
			// a "pickup point" of Glasgow hijack a search scoped to the Edinburgh region page,
			// and on Google/Mapbox sites - where no kind table exists, so nothing ever looks like
			// a region - merely touching the field CLEARED the region the page was scoped to.
			// The field that names its own coordinate inputs is the custom one.
			ownsRegion = 'hpgp-location' !== container.data('component'),
			regionField = ownsRegion ? form.find('input[data-region]') : $(),

			// Suggestion Types governs the location a listing is FILED and SEARCHED by, and stops there.
			// Applied to a custom attribute it made a field such as "Meeting Address" unable to accept an
			// address (City asks Google for localities only, so the box said "No matching places found").
			// An attribute is a separate place with its own purpose, chosen by the owner when they named it.
			// It exempts the REQUEST as well as the filtering, because on several providers the restriction
			// travels in the query itself.
			anyKind = !ownsRegion,
			minLength = data.minLength,
			results = [],
			active = -1,
			requestId = 0,
			timer = null;

		if (!geocoder || !field.length) {
			return;
		}

		// The dropdown reuses the class names the Geolocation extension already styles
		// (assets/css/common.less), so it looks the same whichever provider is in use. The
		// Google "powered by" class is deliberately not among them.
		var menuId = 'hpgp-suggestions-' + (++menuCount),
			menu = $('<ul class="pac-container hpgp-suggestions" role="listbox"></ul>').attr('id', menuId).hide();

		container.append(menu);

		function closeMenu() {
			window.clearTimeout(timer);
			menu.hide().empty();
			active = -1;
			field.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
		}

		function renderMenu(items, message) {

			// Never open over a field the visitor has already left. A debounced request that
			// lands after a blur used to reopen the panel under an empty box, where it stayed
			// until the field was focused and blurred a second time.
			//
			// Compared against activeElement rather than jQuery's :focus, which also requires
			// document.hasFocus() - true of a normal tab, false whenever the browser window
			// itself is in the background or the page is inside an unfocused frame. Using :focus
			// meant a visitor who alt-tabbed away mid-search came back to a dead suggestion box.
			if (document.activeElement !== field.get(0)) {
				closeMenu();

				return;
			}

			menu.empty();

			if (message) {
				// textContent, never innerHTML - a geocoder's response is somebody else's data.
				menu.append($('<li class="pac-item hpgp-suggestions__message"></li>').text(message));
				menu.show();
				field.attr('aria-expanded', 'true').removeAttr('aria-activedescendant');

				return;
			}

			$.each(items, function (index, item) {
				var row = $('<li class="pac-item" role="option"></li>').attr({
						id: menuId + '-' + index,
						'aria-selected': 'false'
					}),
					text = $('<span class="pac-item-query"></span>');

				// The display text may be shortened; the full label still travels with the
				// result, so what a pick saves is unchanged.
				text.text(suggestionText(item.label, ownsRegion));
				row.append(text);
				row.data('index', index);

				row.on('mousedown', function (e) {
					e.preventDefault();
					choose(index);
				});

				menu.append(row);
			});

			menu.show();
			field.attr('aria-expanded', 'true');
		}

		function highlight(index) {
			var rows = menu.children('li');

			rows.removeClass('hpgp-suggestions__item--active').attr('aria-selected', 'false');

			if (index >= 0 && index < rows.length) {
				rows.eq(index).addClass('hpgp-suggestions__item--active').attr('aria-selected', 'true');

				// What a screen reader announces as the user arrows through the list. Without it
				// the highlight is a silent colour change.
				field.attr('aria-activedescendant', menuId + '-' + index);
			} else {
				field.removeAttr('aria-activedescendant');
			}

			active = index;
		}

		function apply(result) {
			if (!result || typeof result.latitude !== 'number' || typeof result.longitude !== 'number' || isNaN(result.latitude) || isNaN(result.longitude)) {
				return;
			}

			// Privacy first, then the owner's display format: a coarsened address still gets
			// shortened if they asked for that, but a shortened one is never re-expanded.
			//
			// "Hide the exact address" applies to the listing's own location, not to attributes an owner
			// creates, for the same reason Suggestion Types does not: coarsening a field the owner made for
			// an address undoes it. It also removes a provider inconsistency: on Google and Mapbox `kindMap`
			// is empty by design (class-hpgp-geolocation.php), so the rule never fired there. The listing's
			// own location field still coarsens, which is what the map's privacy circle is drawn around.
			var label = ownsRegion ? privacyLabel(result) : result.label;

			field.val(data.formatInput ? formatAddress(label) : label);

			// Remember exactly what a picked place put in the box, so the blur handler can tell
			// "this text belongs to those coordinates" from "somebody typed over it".
			appliedLabel = field.val();

			coordinates.latitude.val(result.latitude);
			coordinates.longitude.val(result.longitude);

			// Fill the hidden region field when the chosen place is itself a region, which is
			// what sends the search to a region page instead of a radius search. Anything else
			// clears it, exactly as the extension does.
			if (regionField.length) {
				var type = kindType(result.kind);

				if (type && $.inArray(type, data.regionTypes || []) !== -1) {
					// A geocoder that names the level outright (HERE) is used as is; the others lead
					// their label with the place's own name.
					regionField.val(regionCode(type, result.regionName || String(result.label).split(',')[0].trim()));
				} else {
					regionField.val('');
				}
			}

			closeMenu();
		}

		function choose(index) {
			var result = results[index];

			if (!result) {
				return;
			}

			if (typeof result.latitude === 'number' && !isNaN(result.latitude)) {
				apply(result);

				return;
			}

			// A pick whose position cannot be fetched says so. Doing nothing left the list open
			// and the visitor clicking a row that never responded.
			if (geocoder.resolve) {
				geocoder.resolve(result).then(function (resolved) {
					if (resolved) {
						apply(resolved);
					} else {
						renderMenu([], data.strings.failed);
					}
				});
			}
		}

		/**
		 * Runs one lookup, ignoring anything that is no longer the newest.
		 *
		 * Sequenced rather than aborted. Every adapter returns the promise from .then(), not the
		 * jqXHR, so there was never an abort() to call - the guard that used to sit here was dead
		 * code. Two requests in flight and a slow answer to the earlier one would repaint the list
		 * for a term the visitor had already moved on from, and clicking a row then wrote the
		 * wrong coordinates; a rate-limited 429 on the stale request replaced a perfectly good
		 * list with "unavailable".
		 */
		function search(term) {
			var id = ++requestId;

			renderMenu([], data.strings.searching);

			geocoder.search(term, { anyKind: anyKind }).then(function (items) {
				if (id !== requestId) {
					return;
				}

				// Places of interest are only hidden where the other suggestion restrictions
				// apply: the main location field. A custom attribute named for a venue must still
				// be able to accept one.
				results = $.grep(items || [], function (item) {
					return item.label && (anyKind || (isAllowedKind(item.kind) && (!data.hidePois || !item.poi))) && isAllowedCountry(item.country);
				}).slice(0, 5);

				if (!results.length) {
					renderMenu([], data.strings.noResults);

					return;
				}

				renderMenu(results);
				highlight(-1);
			}, function (xhr) {

				// A 404 is how LocationIQ says "nothing matched" (404 {"error":"Unable to geocode"}), not "I am
				// broken". Treating it as a failure told visitors location search was unavailable whenever they
				// mistyped a place name. Nominatim-shaped services all behave this way.
				if (id !== requestId) {
					return;
				}

				results = [];
				renderMenu([], (xhr && 404 === xhr.status) ? data.strings.noResults : data.strings.failed);
			});
		}

		field.attr({
			autocomplete: 'off',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': menuId
		});

		field.on('input', function () {
			var term = field.val();

			// Clearing the box has to clear everything behind it, or a stale pair of
			// coordinates keeps filtering a search nobody asked for.
			if (term.length <= 1) {
				coordinates.latitude.val('');
				coordinates.longitude.val('');

				if (regionField.length) {
					regionField.val('');
				}
			}

			window.clearTimeout(timer);

			if (term.length < minLength) {
				closeMenu();

				return;
			}

			// Debounced rather than fired per keystroke: the free geocoders all rate limit, and
			// the OpenStreetMap one asks for no more than a request a second.
			timer = window.setTimeout(function () {
				search(term);
			}, 400);
		});

		field.on('keydown', function (e) {
			if (!menu.is(':visible')) {
				return;
			}

			if (e.key === 'ArrowDown') {
				highlight(Math.min(active + 1, menu.children('li').length - 1));
				e.preventDefault();
			} else if (e.key === 'ArrowUp') {
				highlight(Math.max(active - 1, 0));
				e.preventDefault();
			} else if (e.key === 'Enter') {
				if (active >= 0) {
					choose(active);
					e.preventDefault();
				}
			} else if (e.key === 'Escape') {
				closeMenu();
			}
		});

		// What the box held when the visitor arrived in it, and what the last picked place put
		// there. Both are needed to decide whether text on the way out belongs to the coordinates
		// sitting behind it - see the focusout handler.
		var valueOnFocus = field.val(),
			appliedLabel = field.val();

		field.on('focusin', function () {
			valueOnFocus = field.val();
		});

		field.on('focusout', function () {

			// Cancel a debounce that has not fired yet, or it opens the panel again moments
			// after the field was left and emptied.
			window.clearTimeout(timer);

			// Deferred so a suggestion that is being clicked has already been applied - on
			// touch devices the mousedown handler does not always keep the field focused, and
			// clearing synchronously would wipe the value the visitor just chose.
			window.setTimeout(function () {
				closeMenu();

				// A typed address that was never matched to coordinates cannot be searched on,
				// so it is cleared rather than left looking as though it worked. Same as the
				// extension does (assets/js/common.js:226-230).
				//
				// Two conditions the extension does not need and we do, both of which were
				// deleting data:
				//
				// 1. There must BE somewhere to write coordinates. On the name-clash fail-safe
				//    the field is deliberately coordinate-less (`data-no-coordinates`), so
				//    getCoordinateFields returns empty sets and this test could never pass - the
				//    box emptied itself 200 ms after every blur, so the attribute could hold no
				//    value at all, and a Required one on the user model made registration
				//    impossible. The fail-safe was meant to make the field SAFER.
				// 2. The value must be one the visitor typed in this visit. An address that came
				//    from the database without coordinates - imported, or entered before the
				//    attribute had them - was being deleted the first time anybody tabbed past
				//    the field, without touching it. An empty string then normalises to null and
				//    the meta row goes.
				if (!coordinates.latitude.length || !coordinates.longitude.length) {
					return;
				}

				if (field.val() === valueOnFocus || field.val() === appliedLabel) {
					return;
				}

				// Text that belongs to no picked place, so the coordinates behind it belong to
				// somewhere else. Testing "are there coordinates?" was not enough: typing over an
				// address that already had them left the new text sitting on the OLD coordinates,
				// because a long string never trips the "box is empty" reset. The listing would
				// then read as one address and be mapped at another, which is worse than either
				// clearing or keeping it. Everything goes together.
				field.val('');
				coordinates.latitude.val('');
				coordinates.longitude.val('');

				if (regionField.length) {
					regionField.val('');
				}
			}, 200);
		});

		if (navigator.geolocation && button.length) {
			button.on('click', function (e) {
				e.preventDefault();

				// Nothing on this path used to report a failure. getCurrentPosition had no error
				// callback and the reverse promise had no rejection handler, so a refused permission,
				// a device that cannot get a fix, and a geocoder error all ended the way a successful
				// lookup with no result ends: in silence, with the only trace in the console.
				//
				// data.strings.failed already says the useful thing - type the address instead - and
				// it can only be shown through the suggestion panel, which refuses to open over a
				// field nobody is in. So the field is focused first, which is where the visitor has
				// to go next regardless.
				function fail() {
					field.trigger('focus');
					renderMenu([], data.strings.failed);
				}

				navigator.geolocation.getCurrentPosition(function (position) {
					geocoder.reverse(position.coords.latitude, position.coords.longitude).then(function (result) {

						// Deliberately NOT filtered by Suggestion Types. Reverse geocoding returns the building the
						// visitor is standing on, so filtering on a City-restricted site left "Locate Me" doing nothing
						// at all, with no fallback and no error. A dead control is worse than a precise answer: the
						// restriction keeps a LIST of choices consistent, and this is not a list. Privacy is handled by
						// "Hide the exact address", which still applies to this result.
						if (result) {
							apply(result);

							return;
						}

						// A lookup that worked and matched nothing is a different sentence from one
						// that could not run.
						field.trigger('focus');
						renderMenu([], data.strings.noResults);
					}, fail);
				}, function (error) {

					// A refused permission is the one failure the browser has already reported, in
					// its own words and its own UI. Saying "location search is unavailable" on top of
					// that would be both redundant and untrue - it is available, they declined it -
					// so the field is simply focused and left for them to type in.
					if (error && error.PERMISSION_DENIED === error.code) {
						field.trigger('focus');

						return;
					}

					fail();
				}, {

					// Left to itself the browser will accept a cached fix of any age and wait
					// indefinitely for a new one, so a device that cannot get a position never
					// reaches either callback and the button stays apparently dead for good.
					enableHighAccuracy: true,
					timeout: 10000,
					maximumAge: 60000
				});
			});
		} else {
			button.hide();
		}
	}

	// One copyright request per page, shared by every map on it.
	var copyrightRequests = {};

	/**
	 * Fetches a provider's copyright table, reusing a copy under a day old.
	 *
	 * HERE asks for the table to be refreshed every 24 hours, so a day is also the most a copy may
	 * be kept. Browser storage can be missing or blocked; the request then simply runs.
	 */
	function loadCopyright(url) {
		var storeKey = 'hpgpCopyright:' + url.split('?')[0];

		if (copyrightRequests[url]) {
			return copyrightRequests[url];
		}

		try {
			var stored = JSON.parse(window.localStorage.getItem(storeKey) || 'null');

			if (stored && stored.time && Date.now() - stored.time < 86400000 && stored.data) {
				copyrightRequests[url] = $.Deferred().resolve(stored.data).promise();

				return copyrightRequests[url];
			}
		} catch (e) {
			// Storage unavailable: fetch instead.
		}

		copyrightRequests[url] = $.getJSON(url).then(function (response) {
			try {
				window.localStorage.setItem(storeKey, JSON.stringify({ time: Date.now(), data: response }));
			} catch (e) {
				// Storage full or blocked: the copy is simply not kept.
			}

			return response;
		});

		return copyrightRequests[url];
	}

	/**
	 * True when any of a copyright block's boxes overlaps the map view.
	 *
	 * A block with no boxes applies everywhere, and so does a box spanning the whole globe, which
	 * HERE writes with west and east swapped (west 180, east -180). Any other box with west past
	 * east crosses the antimeridian and is tested as its two halves.
	 */
	function copyrightOverlaps(boxes, bounds) {
		var south = bounds.getSouth(),
			north = bounds.getNorth(),
			west = bounds.getWest(),
			east = bounds.getEast();

		if (!boxes || !boxes.length || east - west >= 360) {
			return true;
		}

		west = Math.max(-180, west);
		east = Math.min(180, east);

		function within(from, to) {
			return from <= east && to >= west;
		}

		return $.grep(boxes, function (box) {
			if (box.south > north || box.north < south) {
				return false;
			}

			if (box.west <= box.east) {
				return within(box.west, box.east);
			}

			if (box.west - box.east >= 359) {
				return true;
			}

			return within(box.west, 180) || within(-180, box.east);
		}).length > 0;
	}

	/**
	 * Adds the data suppliers a HERE map must credit, for the style, area and zoom in view.
	 *
	 * HERE's terms require "© 20XX HERE" followed by the supplier labels its copyright table lists
	 * for what is on screen. The fixed part is drawn from the start; the labels follow once the
	 * table arrives, and the fixed part stays if it never does.
	 */
	function hereCopyright(map, layer, tiles) {
		var base = tiles.attribution || '';

		loadCopyright(tiles.copyright.url).then(function (response) {
			var styles = (((response && response.resources) || {}).base || {}).styles || {},
				keys = styles[tiles.copyright.style] || [],
				blocks = (response && response.copyrights) || {};

			function update() {
				var zoom = map.getZoom(),
					bounds = map.getBounds(),
					labels = [];

				$.each(keys, function (index, key) {
					$.each(blocks[key] || [], function (position, block) {
						if (!block || !block.label || zoom < block.minLevel || zoom > block.maxLevel || !copyrightOverlaps(block.boundingBoxes, bounds)) {
							return;
						}

						if ($.inArray(block.label, labels) === -1) {
							labels.push(block.label);
						}
					});
				});

				// Escaped: the attribution control renders HTML, and the labels are another
				// service's data.
				var text = base + (labels.length ? ', ' + $('<span></span>').text(labels.join(', ')).html() : ''),
					current = layer.getAttribution();

				if (text === current) {
					return;
				}

				map.attributionControl.removeAttribution(current);
				layer.options.attribution = text;
				map.attributionControl.addAttribution(text);
			}

			map.on('moveend', update);
			update();
		});
	}

	/**
	 * Draws one map with Leaflet.
	 */
	function initMap(container) {
		if (container.data('hpgp-bound') || typeof L === 'undefined') {
			return;
		}

		container.data('hpgp-bound', true);

		var markers = container.data('markers') || [],
			tiles = container.data('tiles') || data.tiles || {},
			maxZoom = parseInt(container.data('max-zoom'), 10) || data.maxZoom || 18,
			fixedZoom = parseInt(container.data('zoom'), 10) || 0,
			scatter = !!container.data('scatter'),
			markerIcon = container.data('marker'),
			height = container.data('height') || container.width();

		// No tile URL means the provider has no key yet (see get_provider_style()). The empty
		// container would still hold the theme's map height as a blank box, so it goes.
		if (!tiles.url) {
			container.hide();

			return;
		}

		// Only set a height once there is a width to square off against. A container that is
		// hidden when the script runs - inside a tab, an accordion, a sticky sidebar that has
		// not settled, or simply switched off by a site's own CSS - reports width 0, and setting
		// height 0 from it produced a map that stayed invisible even after the container was
		// shown. Left alone, the theme's own min-height applies and the ResizeObserver below
		// squares it up the moment it gains a width.
		if (height) {
			container.height(height);
		}

		// The site's Zoom setting is the map's ceiling, and the provider's tile ceiling is a
		// separate thing. Math.max conflated them and could honour neither: an owner who set
		// Zoom to 12 to stop people reading addresses off the map still got 19, and an owner who
		// set 20 on a provider that stops at 18 got blank grey squares. maxNativeZoom is the
		// right tool for the second half - Leaflet upscales the deepest real tile instead of
		// requesting one that 404s.
		var map = L.map(container.get(0), {
			scrollWheelZoom: false,
			zoomControl: true,
			maxZoom: maxZoom
		}).setView([0, 0], 1);

		var tileLayer = L.tileLayer(tiles.url, {
			attribution: tiles.attribution || '',
			subdomains: tiles.subdomains || 'abc',
			maxZoom: maxZoom,
			maxNativeZoom: tiles.maxZoom || 19
		}).addTo(map);

		if (tiles.copyright && tiles.copyright.url) {
			hereCopyright(map, tileLayer, tiles);
		}

		// An exact address is hidden by drawing a circle instead of a pin, which is the same
		// choice the extension makes on Google Maps.
		function markerFor(item) {
			if (scatter) {
				return L.circleMarker([item.latitude, item.longitude], {
					color: data.markerColor,
					fillColor: data.markerColor,
					fillOpacity: 0.25,
					opacity: 0.75,
					weight: 1,
					radius: 14
				});
			}

			if (markerIcon) {
				return L.marker([item.latitude, item.longitude], {
					title: item.title,
					icon: L.icon({
						iconUrl: markerIcon,
						iconSize: [50, 50],
						iconAnchor: [25, 50],
						popupAnchor: [0, -50]
					})
				});
			}

			return L.marker([item.latitude, item.longitude], {
				title: item.title,
				icon: L.divIcon({
					className: 'hpgp-marker',
					iconSize: [28, 40],
					iconAnchor: [14, 40],
					popupAnchor: [0, -38],
					html: '<svg viewBox="0 0 28 40" width="28" height="40" focusable="false" aria-hidden="true"><path d="M14 0C6.3 0 0 6.3 0 14c0 10.5 14 26 14 26s14-15.5 14-26c0-7.7-6.3-14-14-14z" fill="' + data.markerColor + '"/><circle cx="14" cy="14" r="5" fill="#fff"/></svg>'
				})
			});
		}

		var layer = L.markerClusterGroup ? L.markerClusterGroup({
				maxClusterRadius: 45,
				disableClusteringAtZoom: maxZoom,
				showCoverageOnHover: false
			}) : L.layerGroup(),
			bounds = [];

		$.each(markers, function (index, item) {
			if (typeof item.latitude !== 'number' || typeof item.longitude !== 'number') {
				return;
			}

			var marker = markerFor(item);

			if (item.content) {
				marker.bindPopup(item.content, { minWidth: 220 });
			}

			layer.addLayer(marker);
			bounds.push([item.latitude, item.longitude]);
		});

		map.addLayer(layer);

		function fit() {
			if (!bounds.length) {
				return;
			}

			// A fixed zoom fixes the scale, not the subject. Centring on bounds[0] centred the map
			// on whichever listing was published most recently, so the block quietly re-aimed
			// itself every time anyone added a listing and pushed the rest off the edge. The
			// centre of what there is to show is the only stable answer, and it is what the
			// visitor means by "zoom 12" - Leaflet's own getCenter on the same bounds.
			if (fixedZoom) {
				map.setView(
					bounds.length > 1 ? L.latLngBounds(bounds).getCenter() : bounds[0],
					fixedZoom
				);

				return;
			}

			map.fitBounds(bounds, {
				maxZoom: maxZoom - 1,
				padding: [40, 40],
				animate: false
			});
		}

		// Scattered circles stand for a real distance on the ground, so they have to grow with
		// the zoom or they stop meaning anything. Sized once after the initial fit as well as on
		// every later zoom: binding zoomend alone left the first paint at the 14px starting
		// radius - a dot small enough to read a street off, on the very setting whose job is to
		// stop that - until the visitor happened to zoom.
		function resizeScatter() {
			layer.eachLayer(function (marker) {
				if (marker.setRadius) {
					marker.setRadius(Math.min(60, Math.pow(1.3125, map.getZoom())));
				}
			});
		}

		if (scatter) {
			map.on('zoomend', resizeScatter);
		}

		fit();

		if (scatter) {
			resizeScatter();
		}

		if (window.ResizeObserver) {
			new window.ResizeObserver(function () {

				// Square the map off the first time the container actually has a width, for the
				// hidden-at-load case above.
				if (!container.data('height') && container.width() && !container.height()) {
					container.height(container.width());
				}

				map.invalidateSize();
				fit();

				if (scatter) {
					resizeScatter();
				}
			}).observe(container.get(0));
		}
	}

	/**
	 * Replaces the extension's initialiser.
	 */
	hivepress.initGeolocation = function (container) {
		if (data.native) {
			if (typeof nativeInit === 'function') {
				nativeInit(container);
			}
		} else {
			container.find(hivepress.getSelector('location')).each(function () {
				initLocationField($(this));
			});

			container.find(hivepress.getSelector('map')).each(function () {
				initMap($(this));
			});
		}

		// Custom location attributes are ours on every provider, because the extension has no
		// idea they exist.
		container.find(hivepress.getSelector('hpgp-location')).each(function () {
			initLocationField($(this));
		});
	};

	// The extension binds this event itself and calls the property above. It is only bound here
	// when the extension's script is absent, which is exactly when one of our providers is in
	// use - binding it twice would initialise every field twice over.
	if (typeof nativeInit !== 'function') {
		$(document).on('hivepress:init', function (event, container) {
			hivepress.initGeolocation(container);
		});
	}
})(jQuery);
