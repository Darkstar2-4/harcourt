<?php

namespace Smush\Core\Backups;

use Smush\Core\Controller;
use Smush\Core\Core;
use Smush\Core\File_System;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Media\Media_Item_Optimizer;
use Smush\Core\Product_Analytics\Product_Analytics;
use Smush\Core\Settings;
use WP_Smush;

class Backups_Controller extends Controller {
	/**
	 * @var Media_Item_Cache
	 */
	private $media_item_cache;
	/**
	 * @var \WDEV_Logger|null
	 */
	private $logger;
	/**
	 * @var File_System
	 */
	private $fs;
	/**
	 * @var Backups
	 */
	private $backups;

	public function __construct() {
		$this->media_item_cache = Media_Item_Cache::get_instance();
		$this->logger           = Helper::logger();
		$this->fs               = new File_System();
		$this->backups          = new Backups();

		$this->register_action( 'wp_ajax_smush_restore_image', array( $this, 'handle_restore_ajax' ) );
		$this->register_action( 'delete_attachment', array( $this, 'delete_backup_file' ) );
		$this->register_action( 'wp_ajax_restore_step', array( $this, 'restore_step' ) );
		$this->register_action( 'wp_ajax_get_image_count', array( $this, 'get_image_count' ) );
	}

	public function handle_restore_ajax() {
		if ( empty( $_POST['attachment_id'] ) || empty( $_POST['_nonce'] ) ) {
			wp_send_json_error( array(
				'error_msg' => esc_html__( 'Error in processing restore action, fields empty.', 'wp-smushit' ),
			) );
		}

		$nonce_value   = filter_input( INPUT_POST, '_nonce', FILTER_SANITIZE_SPECIAL_CHARS );
		$attachment_id = filter_input( INPUT_POST, 'attachment_id', FILTER_SANITIZE_NUMBER_INT );

		if ( ! wp_verify_nonce( $nonce_value, "wp-smush-restore-$attachment_id" ) ) {
			wp_send_json_error( array(
				'error_msg' => esc_html__( 'Image not restored, nonce verification failed.', 'wp-smushit' ),
			) );
		}

		// Check capability.
		// TODO: change Helper::is_user_allowed to a non static method
		if ( ! Helper::is_user_allowed( 'upload_files' ) ) {
			wp_send_json_error( array(
				'error_msg' => esc_html__( "You don't have permission to work with uploaded files.", 'wp-smushit' ),
			) );
		}

		$attachment_id = (int) $attachment_id;
		$media_item    = Media_Item_Cache::get_instance()->get( $attachment_id );
		if ( ! $media_item->is_mime_type_supported() ) {
			wp_send_json_error( array(
				'error_msg' => $media_item->get_errors()->get_error_message(),
			) );
		}

		$optimizer = new Media_Item_Optimizer( $media_item );
		$restored  = $optimizer->restore();

		$this->track_single_image_restore( $restored, $optimizer );

		if ( ! $restored ) {
			wp_send_json_error( array(
				'error_msg' => esc_html__( 'Unable to restore image', 'wp-smushit' ),
			) );
		}

		$button_html = WP_Smush::get_instance()->library()->generate_markup( $attachment_id );
		$file_path   = $media_item->get_main_size()->get_file_path();
		$size        = $this->fs->file_exists( $file_path )
			? $this->fs->filesize( $file_path )
			: 0;
		if ( $size > 0 ) {
			$update_size = size_format( $size );
		}

		wp_send_json_success( array(
			'stats'    => $button_html,
			'new_size' => isset( $update_size ) ? $update_size : 0,
		) );
	}

	private function track_single_image_restore( $restored, $optimizer ) {
		$restoration_errors      = $optimizer->get_restoration_errors();
		$missing_backup_detected = ! $restored && ! empty( $restoration_errors->get_error_message( 'missing_backup' ) );

		$limit_per_day = 10;
		Product_Analytics::get_instance()->maybe_track(
			'Single Image Restore',
			array(
				'Total images restored' => $restored ? 1 : 0,
				'Total images'          => 1,
				'Backup not found'      => $missing_backup_detected ? 1 : 0,
				'Backup Status'         => Settings::get_instance()->is_backup_active() ? 'Enabled' : 'Disabled',
			),
			$limit_per_day
		);
	}

	public function delete_backup_file( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( $media_item->is_valid() && $media_item->get_default_backup_size() ) {
			// Delete the file
			$default_backup_path = $media_item->get_default_backup_size()->get_file_path();
			if ( $this->fs->file_exists( $default_backup_path ) ) {
				$this->fs->unlink( $default_backup_path );
			}

			// Delete the meta
			$media_item->remove_default_backup_size();
			$media_item->save();
		} else {
			$this->logger->error( sprintf( 'Count not delete webp versions of the media item [%d]', $attachment_id ) );
		}
	}

	/**
	 * Bulk restore images from the modal.
	 *
	 * @since 3.2.2
	 */
	public function restore_step() {
		check_ajax_referer( 'smush_bulk_restore' );

		// Check for permission.
		if ( ! Helper::is_user_allowed() ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		$id = filter_input( INPUT_POST, 'item', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );

		$media_item = Media_Item_Cache::get_instance()->get( $id );
		if ( ! $media_item->is_mime_type_supported() ) {
			wp_send_json_error(
				array(
					/* translators: %s: Error message */
					'error_msg' => sprintf( esc_html__( 'Image not restored. %s', 'wp-smushit' ), $media_item->get_errors()->get_error_message() ),
				)
			);
		}

		$optimizer = new Media_Item_Optimizer( $media_item );
		$status    = $id && $optimizer->restore();

		$file_name = $media_item->get_full_or_scaled_size()->get_file_name();

		wp_send_json_success(
			array(
				'success' => $status,
				'src'     => ! empty( $file_name ) ? $file_name : __( 'Error getting file name', 'wp-smushit' ),
				'thumb'   => wp_get_attachment_image( $id ),
				'link'    => $media_item->get_edit_link(),
				'error_code' => $status ? '' : $optimizer->get_restoration_errors()->get_error_code(),
			)
		);
	}

	/**
	 * Get the number of attachments that can be restored.
	 *
	 * @since 3.2.2
	 */
	public function get_image_count() {
		check_ajax_referer( 'smush_bulk_restore' );
		// Check for permission.
		if ( ! Helper::is_user_allowed() ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}
		wp_send_json_success(
			array(
				'items' => $this->backups->count_attachments_with_backups(),
			)
		);
	}

}