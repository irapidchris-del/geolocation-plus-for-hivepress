<?php
/**
 * Plugin Name: Geolocation Plus for HivePress
 * Plugin URI: https://github.com/irapidchris-del/geolocation-plus-for-hivepress
 * Description: Extends the HivePress Geolocation extension with free map providers, custom location attributes, tidier address display, restricted location suggestions and a customisable map block.
 * Version: 1.1.4
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: geolocation-plus-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/geolocation-plus-for-hivepress
 *
 * @package GeolocationPlus
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HPGP_VERSION', '1.1.4' );
define( 'HPGP_FILE', __FILE__ );

// The author's support page. One place, so the Plugins row and the View details
// popup can never drift apart.
define( 'HPGP_SUPPORT_URL', 'https://ko-fi.com/chrisbathivepresscommunity' );

// Set up updates from GitHub releases. The file registers its own hooks and reads the two
// constants above, so it has to be required after them.
require_once __DIR__ . '/updater.php';

/**
 * Builds an asset version that changes when the file does.
 *
 * The plugin version alone is not enough: a hotfix edited in place, or anything tested before
 * the version is bumped, is served from browser and page caches as the old file - which reads as
 * "the fix does nothing" and sends the next session hunting through the PHP. HivePress passes a
 * config's version straight through (`components/class-asset.php:197`).
 *
 * @param string $path Asset path relative to the plugin directory.
 * @return string
 */
function hpgp_asset_version( $path ) {
	$file = plugin_dir_path( HPGP_FILE ) . $path;

	return HPGP_VERSION . ( file_exists( $file ) ? '.' . (int) filemtime( $file ) : '' );
}

/*
 * Deliberately NO deactivation hook.
 *
 * An earlier build parked the owner's Map Provider choice on deactivation and restored it on
 * reactivation, so that a site left without this plugin would not sit on a provider the
 * Geolocation extension cannot draw. It was the wrong trade: deactivating a plugin is usually
 * temporary - debugging, testing, switching something off for ten minutes - and silently
 * rewriting ANOTHER plugin's setting during it is exactly the kind of surprise that makes an
 * admin screen untrustworthy. The owner's choice is theirs; reactivating brings the maps back.
 *
 * The provider is still put back to the extension's default at UNINSTALL, in uninstall.php,
 * which is the point at which the value genuinely stops meaning anything.
 */

/**
 * Registers the extension.
 *
 * Two registration forms exist and both have a failure mode. HivePress resolves a bare
 * directory path to `{dirname}/{dirname}.php`, so the string form fails silently whenever
 * the installed folder name differs from the main file name (a GitHub source zip unpacks
 * to `geolocation-plus-for-hivepress-main`, for instance). The array form always registers,
 * but core's updater probe concatenates every entry as a string, so an array entry makes it
 * log an "Array to string conversion" warning on each request unless that probe has already
 * been satisfied. So: the string form whenever the folder name matches, and only for a
 * renamed folder the array form, with the probe run here first over the string entries so
 * core's own loop never reaches the array. The filter is registered late so extensions that
 * bundle the updates package are already listed by the time that probe runs, and at file
 * scope because core reads it before any plugins_loaded callback.
 *
 * @param array<string, mixed> $extensions Extension arguments.
 * @return array<string, mixed>
 */
function hpgp_register_extension( $extensions ) {
	if ( file_exists( __DIR__ . '/' . basename( __DIR__ ) . '.php' ) ) {
		$extensions[] = __DIR__;

		return $extensions;
	}

	if ( ! isset( $extensions['updates'] ) ) {
		$path = '/vendor/hivepress/hivepress-updates';

		foreach ( $extensions as $dir ) {
			if ( is_string( $dir ) && file_exists( $dir . $path . '/hivepress-updates.php' ) ) {
				$extensions['updates'] = $dir . $path;

				break;
			}
		}

		// Set it even when nothing was found. Core's own probe (class-core.php:245-256) only
		// runs while this key is unset, and it concatenates EVERY entry as a string - so on a
		// site with no premium extension the array entry below would make it warn "Array to
		// string conversion" on every single request. A path that does not exist is harmless:
		// core only file_exists() tests it, and later skips any entry with no main file.
		if ( ! isset( $extensions['updates'] ) ) {
			$extensions['updates'] = __DIR__ . $path;
		}
	}

	$extensions['geolocation_plus_for_hivepress'] = [
		'name'    => 'Geolocation Plus for HivePress',
		'version' => HPGP_VERSION,
		'path'    => __DIR__,
		'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
	];

	return $extensions;
}

add_filter( 'hivepress/v1/extensions', 'hpgp_register_extension', 100 );

// Add a settings link on the Plugins screen.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		if ( class_exists( '\HivePress\Core' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=geolocation' ) ) . '">' . esc_html__( 'Settings', 'geolocation-plus-for-hivepress' ) . '</a>' );
		}

		return $links;
	}
);

/**
 * Adds the house "Donate" link to this plugin's row on the Plugins screen.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen, so without the basename
 * test the link would appear on every row on the site. The markup is copied verbatim from
 * the house spec in `releasing.md` rather than composed here: every plugin's row has to look
 * identical and sessions have drifted before. The label is exactly "Donate", matching the
 * wording WordPress itself uses in the details popup, and the icon is a Dashicon rather than
 * Font Awesome because Dashicons is the admin's own font and is always loaded there.
 * WordPress joins row-meta items with " | " itself, so this returns a bare anchor.
 *
 * @param array<string> $meta        Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hpgp_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( HPGP_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'geolocation-plus-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hpgp_add_row_meta', 10, 2 );

/**
 * Shows a notice when a required plugin is missing.
 *
 * Without HivePress there is no settings screen and no extension registry, and without the
 * Geolocation extension there is no location attribute, no map block and no provider setting
 * to extend - in both cases this plugin silently does nothing, so it has to say so.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! class_exists( '\HivePress\Core' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Geolocation Plus for HivePress requires the HivePress plugin to be installed and activated.', 'geolocation-plus-for-hivepress' ) . '</p></div>';

			return;
		}

		if ( ! hivepress()->get_version( 'geolocation' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Geolocation Plus for HivePress requires the HivePress Geolocation extension to be installed and activated.', 'geolocation-plus-for-hivepress' ) . '</p></div>';
		}
	}
);
