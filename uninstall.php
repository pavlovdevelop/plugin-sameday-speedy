<?php
/**
 * Fired during plugin uninstallation.
 *
 * This file is executed when the plugin is uninstalled.
 *
 * @since      1.0.0
 * @package    Sameday_Woocommerce_Bg
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options
delete_option( 'sameday_woocommerce_bg_settings' );
delete_option( 'sameday_locations' );

// Remove plugin settings
delete_site_option( 'sameday_woocommerce_bg_settings' );
delete_site_option( 'sameday_locations' );