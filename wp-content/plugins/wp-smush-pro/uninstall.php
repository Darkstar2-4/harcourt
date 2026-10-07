<?php
/**
 * Remove plugin settings data.
 *
 * @since 1.7
 * @package Smush
 */

use Smush\Core\LCP\LCP_Helper;
use Smush\Core\Settings;

// If uninstall not called from WordPress exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

if ( ! class_exists( '\\Smush\\Core\\Settings' ) ) {
	/* @noinspection PhpIncludeInspection */
	include_once plugin_dir_path( __FILE__ ) . '/core/class-settings.php';
}
$keep_data = Settings::get_instance()->get( 'keep_data' );

// Check if someone want to keep the stats and settings.
if ( ( defined( 'WP_SMUSH_PRESERVE_STATS' ) && WP_SMUSH_PRESERVE_STATS ) || true === $keep_data ) {
	return;
}

global $wpdb;

$smushit_keys = array(
	'wp-smush-resmush-list',
	'wp-smush-nextgen-resmush-list',
	'wp-smush-resize_sizes',
	'wp-smush-transparent_png',
	'wp-smush-image_sizes',
	'wp-smush-super_smushed',
	'wp-smush-super_smushed_nextgen',
	'wp-smush-settings_updated',
	'wp-smush-hide_update_info',
	'wp-smush-hide_upgrade_notice',
	'wp-smush-hide_s3support_alert',
	'wp-smush-install-type',
	'wp-smush-version',
	'wp-smush-scan',
	'wp-smush-settings',
	'wp-smush-cdn-advanced-settings',
	'wp-smush-cdn_status',
	'wp-smush-lazy_load',
	'wp-smush-preload',
	'wp-smush-last_run_sync',
	'wp-smush-networkwide',
	'wp-smush-cron_update_running',
	'wp-smush-dismissed-notices',
	'wp-smush-show_upgrade_modal',
	'wp-smush-preset_configs',
	'wp-smush-webp_hide_wizard',
	'wp-smush-hide-tutorials',
	'wp-smush-hide_tutorials_from_bulk_smush', // Possible leftover from 3.8.4.
	'wp-smush-png2jpg-rewrite-rules-flushed',
	'wp-smush-optimization-global-stats',
	'wp-smush-resize-global-stats',
	'wp-smush-png2jpg-global-stats',
	'wp_smush_skip_image_sizes_recheck',
	'wp_smush_image_sizes_state',
	'wp_smush_global_stats',
	'wp_smush_global_stats_json',
	'wp-smush-optimize-list',
	'wp-smush-reoptimize-list',
	'wp-smush-error-items-list',
	'wp-smush-ignored-items-list',
	'wp-smush-animated-items-list',
	'wp-smush-optimize-list-json',
	'wp-smush-reoptimize-list-json',
	'wp-smush-error-items-list-json',
	'wp-smush-ignored-items-list-json',
	'wp-smush-animated-items-list-json',
	'wp-smush-plugin-activated',
	'wp_smush_run_optimize_on_scan_completed',
	'wp-smush-nextgen-reoptimize-list',
	'wp-smush-nextgen-super-smushed-list',
	'wp-smush-webp-global-stats',
	'wp-smush-avif-global-stats',
	'wp_smush_scan_slice_size',
	'wp_smush_media_library_last_process',
	'wp_smush_public_expected_nonces',
	'wp_smush_expected_public_nonces',
	'wp_smush_expected_nonces',
	'wp_smush_background_pre_flight',
	'wp_smush_background_scan_process_status',
	'wp_smush_bulk_smush_background_process_status',
	'wp_smush_event_times',
	'wp-smush-show-new-feature-hotspot',
	'wp-smush-rating-status',
	'wp-smush-api_message',
	'wp_smush_notifications',
	'wp-smush-directory_first_visit_dismissed',
	'wp_smush_event_data',
	'wp_smush_show_connected_modal',
	'wp_smush_error_counts',
	'wp_smush_pre_3_22_site',
	'wp_smush_pre_3_12_6_site',
	'wp_smush_last_scan_completed',
	'wp_smush_next_gen_previously_active_format_key',
	'smush_deactivated',
	'wp-smush-review_prompt_next_show',
	'wp-smush-dir-settings',
);

$db_keys = array(
	'skip-smush-setup',
	'smush_global_stats',
	'wp_smush_stats_nextgen',
);

// Cache Keys.
$cache_smush_group = array(
	'exceeding_items',
	'wp-smush-resize_count',
	'wp-smush-resize_savings',
	'wp-smush-pngjpg_savings',
	'wp-smush-smushed_ids',
	'media_attachments',
	'skipped_images',
	'images_with_backups',
	'wp-smush-dir_total_stats',
);

$cache_nextgen_group = array(
	'wp_smush_images',
	'wp_smush_images_smushed',
	'wp_smush_images_unsmushed',
	'wp_smush_stats_nextgen',
);

if ( ! class_exists( '\Smush\Core\LCP\LCP_Helper' ) ) {
	/* @noinspection PhpIncludeInspection */
	$lcp_helper_file_path = plugin_dir_path( __FILE__ ) . '/core/lcp/class-lcp-helper.php';
	if ( file_exists( $lcp_helper_file_path ) ) {
		include_once $lcp_helper_file_path;
	}
}

if ( ! is_multisite() ) {
	// Delete Options.
	foreach ( $smushit_keys as $key ) {
		delete_option( $key );
	}

	foreach ( $db_keys as $key ) {
		delete_option( $key );
	}

	// Delete Cache data.
	foreach ( $cache_smush_group as $s_key ) {
		wp_cache_delete( $s_key, 'wp-smush' );
	}

	foreach ( $cache_nextgen_group as $n_key ) {
		wp_cache_delete( $n_key, 'nextgen' );
	}

	wp_cache_delete( 'get_image_sizes', 'smush_image_sizes' );

	delete_transient( 'wp-smush-conflict_check' );
	delete_transient( 'wp-smush-conflict-plugins' );

	if ( class_exists( '\Smush\Core\LCP\LCP_Helper' ) ) {
		LCP_Helper::delete_all_lcp_data();
	}
}

// Delete Directory Smush stats.
delete_option( 'dir_smush_stats' );
delete_option( 'wp_smush_scan' );
delete_option( 'wp_smush_api_auth' );
delete_site_option( 'wp_smush_api_auth' );

// Delete user meta.
delete_metadata( 'user', null, 'wp-smush-dismissed-notices', '', true );

// Delete Post meta.
$meta_type  = 'post';
$meta_key   = 'wp-smpro-smush-data';
$meta_value = '';
$delete_all = true;

if ( is_multisite() ) {
	$blog_pattern_keys = array(
		'wp_smush_background_scan_process_',
		'wp_smush_bulk_smush_background_process_'
	);

	$offset = 0;
	$limit  = 100;
	while ( $blogs = $wpdb->get_results( "SELECT blog_id FROM {$wpdb->blogs} LIMIT $offset, $limit", ARRAY_A ) ) {
		if ( $blogs ) {
			foreach ( $blogs as $blog ) {
				switch_to_blog( $blog['blog_id'] );
				delete_metadata( $meta_type, null, $meta_key, $meta_value, $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-lossy', '', $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-resize_savings', '', $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-original_file', '', $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-pngjpg_savings', '', $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-animated', '', $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-transparent', '', $delete_all );
				delete_metadata( $meta_type, null, 'wp-smush-ignore-bulk', '', $delete_all );

				foreach ( $blog_pattern_keys as $pattern ) {
					delete_site_option($pattern . $blog['blog_id'] . '_status');
				}

				foreach ( $smushit_keys as $key ) {
					delete_option( $key );
					delete_site_option( $key );
				}

				foreach ( $db_keys as $key ) {
					delete_option( $key );
					delete_site_option( $key );
				}

				// Delete Cache data.
				foreach ( $cache_smush_group as $s_key ) {
					wp_cache_delete( $s_key, 'wp-smush' );
				}


				wp_cache_delete( 'get_image_sizes', 'smush_image_sizes' );
				if ( class_exists( '\Smush\Core\LCP\LCP_Helper' ) ) {
					LCP_Helper::delete_all_lcp_data();
				}
			}
			restore_current_blog();
		}
		$offset += $limit;
	}
} else {
	delete_metadata( $meta_type, null, $meta_key, $meta_value, $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-lossy', '', $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-resize_savings', '', $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-original_file', '', $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-pngjpg_savings', '', $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-animated', '', $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-transparent', '', $delete_all );
	delete_metadata( $meta_type, null, 'wp-smush-ignore-bulk', '', $delete_all );
}
// Delete Directory smush table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}smush_dir_images" );

// Delete directory scan data.
delete_option( 'wp-smush-scan-step' );

// Delete all WebP images.
global $wp_filesystem;
if ( is_null( $wp_filesystem ) ) {
	WP_Filesystem();
}

$upload_dir = wp_get_upload_dir();
$webp_dir   = dirname( $upload_dir['basedir'] ) . '/smush-webp';
$wp_filesystem->delete( $webp_dir, true );

// Delete WebP test image.
$webp_img = $upload_dir['basedir'] . '/smush-webp-test.png';
$wp_filesystem->delete( $webp_img );

// TODO: Add procedure to delete backup files
// TODO: Update NextGen Metadata to remove Smush stats on plugin deletion.