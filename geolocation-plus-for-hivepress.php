<?php
/**
 * Plugin Name: Geolocation Plus for HivePress
 * Plugin URI: https://github.com/irapidchris-del/geolocation-plus-for-hivepress
 * Description: Extends the HivePress Geolocation extension with free map providers, custom location attributes, tidier address display, restricted location suggestions and a customisable map block.
 * Version: 1.0.1
 * Author: ChrisB
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

define( 'HPGP_VERSION', '1.0.1' );
define( 'HPGP_FILE', __FILE__ );

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
