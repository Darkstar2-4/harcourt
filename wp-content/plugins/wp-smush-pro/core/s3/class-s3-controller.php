<?php

namespace Smush\Core\S3;

use DeliciousBrains\WP_Offload_Media\Integrations\Media_Library;
use DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item;
use Smush\Core\Controller;
use Smush\Core\File_System;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Membership\Membership;
use Smush\Core\Settings;
use WDEV_Logger;

class S3_Controller extends Controller {
	private static $as3cf_get_attached_file_priority = - 10;

	private static $s3_setting_key = 's3';

	private $media_item_cache;
	/**
	 * @var WP_Offload_Media_Api
	 */
	private $wp_offload_media;
	/**
	 * @var WDEV_Logger
	 */
	private $logger;
	/**
	 * @var Settings
	 */
	private $settings;
	/**
	 * @var File_System
	 */
	private $fs;
	private $membership;

	public function __construct() {
		$this->media_item_cache = Media_Item_Cache::get_instance();
		$this->wp_offload_media = new WP_Offload_Media_Api();
		$this->logger           = Helper::logger()->integrations();
		$this->settings         = Settings::get_instance();
		$this->fs               = new File_System();
		$this->membership       = Membership::get_instance();

		$this->register_action( 'init', array( $this, 'maybe_initialize' ), - 10 );
		// TODO: [WPMUDEV SMUSH UI] the following three methods no longer work because of the new UI
		$this->register_action( 'smush_setting_column_right_inside', array( $this, 's3_setup_message' ), 15 );
		$this->register_action( 'wp_smush_header_notices', array( $this, 'show_s3_support_required_notice' ) );
		$this->register_filter( 'wp_smush_should_fetch_external_image_dimensions', array( $this, 'allow_fetch_image_dimensions_from_s3' ), 10, 2 );
		$this->register_action( 'wp_ajax_dismiss_s3support_alert', array( $this, 'dismiss_s3support_alert' ) );
	}

	public function maybe_initialize() {
		$wp_offload_media_active = $this->wp_offload_media_active();
		if ( ! $wp_offload_media_active || ! $this->settings->is_s3_active() ) {
			return;
		}

		// TODO: PNG2Jpg file names should not exist on the server?
		// TODO: check whether we need to check is_plugin_setup, remove-local-file and copy-to-s3 settings from wp-offload

		$this->support_s3_image_optimization();

		$this->support_s3_backup_and_restore();

		add_filter( 'wp_smush_media_item_size', array( $this, 'initialize_s3_size' ), 10, 4 );
	}

	public function before_restore( $callback, $priority ) {
		add_action( 'wp_smush_before_restore_backup', $callback, $priority, 2 );
	}

	public function before_restore_attempt( $callback, $priority ) {
		add_action( 'wp_smush_before_restore_backup_attempt', $callback, $priority, 1 );
	}

	public function after_restore( $callback, $priority ) {
		add_action( 'wp_smush_after_restore_backup', $callback, $priority, 3 );
	}

	public function disable_s3_auto_download() {
		add_filter( 'as3cf_get_attached_file_copy_back_to_local', array( $this, 'return_false' ) );
	}

	public function enable_back_s3_auto_download() {
		add_filter( 'as3cf_get_attached_file_copy_back_to_local', array( $this, 'return_false' ) );
	}

	public function download_all_sizes( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( ! $this->is_media_item_valid( $media_item ) ) {
			return;
		}

		foreach ( $media_item->get_sizes() as $size ) {
			if ( ! is_a( $size, '\Smush\Core\S3\S3_Media_Item_Size' ) ) {
				$this->log_error( 'Something went wrong while trying to download the images for Smush.' );
				continue;
			}

			if ( ! $this->fs->file_exists( $size->get_local_path() ) ) {
				$this->download_remote_file( $attachment_id, $size->get_local_path() );
			}
		}
	}

	public function download_backup_file( $file_path, $attachment_id ) {
		if ( ! $this->fs->file_exists( $file_path ) ) {
			$this->download_remote_file( $attachment_id, $file_path );
		}
	}

	private function download_remote_file( $attachment_id, $file_path ) {
		$s3_library_item = $this->get_s3_media_item( $attachment_id );
		if ( $s3_library_item ) {
			$this->wp_offload_media->copy_provider_file_to_server( $s3_library_item, $file_path );
		}

		if ( ! $this->fs->file_exists( $file_path ) ) {
			$this->log_error( "Failed to download remote file $attachment_id." );
		}
	}

	public function disable_s3_update_attachment( $data ) {
		add_filter( 'as3cf_pre_update_attachment_metadata', array( $this, 'return_true' ) );

		return $data;
	}

	public function enable_back_s3_update_attachment() {
		remove_filter( 'as3cf_pre_update_attachment_metadata', array( $this, 'return_true' ) );
	}

	public function return_true() {
		return true;
	}

	public function return_false() {
		return false;
	}

	/**
	 * @return bool
	 */
	private function wp_offload_media_active() {
		return function_exists( 'as3cf_init' ) || function_exists( 'as3cf_pro_init' );
	}

	/**
	 * @param Media_Item $media_item
	 *
	 * @return bool
	 */
	private function is_media_item_valid( $media_item ) {
		$invalid = ! $media_item || empty( $media_item->get_wp_metadata() );
		if ( $invalid ) {
			$this->log_error( 'Media item is not valid.' );
		}

		return ! $invalid;
	}

	public function disable_s3_get_attached_file_filters() {
		// Make sure smush always gets local paths
		$this->disable_stream_wrapper_file();
		// S3 auto downloads an image when get_attached_file is called, we want to disable this, because we will explicitly download all media item sizes.
		$this->disable_s3_auto_download();
		// Reset media items, so they have to fetch the new values
		$this->media_item_cache->reset_all();
	}

	public function enable_back_s3_get_attached_file_filters() {
		$this->enable_back_stream_wrapper_file();
		$this->enable_back_s3_auto_download();
		$this->media_item_cache->reset_all();
	}

	private function disable_stream_wrapper_file() {
		add_filter(
			'as3cf_get_attached_file',
			array( $this, 'return_local_file_path' ),
			self::$as3cf_get_attached_file_priority, // Our callback needs to run before the s3 callback get_stream_wrapper_file
			2
		);
	}

	private function enable_back_stream_wrapper_file() {
		remove_filter( 'as3cf_get_attached_file', array(
			$this,
			'return_local_file_path',
		), self::$as3cf_get_attached_file_priority );
	}

	public function return_local_file_path( $url, $file_path ) {
		return $file_path;
	}

	/**
	 * @param callable $callback
	 *
	 * @return void
	 */
	private function before_smush( $callback, $priority ) {
		add_action( 'wp_smush_before_smush_file', $callback, $priority );
	}

	private function before_smush_attempt( $callback, $priority ) {
		add_action( 'wp_smush_before_smush_attempt', $callback, $priority );
	}

	/**
	 * @param callable $callback
	 *
	 * @return void
	 */
	private function after_smush( $callback, $priority ) {
		add_action( 'wp_smush_after_smush_file', $callback, $priority );
	}

	/**
	 * @param $attachment_id
	 *
	 * @return void
	 */
	public function trigger_update_attachment_metadata( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( ! $this->is_media_item_valid( $media_item ) ) {
			return;
		}
		wp_update_attachment_metadata( $attachment_id, $media_item->get_wp_metadata() );
	}

	/**
	 * @param $size
	 * @param $key
	 * @param $metadata
	 * @param $media_item Media_Item
	 *
	 * @return S3_Media_Item_Size
	 */
	public function initialize_s3_size( $size, $key, $metadata, $media_item ) {
		return new S3_Media_Item_Size(
			$key,
			$media_item->get_id(),
			$media_item->get_dir(),
			$media_item->get_base_url(),
			$metadata
		);
	}

	/**
	 * @param $attachment_id
	 *
	 * @return Media_Library_Item|null
	 */
	private function get_s3_media_item( $attachment_id ) {
		return $this->wp_offload_media->is_attachment_served_by_provider( $attachment_id, true );
	}

	/**
	 * @return void
	 */
	private function support_s3_image_optimization() {
		/**
		 * Prevent frequent offloading attempts
		 */
		// During the optimization we might call wp_update_attachment_metadata multiple times. Prevent any offload attempts while smushing is in progress.
		$this->before_smush( array( $this, 'disable_s3_update_attachment' ), 10 );

		/**
		 * Ensure smush has access to local files during optimization
		 */
		// Download any of the sizes that don't exist locally
		$this->before_smush( array( $this, 'download_all_sizes' ), 20 );

		/**
		 * Delete remote version before uploading optimized
		 */
		// When all optimizations are completed, the new files will be uploaded.
		// Note that this is especially important for Png2Jpg optimization for getting rid of the old files from the servers. The new files are nothing like the old ones.
		add_action( 'wp_smush_png_jpg_converted', array( $this, 'delete_old_png_files_after_convert' ), 10, 4 );

		/**
		 * Trigger offloading after smush is done
		 */
		// Turn offloading back on
		$this->after_smush( array( $this, 'enable_back_s3_update_attachment' ), 20 );
		// Trigger offloading
		$this->after_smush( array( $this, 'trigger_update_attachment_metadata' ), 30 );

		/**
		 * Delay offloading on new media upload when auto smush is on
		 */
		$auto_smush_on = $this->settings->get( 'auto' );
		if ( $auto_smush_on ) {
			/**
			 * We need to prevent {@see Media_Library::wp_update_attachment_metadata()} from getting called
			 */

			// New media upload triggers wp_update_attachment_metadata which triggers offloading. Make sure offloading is postponed until smush is done.
			add_filter( 'add_attachment', array( $this, 'disable_s3_update_attachment' ) );

			$priority = 100; // This has to be higher than other methods attached to this hook because $media_item->is_skipped() depends on those other methods
			$this->after_attachment_upload( array( $this, 'offload_if_media_item_not_optimizable' ), $priority );
			$this->before_smush_attempt( array( $this, 'offload_if_media_item_not_optimizable' ), $priority );
		}
	}

	/**
	 * @return void
	 */
	private function support_s3_backup_and_restore() {
		/**
		 * Disable remote file filters during the restore process
		 */
		$this->before_restore_attempt( array( $this, 'disable_s3_get_attached_file_filters' ), 10 );

		/**
		 * Ensure smush has access to local files during restoration
		 */
		$this->before_restore( array( $this, 'download_backup_file' ), 10 );
		/**
		 * Disable offloading
		 */
		$this->before_restore( array( $this, 'disable_s3_update_attachment' ), 20 );

		/**
		 * Delete remote version before uploading restored
		 */
		// When the restoration is completed, the new files will be uploaded. Again, this is especially important for Png2Jpg
		add_action( 'wp_smush_after_restore_png_jpg', array( $this, 'delete_old_jpg_files_after_restore' ), 10, 2 );

		/**
		 * Trigger offloading after restore is done
		 */
		$this->after_restore( array( $this, 'enable_back_s3_update_attachment' ), 20 );

		$this->after_restore( array( $this, 'enable_back_s3_get_attached_file_filters' ), 30 );

		$this->after_restore( function ( $restored, $backup_file_path, $attachment_id ) {
			if ( $restored ) {
				$this->wp_offload_media->delete_remote_files( $backup_file_path, $attachment_id );
				$this->trigger_update_attachment_metadata( $attachment_id );
			}
		}, 40 );
	}

	private function log_error( $error ) {
		$this->logger->error( "Smush S3 Integration: $error" );
	}

	private function is_media_item_optimizable( $media_item ) {
		return ! $this->is_media_item_not_optimizable( $media_item );
	}

	/**
	 * @param Media_Item $media_item
	 *
	 * @return bool
	 */
	private function is_media_item_not_optimizable( $media_item ) {
		return ! $media_item->is_valid() || $media_item->has_errors() || $media_item->is_skipped();
	}

	public function offload_if_media_item_not_optimizable( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( $this->is_media_item_not_optimizable( $media_item ) ) {
			// If there is an error we want the image to be offloaded explicitly
			$this->enable_back_s3_update_attachment();
			$this->trigger_update_attachment_metadata( $attachment_id );
		}
		// We have already added hooks for enabling back and triggering offloading after successful smush.
	}

	private function after_attachment_upload( $callback, $priority ) {
		add_action( 'wp_smush_after_attachment_upload', $callback, $priority );
	}

	public function delete_old_png_files_after_convert( $attachment_id, $image_metadata, $media_item_stats, $png_file_paths ) {
		if ( empty( $png_file_paths ) ) {
			return;
		}

		$this->after_smush( function () use ( $png_file_paths, $attachment_id ) {
			$this->wp_offload_media->delete_remote_files( $png_file_paths, $attachment_id );
		}, 50 );
	}

	public function delete_old_jpg_files_after_restore( $media_item, $jpg_file_paths ) {
		if ( empty( $jpg_file_paths ) ) {
			return;
		}

		$attachment_id = $media_item->get_id();
		$this->after_restore( function ( $restored ) use ( $jpg_file_paths, $attachment_id ) {
			if ( $restored ) {
				$this->wp_offload_media->delete_remote_files( $jpg_file_paths, $attachment_id );
			}
		}, 50 );
	}

	/**
	 * Prints the message for S3 setup
	 *
	 * TODO: [WPMUDEV SMUSH UI] this no longer works in react
	 *
	 * @param string $setting_key Settings key.
	 */
	public function s3_setup_message( $setting_key ) {
		// Return if not S3.
		$s3_setting_key = self::$s3_setting_key;
		if ( $s3_setting_key !== $setting_key ) {
			return;
		}

		// If S3 integration is not enabled, return.
		$is_s3_active            = $this->settings->is_s3_active();
		$wp_offload_media_active = $this->wp_offload_media_active();

		// If integration is disabled when S3 offload is active, do not continue.
		if ( ! $is_s3_active && $wp_offload_media_active ) {
			return;
		}

		// If S3 offload global variable is not available, plugin is not active.
		if ( ! $wp_offload_media_active ) {
			$class   = '';
			$message = __( 'To use this feature you need to install WP Offload Media and have an Amazon S3 account setup.', 'wp-smushit' );
		} elseif ( $this->wp_offload_media->is_plugin_setup() === null || $this->wp_offload_media->get_plugin_page_url() === null ) {
			// Check if in case for some reason, we couldn't find the required function.
			$class   = ' sui-notice-warning';
			$message = sprintf( /* translators: %1$s: opening a tag, %2$s: closing a tag */
				esc_html__(
					'We are having trouble interacting with WP Offload Media, make sure the plugin is activated. Or you can %1$sreport a bug%2$s.',
					'wp-smushit'
				),
				'<a href="' . esc_url( 'https://wpmudev.com/contact' ) . '" target="_blank">',
				'</a>'
			);
		} elseif ( ! $this->wp_offload_media->is_plugin_setup() ) {
			// Plugin is not setup, or some information is missing.
			$class   = ' sui-notice-warning';
			$message = sprintf( /* translators: %1$s: opening a tag, %2$s: closing a tag */
				esc_html__(
					'It seems you haven’t finished setting up WP Offload Media yet. %1$sConfigure it now%2$s to enable Amazon S3 support.',
					'wp-smushit'
				),
				'<a href="' . $this->wp_offload_media->get_plugin_page_url() . '" target="_blank">',
				'</a>'
			);
		} else {
			// S3 support is active.
			$class   = ' sui-notice-info';
			$message = __( 'Amazon S3 support is active.', 'wp-smushit' );
		}
		?>
		<div class="sui-toggle-content">
			<div class="sui-notice<?php echo esc_attr( $class ); ?>">
				<div class="sui-notice-content">
					<div class="sui-notice-message">
						<i class="sui-notice-icon sui-icon-info" aria-hidden="true"></i>
						<p><?php echo wp_kses_post( $message ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Error message to show when S3 support is required.
	 *
	 * Show a error message to admins, if they need to enable S3 support. If "remove files from
	 * server" option is enabled in WP Offload Media plugin, we need WP Smush Pro to enable S3 support.
	 *
	 * @return void
	 */
	public function show_s3_support_required_notice() {
		// Do not display it for other users. Do not display on network screens, if network-wide option is disabled.
		if ( ! current_user_can( 'manage_options' ) || ! Settings::can_access( 'integrations' ) ) {
			return;
		}

		// If already dismissed, do not show.
		if ( '1' === get_site_option( 'wp-smush-hide_s3support_alert' ) ) {
			return;
		}

		// Return early, if support is not required.
		if ( ! $this->wp_offload_media_active() || $this->settings->is_s3_active() ) {
			return;
		}

		// Settings link.
		$settings_link = is_multisite() && is_network_admin()
			? network_admin_url( 'admin.php?page=smush-integrations' )
			: menu_page_url( 'smush-integrations', false );

		if ( $this->membership->is_pro() ) {
			/**
			 * If premium user, but S3 support is not enabled.
			 */
			$message = sprintf(
			/* Translators: %1$s: opening strong tag, %2$s: closing strong tag, %s: settings link, %3$s: opening a and strong tags, %4$s: closing a and strong tags */
				__(
					'We can see you have WP Offload Media installed. If you want to optimize your S3 images, you’ll need to enable the %3$sAmazon S3 Support%4$s feature in Smush’s Integrations.',
					'wp-smushit'
				),
				'<strong>',
				'</strong>',
				"<a href='$settings_link'><strong>",
				'</strong></a>'
			);
		} else {
			/**
			 * If not a premium user.
			 */
			$message = sprintf(
			/* Translators: %1$s: opening strong tag, %2$s: closing strong tag, %s: settings link, %3$s: opening a and strong tags, %4$s: closing a and strong tags */
				__(
					"We can see you have WP Offload Media installed. If you want to optimize your S3 images you'll need to %3\$supgrade to Smush Pro%4\$s",
					'wp-smushit'
				),
				'<strong>',
				'</strong>',
				'<a href=' . esc_url( 'https://wpmudev.com/project/wp-smush-pro' ) . '><strong>',
				'</strong></a>'
			);
		}
		$message = '<p>' . $message . '</p>';
		echo '<div role="alert" id="wp-smush-s3support-alert" class="sui-notice" data-message="' . esc_attr( $message ) . '" aria-live="assertive"></div>';
	}

	public function allow_fetch_image_dimensions_from_s3( $allow_fetch, $image_url ) {
		if ( $allow_fetch || ! $this->wp_offload_media->get_setting( 'serve-from-s3' ) ) {
			return $allow_fetch;
		}

		$delivery_domain = $this->wp_offload_media->get_delivery_domain();
		if ( ! empty( $delivery_domain ) ) {
			$allow_fetch = strpos( $image_url, $delivery_domain ) !== false;
		}

		return $allow_fetch;
	}

	/**
	 * Hide S3 support alert by setting a flag.
	 */
	public function dismiss_s3support_alert() {
		check_ajax_referer( 'wp-smush-ajax' );
		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}
		// Just set a flag.
		update_site_option( 'wp-smush-hide_s3support_alert', 1 );
		wp_send_json_success();
	}
}