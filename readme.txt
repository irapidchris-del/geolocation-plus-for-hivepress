=== Geolocation Plus for HivePress ===
Contributors: ChrisB
Tags: hivepress, geolocation, map, openstreetmap, leaflet
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds free map providers, custom location attributes, tidier addresses, restricted suggestions and a map block to the HivePress Geolocation extension.

== Description ==

The HivePress Geolocation extension gives you Google Maps or Mapbox, one location field per listing, and a map in the sidebar. This plugin adds the things people keep asking for on top of it, without changing a single file of the extension itself.

**Free map providers.** Four more choices in the Map Provider drop-down, all drawn with Leaflet, which is bundled with the plugin rather than loaded from anybody else's server:

* **OpenStreetMap** needs no account at all. Maps come from OpenStreetMap and address suggestions from Photon, which indexes the same data for type-ahead search.
* **MapTiler**, **Geoapify** and **LocationIQ** each have a generous free tier and need a free API key, entered in the Integrations section.

Pick a provider and everything else follows: the map, the suggestion list, the "locate me" button, the region pages, and the link on a listing's address, which opens OpenStreetMap rather than Google Maps. Google Maps and Mapbox carry on working exactly as before if you would rather keep them.

**Location attributes you create yourself.** A new "Location" field type appears in the Field Type drop-down when you add an attribute under Listings, Vendors, Bookings, Requests or Users. It behaves like any other HivePress attribute, and it suggests real places as the visitor types, exactly like the built-in location field. Coordinates are saved alongside it automatically, so the value carries a real position rather than just text.

**Tidier addresses.** Choose what visitors see: the full address, the city only, the city and the country, everything except the country, and so on. Nothing is re-saved, so you can change your mind at any time and every existing listing follows immediately. There is also an option to shorten what gets saved as people pick a suggestion, if you would rather store the short version.

**Restricted suggestions.** Decide which kinds of place people are allowed to choose: countries, regions, cities, districts, postcodes, street addresses, or any combination. This is the tidiest way to keep every saved location consistent, and it works on Google Maps and Mapbox too. It governs the location a listing is filed and searched by; the Location attributes you create keep offering everything, so an attribute named for an address can still accept one.

**A map block.** A "Location Map" block for the WordPress editor, and a matching `[hivepress_hpgp_map]` shortcode. Show your listings, your vendors or a single place; set the height, the zoom level and the map style; filter to one category or to featured listings only. Markers are clustered automatically.

The shortcode takes the same options as the block:

* `source` - `listings`, `vendors` or `point`. Defaults to listings.
* `category` - a listing category ID. Listings only.
* `number` - how many markers at most. Defaults to 50.
* `featured` - `1` to show featured listings only.
* `latitude`, `longitude`, `label` - the position and popup wording for `source="point"`.
* `height` - height in pixels. Leave it out for a square map.
* `zoom` - 1 to 20. Leave it out to fit the map around the markers.
* `style` - a map style name. Leave it out to use the style set on the settings page.

For example:

`[hivepress_hpgp_map source="listings" category="12" number="100" height="420"]`

`[hivepress_hpgp_map source="point" latitude="55.953251" longitude="-3.188267" label="Our office"]`

= Requirements =

HivePress and the HivePress Geolocation extension, both active. The plugin says so on the Plugins screen if either is missing.

= Privacy =

Nothing is sent anywhere until you choose a map provider that needs it, and then only the address being searched or the coordinates being looked up. No site or visitor data is collected by this plugin, and no analytics of any kind are included. The API keys for MapTiler, Geoapify and LocationIQ are used in the visitor's browser, as those services intend, so restrict them to your domain in the provider's own dashboard.

= Known limits =

* Maps are drawn with Leaflet, which uses ordinary picture tiles. Vector maps and the deeper colour control that comes with them would need MapLibre, which is not included yet; see the FAQ.
* Map styles, marker colour and a fixed zoom level apply to the providers this plugin adds. Google Maps and Mapbox draw their own maps and keep their own appearance.
* Region pages are built by looking up the coordinates, and the search box matches them by name. Where a geocoder names a place differently in a search result and in a coordinate lookup ("Glasgow" against "Glasgow City", for example), the search falls back to the ordinary radius search, which is what HivePress does anyway.
* The "Address Format" setting splits addresses on commas. That is exactly right for a location chosen from the suggestion list, and it can look odd on a hand-typed address with unusual punctuation. It also has no effect on themes that supply their own address markup, which the official ExpertHive, JobHive and MeetingHive themes do.
* A custom location attribute is for recording and showing a second place, such as a meeting point. It stores real coordinates, but it is not plotted on the map block and cannot be searched by distance yet - the built-in Location field is the one radius search uses. Neither "Suggestion Types" nor "Hide the exact address" applies to it: you created the field and chose to show it, so what gets picked is what gets stored.
* OpenStreetMap suggestions come from Photon, which serves English, German and French. On a site in any other language, place names come back in the local language of each place instead.
* Region pages are filed a few moments after a listing is saved rather than during the save, so a slow geocoder never delays anybody. If a lookup fails, the settings page tells you and quotes the reason, and re-saving the listing tries again. OpenStreetMap is allowed twice as long as the other providers to answer, because it is a free community service and can take several seconds.
* Suggestion types are matched to whatever each provider offers, and the providers do not offer the same set. MapTiler reports neighbourhoods inside its city results rather than as a type of their own, so restricting to Neighbourhood returns little there.
* MapTiler has no reliable city level in the United Kingdom, so a listing in the middle of a large city whose districts are named can be filed under the district instead: a central Edinburgh address becomes "Old Town" under "City of Edinburgh". The tree is still correct, and smaller towns and cities are named as you would expect. The other providers do not have this problem. Each provider's section on the Integrations settings page carries a short note on how it names places, so you can read it while choosing rather than having to find it here.
* Providers can name the same city differently, so switching provider on a site that already has region pages can create a second page for one city, such as "Edinburgh" alongside "City of Edinburgh". Nothing is lost, but you may want to merge them under Listings > Regions.
* Google Maps and Mapbox identify a region by their own internal id rather than by its name. Region pages built under those two keep working exactly as before, and this plugin adds its own name-based identifier alongside rather than replacing it, so nothing has to be rebuilt when you switch.
* Region pages are matched by place name, so two places that share a name and a type would share a page. On a single-country site that does not arise.

== Installation ==

1. Install and activate HivePress and the HivePress Geolocation extension.
2. Upload the plugin zip through Plugins > Add New > Upload Plugin, then activate it.
3. Go to HivePress > Settings > Geolocation and choose a Map Provider.
4. If the provider needs an API key, add it under HivePress > Settings > Integrations.

== Frequently Asked Questions ==

= Do I have to stop using Google Maps? =

No. Leave the Map Provider set to Google Maps and the extension carries on exactly as before. You still get the custom location attributes, the address formatting, the restricted suggestions and the map block.

= Will changing the address format lose my full addresses? =

No. The full address stays in the database and is still shown when you hover the address on a listing. Only what is printed on the page changes, so switching back to "Full address" restores everything at once.

= Is OpenStreetMap suitable for a busy site? =

Its maps and its search are free community services that ask people not to lean on them. They are perfect for getting started and for smaller sites. Once you have real traffic, move to MapTiler, Geoapify or LocationIQ, which are free at the volumes most sites need and are built for it.

= Can I use MapLibre, or vector maps? =

Not in this version. MapLibre is worth explaining, because it is asked for often and it is not quite what it sounds like: it is a map *rendering* engine, the open fork of Mapbox GL JS, rather than a source of maps. Adding it would mean shipping a second engine alongside Leaflet, which this plugin uses today.

It would be a real improvement where it counts. Vector maps stay sharp at any zoom, and because the map style is a file you can edit rather than a picture, colours become genuinely customisable. MapTiler, Geoapify and LocationIQ all publish vector styles already, so their existing API keys would work unchanged, and it would open the door to OpenFreeMap, which serves vector maps with no account at all.

The reason it is not here yet is weight. MapLibre is around six times the size of Leaflet, which every visitor to a page with a map would download, and it needs WebGL, so it fails outright on hardware that does not support it. That is a lot to spend before this plugin has real users telling us it is worth it. It is the first thing on the list for a future version, most likely as a choice rather than a replacement, so nobody pays for it unless they want it.

= What happens to my settings if I delete the plugin? =

They are kept, so reinstalling restores everything. If you want them removed for good, tick "Delete all data when this plugin is deleted" in the Removing the Plugin section of HivePress > Settings > Geolocation first. WordPress will warn you that deleting a plugin also deletes its data whichever way that box is set; ignore that wording and trust the setting.

== Changelog ==

= 1.1.3 =
* Changed - the settings screen now keeps its quick links in view as you scroll, and adds a
  Save button and a back-to-top button that follow you down the page. The quick links, the
  Save button and the back-to-top button now look and sit exactly the same in every one of
  these extensions, so moving between two of their settings tabs no longer means hunting for
  the same control in a different place.
* Changed - the settings screen's styles and script, and the colour picker used by the Marker
  Colour box, are no longer loaded on other plugins' settings tabs, so those tabs carry a little
  less to download.

= 1.1.2 =
* Changed - a code comment that described a different function had been left stranded above
  the one explaining how MapTiler responses are read, so a developer saw the wrong
  description. Comments only. Nothing about how the plugin works has changed.

= 1.1.1 =
* Fixed - the quick links added to the settings screen in 1.1.0 appeared on every HivePress
  settings tab, including tabs belonging to other plugins. They now appear only on tabs that
  carry this plugin's own settings, stand down when another plugin has already drawn a
  navigation there, and never rewrite a heading anchor another plugin created.

= 1.1.0 =
* Added - separate address formats for listings and for vendors, each with an optional character
  limit that trims the displayed address with an ellipsis. The main Address Format still applies
  wherever no override is set, so nothing changes until you choose one.
* Added - an option to remove repeated address parts, so a provider answering "Edinburgh,
  Edinburgh, Scotland" displays as "Edinburgh, Scotland" everywhere addresses appear.
* Added - a "Hide the location field" option on the HivePress search blocks in the WordPress
  editor, for a search form placed somewhere the location is already decided.
* Added - an option to hide places of interest, such as "Edinburgh Castle", from location
  suggestions, on every provider including Google Maps and Mapbox.
* Added - an option to show suggestions already shortened by the Address Format, so the list
  matches what the page will display.
* Added - quick links and dividers on the settings screen, and shorter setting descriptions.
* Fixed - the "locate me" button now works on Mapbox. Its reverse lookup borrowed the forward
  search request, whose parameters Mapbox refuses when reverse geocoding, so the button silently
  did nothing. It also now reports plainly when a location cannot be found.
* Fixed - the button no longer sits there apparently dead when the browser cannot supply a
  position. It gives up after ten seconds and asks the visitor to type the address instead, and
  says nothing extra when they simply declined the browser's own permission prompt, because the
  browser has already told them.
* Fixed - Suggestion Types now takes effect in full on Mapbox. Only the first five kinds of place
  were sent, so a site allowing more than five had the rest quietly ignored.

= 1.0.10 =
* Fixed - region searching now works for a model switched on after the plugin was installed. The
  one-off pass that gives existing region terms their search codes skipped any model whose regions
  did not exist yet, but marked itself finished anyway, so those terms never got codes and
  searching one of them quietly fell back to a distance search. The pass now records each model
  separately and picks up the rest later.
* Fixed - deleting the plugin now also clears the update check's own leftovers and cancels its
  background update check.

= 1.0.9 =
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.

= 1.0.8 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.0.7 =
* Region pages are now worked out in the background just after a listing is saved, rather than during the save. On a busy site, a slow answer from a free geocoding service could previously hold the server long enough to cause timeout errors for other visitors; nothing waits on the geocoder any more.
* Restores the three display template files that were missing from the 1.0.6 download, which made the address line disappear from listing, vendor and request pages on most themes.

= 1.0.6 =
* Fixed: the author shown on the Plugins screen now reads "ChrisB @ HivePress Community", matching every other extension in the range.
* Added: a "Donate" link on the Plugins screen and in the plugin details popup, for anyone who would like to support the work. It appears nowhere else and gates nothing.

= 1.0.5 =
* "Hide the exact address" no longer shortens the Location attributes you create, matching the way Suggestion Types behaves. It still shortens the listing's own location, which is what the setting is for. This also settles a difference nobody chose: the shortening only ever happened on OpenStreetMap, MapTiler, Geoapify and LocationIQ, so the same site behaved differently depending on which provider was selected.

= 1.0.4 =
* The Suggestion Types setting no longer restricts the Location attributes you create. It governs the location a listing is filed and searched by, which is what its description promises; applied to an attribute as well it made a field named "Studio Address" unable to accept an address on a site restricted to cities. The setting now says where it applies.

= 1.0.3 =
* The Integrations settings page now says to restrict each API key to your own domain before pasting it in. The keys are used in the visitor browser, as these services intend, so they are readable by anyone who views a page with a map on it, and an unrestricted key can be used up by somebody else. This was only in the readme before, which is not open when you are pasting a key into a box.

= 1.0.2 =
* "Locate Me" works again when Suggestion Types is restricted. It was doing nothing at all, silently, because the visitor's own position did not match the kinds of place the suggestion list was limited to. Where somebody is standing is not a choice from a list, so the restriction no longer applies to it. "Hide the exact address" still does, and remains the setting to use if you want the result made less precise.
* Searching on OpenStreetMap with a country restriction no longer reports "No matching places found" when matches exist. It asks for a wider set of results before filtering, so a place with many foreign namesakes, such as Richmond, still finds the British ones.

= 1.0.1 =
* The Countries setting now restricts OpenStreetMap suggestions. Photon has no country setting to send, so a United Kingdom site was offered Ludlow in Illinois, Maine, Kentucky and Vermont alongside the one in Shropshire; results are now matched against the countries you allow.
* Region pages are no longer lost when OpenStreetMap is slow to answer. It is a free community service and can take longer than the other providers, so it is now allowed twice as long before the lookup is abandoned.

= 1.0.0 =
* First release.
* Adds OpenStreetMap, MapTiler, Geoapify and LocationIQ as map providers, drawn with bundled Leaflet and clustered markers.
* Adds a Location field type for admin-defined attributes on listings, vendors, bookings, requests and users, with coordinates saved alongside.
* Adds an address display format, applied when a location is shown rather than when it is saved.
* Adds a suggestion type restriction that works on every provider, including Google Maps and Mapbox.
* Adds a Location Map block and a matching shortcode, with height, zoom, style, category and featured options.
* Keeps region generation working on the new providers, which the extension only supports for Google Maps and Mapbox.
* Points a listing's address link at OpenStreetMap instead of Google Maps whenever one of the map providers this plugin adds is selected.
