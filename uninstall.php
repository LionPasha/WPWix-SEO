<?php
/**
 * WPWix SEO kaldırıldığında tüm verisini temizler:
 * option'lar, transient'ler ve _wpwixseo_* postmeta kayıtları.
 *
 * @package WPWix_SEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpwixseo_settings' );
delete_option( 'wpwixseo_bulk_state' );
delete_transient( 'wpwixseo_sitemap' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_wpwixseo\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup, tek seferlik.
