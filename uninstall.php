<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching
 * the plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's settings by default.** Someone who deletes a plugin
 * by accident, or removes it to install a clean copy, gets their configuration back when they
 * reinstall. Destruction is opt-in, through the "Delete all data when this plugin is deleted"
 * checkbox in the "Removing the Plugin" section of the HivePress Geolocation settings tab, and
 * is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php:398-410
 * is hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to
 * that screen; the setting has to live on our own page. Worse, WordPress prints "(will also
 * delete its data)" on that screen whenever an uninstall.php exists at all, whatever the file
 * actually does, so the setting's own description tells the owner that the core warning does
 * not apply unless they ticked the box.
 *
 * Nothing here ever touches the region taxonomy terms. They belong to the HivePress Geolocation
 * extension, they are indexed by listings, and they carry an owner's own place names - this
 * plugin only ever added rows to a taxonomy somebody else registered.
 *
 * @package GeolocationPlus
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans one site.
 *
 * Wrapped in a function because a network install has to run it per site: options live in each
 * site's own table, so a single pass would leave every other site holding this plugin's settings
 * and, worse, three live third-party API keys in a database the owner believes they have cleaned.
 */
function hpgp_uninstall_site() {
	global $wpdb;

	// Read the owner's choice first, before anything is touched.
	$delete_all = (bool) get_option( 'hp_geolocation_plus_delete_data' );

	/*
	 * -----------------------------------------------------------------------------------------
	 * Always cleaned, on both paths.
	 * -----------------------------------------------------------------------------------------
	 */

	// One of this plugin's providers left in the extension's own setting stops meaning anything
	// the moment the plugin is deleted: the Geolocation extension falls through to Google Maps
	// with an empty key and every map on the site turns into a grey error box, while the Map
	// Provider drop-down - which no longer has the option - displays "Google Maps" and reports
	// nothing wrong. Putting the extension's own default back is not destroying an owner's
	// setting, it is removing a value only this plugin could ever honour. Done here and NOT on
	// deactivation, which is usually temporary and must not rewrite another plugin's settings.
	if ( in_array( get_option( 'hp_geolocation_provider' ), [ 'osm', 'maptiler', 'geoapify', 'locationiq' ], true ) ) {
		update_option( 'hp_geolocation_provider', '' );
	}

	// Any transient the plugin has ever set. A transient is stored as "_transient_{name}" plus a
	// separate "_transient_timeout_{name}" row, so a prefix sweep anchored on the option name
	// cannot match them, and a timeout row left behind with no value row is the classic orphan.
	// esc_like() covers the WHOLE prefix, underscores included - an unescaped "_" is a LIKE
	// wildcard matching any character, which could reach another plugin's row.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_hpgp' ) . '%',
			$wpdb->esc_like( '_transient_timeout_hpgp' ) . '%'
		)
	);

	foreach ( (array) $transients as $transient_name ) {
		delete_option( $transient_name );
	}

	// Left over from a 1.0.0 pre-release build that parked the provider on deactivation. Harmless
	// if absent, and worth clearing so no site carries a dead row.
	delete_option( 'hp_geolocation_plus_saved_provider' );

	if ( ! $delete_all ) {
		return;
	}

	/*
	 * -----------------------------------------------------------------------------------------
	 * Only when the owner asked for it.
	 * -----------------------------------------------------------------------------------------
	 */

	// Every setting this plugin adds. HivePress stores a settings field as "hp_" plus the field
	// name, and every one of ours is named "geolocation_plus_*", so one prefix covers the lot -
	// including the three API keys on the Integrations tab. The delete-data flag itself matches
	// that prefix too, so it is excluded here and removed last: if anything fails part way
	// through, the flag is still set and a second delete finishes the job, rather than the site
	// silently reverting to "retain" with half the data already gone.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'hp_geolocation_plus_' ) . '%',
			'hp_geolocation_plus_delete_data'
		)
	);

	foreach ( (array) $options as $option_name ) {
		delete_option( $option_name );
	}

	delete_option( 'hp_geolocation_plus_delete_data' );
}

// The updater's cached release lookup is network-wide, so it is removed once rather than per
// site: a site transient lives under its own prefix and neither sweep above would reach it.
delete_site_transient( 'hpgp_github_release' );

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $hpgp_site_id ) {
		switch_to_blog( $hpgp_site_id );

		hpgp_uninstall_site();

		restore_current_blog();
	}
} else {
	hpgp_uninstall_site();
}
