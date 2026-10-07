<?php

namespace Smush\Core\Bulk;

use Smush\Core\Controller;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Media\Media_Item_Optimizer;

/**
 * Tracks the savings accumulated by the current/last bulk Smush session.
 *
 */
class Bulk_Smush_Session_Savings extends Controller {
	private static $option_key = 'smush_bulk_session_savings';

	/**
	 * Static instance.
	 *
	 * @var self
	 */
	private static $instance;

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		// Every bulk Smush session starts from zero.
		$this->register_action( 'wp_smush_bulk_smush_start', array( $this, 'reset' ) );
		// Accumulate the savings of each image optimized by the bulk process.
		$this->register_action( 'image_smushed', array( $this, 'add_image_savings' ) );
		// Expose the running total to the frontend poll response.
		$this->register_filter( 'wp_smush_frontend_poll_data', array( $this, 'add_to_poll_data' ) );
		// Serve the session total to the bulk Smush completion modal.
		$this->register_action( 'wp_ajax_bulk_smush_get_stats', array( $this, 'ajax_get_stats' ) );
	}

	/**
	 * Return the session savings total to the completion modal.
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'wp-smush-ajax', '_nonce' );

		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		wp_send_json_success( array(
			'session_savings_bytes' => $this->get_savings(),
		) );
	}

	/**
	 * Reset the accumulated savings for a new bulk Smush session.
	 */
	public function reset() {
		update_option( self::$option_key, 0, false );
	}

	/**
	 * Add the savings of a single image optimized by the bulk process.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function add_image_savings( $attachment_id ) {
		$bytes = $this->get_attachment_savings_bytes( $attachment_id );
		if ( $bytes <= 0 ) {
			return;
		}

		update_option( self::$option_key, $this->get_savings() + $bytes, false );
	}

	/**
	 * Savings accumulated by the current/last bulk Smush session, in bytes.
	 *
	 * @return int
	 */
	public function get_savings() {
		return (int) get_option( self::$option_key, 0 );
	}

	/**
	 * Add the running session savings total to the frontend poll response.
	 *
	 * @param array $data Polling data array.
	 *
	 * @return array
	 */
	public function add_to_poll_data( $data ) {
		$data['bulk_smush_session_savings'] = $this->get_savings();

		return $data;
	}

	/**
	 * Total optimization savings currently recorded for an attachment, in bytes.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return int
	 */
	private function get_attachment_savings_bytes( $attachment_id ) {
		$media_item = Media_Item_Cache::get_instance()->get( $attachment_id );
		if ( ! $media_item ) {
			return 0;
		}

		$optimizer = new Media_Item_Optimizer( $media_item );

		return $optimizer->get_total_stats()->get_bytes();
	}
}