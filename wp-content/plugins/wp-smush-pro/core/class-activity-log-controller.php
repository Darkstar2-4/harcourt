<?php
namespace Smush\Core;

use Smush\Core\Bulk\Background_Bulk_Smush_Controller;
use Smush\Core\Helper;
use Smush\Core\Media_Library\Background_Media_Library_Scanner;
use Smush\Core\Threads\JSON_Object_Array;
use Smush\Core\Modules\Helpers\WhiteLabel;

class Activity_Log_Controller extends Controller {
	/**
	 * Notification data key.
	 *
	 * @var string
	 */
	private static $notification_data_key = 'wp_smush_notifications';

	/**
	 * Maximum number of notifications.
	 *
	 * @var int
	 */
	private static $max_notification = 50;

	public static function get_max_notifications() {
		return self::$max_notification;
	}

	/**
	 * @var Media_Library_Scan_Background_Process
	 */
	protected $scan_background_process;

	/**
	 * @var Bulk_Smush_Background_Process
	 */
	protected $bulk_background_process;

	/**
	 * @var String_Utils
	 */
	protected $string_utils;

	/**
	 * @var JSON_Object_Array
	 */
	private $object_array;

	/**
	 * Activity_Log_Controller instance.
	 *
	 * @var mixed
	 */
	private static $instance;

	/**
	 * @var WhiteLabel
	 */
	private $whitelabel;

	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Activity_Log_Controller_Pro();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->string_utils            = new String_Utils();
		$this->object_array            = new JSON_Object_Array( self::$notification_data_key );
		$this->whitelabel              = new WhiteLabel();
		$this->scan_background_process = Background_Media_Library_Scanner::get_instance()->get_background_process();
		$this->bulk_background_process = Background_Bulk_Smush_Controller::get_instance()->get_background_process();

		$this->register_filter( 'wp_smush_localize_ui_script_data', array( $this, 'localized_data_for_ui' ) );

		$this->register_action( 'wp_ajax_smush_add_notification', array( $this, 'ajax_add_notification' ) );
		$this->register_action( 'wp_ajax_smush_get_notifications', array( $this, 'ajax_get_notifications' ) );

		$log_priority = 100;
		// $this->register_action( 'wp_smush_config_applied', array( $this, 'log_config_applied' ), $log_priority );

		// Scan.
		$identifier             = $this->scan_background_process->get_identifier();
		$scan_dead_action      = "{$identifier}_dead";
		/**
		 * Track early to ensure we capture the scan completed timing correctly,
		 * since Bulk Smush can be auto-started when a scan is completed.
		 */
		$log_scan_completed_priority = 5;
		$this->register_action( "{$identifier}_completed", array( $this, 'log_scan_completed' ), $log_scan_completed_priority );
		$this->register_action( $scan_dead_action, array( $this, 'log_scan_process_death' ), $log_priority );

		// Bulk Smush.
		$identifier              = $this->bulk_background_process->get_identifier();
		$bulkd_smush_dead_action = "{$identifier}_dead";
		$this->register_action( 'wp_smush_bulk_smush_completed', array( $this, 'log_bulk_smush_completed' ), $log_priority );
		$this->register_action( $bulkd_smush_dead_action, array( $this, 'log_bulk_smush_process_death' ), $log_priority );

		$this->register_action( 'wp_smush_cdn_activated', array( $this, 'log_cdn_activated' ), $log_priority );
	}

	/**
	 * Localize data for the UI.
	 *
	 * @param array $data
	 * @return array
	 */
	public function localized_data_for_ui( $data ) {
		$data['activityLog'] = array(
			'notifications' => $this->get_notifications_for_ui(),
		);
		return $data;
	}

	/**
	 * AJAX handler for adding a notification from the UI.
	 *
	 * @return void
	 */
	public function ajax_add_notification() {
		check_ajax_referer( 'wp-smush-ajax' );

		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-smushit' ) ), 403 );
		}

		$raw = isset( $_POST['notification'] ) ? wp_unslash( $_POST['notification'] ) : '';
		$notification = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( empty( $notification ) || ! is_array( $notification ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid notification data.', 'wp-smushit' ) ) );
		}

		$success = $this->add_notification( $notification );

		if ( ! $success ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save notification.', 'wp-smushit' ) ) );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler for fetching the latest notifications for the UI.
	 * Called after a long-running background process completes or dies on-page,
	 * so the frontend can replace optimistic entries with server-written ones.
	 *
	 * @return void
	 */
	public function ajax_get_notifications() {
		check_ajax_referer( 'wp-smush-ajax' );

		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-smushit' ) ), 403 );
		}

		wp_send_json_success( array(
			'notifications' => $this->get_notifications_for_ui(),
		) );
	}

	private function get_notifications_for_ui() {
		$this->enforce_notification_limit();
		$notifications = $this->get_notifications();

		if ( empty( $notifications ) ) {
			$this->add_notification(
				array(
					'module'  => 'smush',
					'content' => $this->string_utils->get_raw_string( 'Welcome to Smush', $this->whitelabel->replace_branding_terms( __( 'Welcome to Smush', 'wp-smushit' ) ) ),
				)
			);
			$notifications = $this->get_notifications();
		}

		return array_map( array( $this, 'sanitize_notification_for_ui' ), $notifications );
	}



	/**
	 * Track the completion of a scan process.
	 *
	 * @return void
	 */
	public function log_scan_completed() {
		$this->add_notification(
			array(
				'module'  => 'scan',
				'content' => $this->string_utils->get_raw_string( 'Scan completed.', __( 'Scan completed.', 'wp-smushit' ) ),
			)
		);
	}


	/**
	 * Track the death of a scan process.
	 *
	 * @return void
	 */
	public function log_scan_process_death() {
		$this->add_notification(
			array(
				'module'  => 'scan',
				'content' => $this->string_utils->get_raw_string( 'Scan failed.', __( 'Scan failed.', 'wp-smushit' ) ),
			)
		);
	}

	/**
	 * Track the completion of a bulk smush process.
	 *
	 * @return void
	 */
	public function log_bulk_smush_completed() {
		$this->add_notification(
			array(
				'module'  => 'bulk_smush',
				'content' => $this->string_utils->get_raw_string( 'Bulk Optimization completed.', __( 'Bulk Optimization completed.', 'wp-smushit' ) ),
			)
		);
	}


	/**
	 * Track the death of a bulk smush process.
	 *
	 * @return void
	 */
	public function log_bulk_smush_process_death() {
		$this->add_notification(
			array(
				'module'  => 'bulk_smush',
				'content' => $this->string_utils->get_raw_string( 'Bulk Optimization failed.', __( 'Bulk Optimization failed.', 'wp-smushit' ) ),
			)
		);
	}

	/**
	 * Track CDN going fully active after provisioning completes.
	 *
	 * @return void
	 */
	public function log_cdn_activated() {
		$this->add_notification(
			array(
				'module'  => 'cdn',
				'content' => $this->string_utils->get_raw_string( 'CDN setup complete.', __( 'CDN setup complete.', 'wp-smushit' ) ),
			)
		);
	}

	/**
	 * Get the notifications.
	 *
	 * Reads directly from the database (bypasses object cache) to ensure
	 * writes from other background processes are visible.
	 *
	 * @return array
	 */
	public function get_notifications() {
		$notifications = $this->object_array->get( array() );

		return is_array( $notifications ) ? $notifications : array();
	}

	/**
	 * Add a notification atomically.
	 *
	 * Uses a single JSON_ARRAY_APPEND database query so concurrent background
	 * processes never overwrite each other's entries.
	 *
	 * @param mixed $notification Notification data.
	 *
	 * @return bool True if the notification was successfully stored, false otherwise.
	 */
	public function add_notification( $notification ) {
		$sanitized_notification = $this->sanitize_notification( $notification );
		if ( empty( $sanitized_notification ) ) {
			return false;
		}

		$result = $this->object_array->append( $sanitized_notification );

		return $result !== false && $result > 0;
	}

	/**
	 * Sanitize a notification for display in the UI.
	 * Translates the notification content.
	 *
	 * @param array $notification Notification data.
	 *
	 * @return array Sanitized notification data.
	 */
	private function sanitize_notification_for_ui( $notification ) {
		$sanitized = $this->sanitize_notification( $notification );
		if ( empty( $sanitized['content'] ) ) {
			return array();
		}

		$sanitized['content'] = $this->string_utils->get_translated_string( $sanitized['content'] );
		return $sanitized;
	}

	/**
	 * Sanitize a notification.
	 *
	 * @param mixed $notification Notification data.
	 *
	 * @return array{id: string, timestamp: string|int, type: string, content: string, url: string}
	 */
	private function sanitize_notification( $notification ) {
		if ( empty( $notification['content'] ) ) {
			return array();
		}
		// Sanitize and ensure all expected fields exist.
		$sanitized              = array();
		$sanitized['id']        = isset( $notification['id'] ) ? sanitize_text_field( $notification['id'] ) : uniqid( 'smush_notification_' );
		$sanitized['timestamp'] = isset( $notification['timestamp'] ) ? sanitize_text_field( $notification['timestamp'] ) : microtime( true );
		$sanitized['type']      = isset( $notification['type'] ) ? sanitize_text_field( $notification['type'] ) : 'info';
		$sanitized['module']    = isset( $notification['module'] ) ? sanitize_text_field( $notification['module'] ) : '';
		$sanitized['content']   = isset( $notification['content'] ) ? sanitize_text_field( $notification['content'] ) : '';
		$sanitized['url']       = isset( $notification['url'] ) ? sanitize_text_field( $notification['url'] ) : '';
		return $sanitized;
	}

	/**
	 * Trim the stored list to the maximum allowed size, keeping the most recent entries.
	 *
	 * Called once per UI read (localize / poll), so background processes can append
	 * atomically without any per-write overhead. The worst-case outcome of deferring
	 * this to read-time is storing a few extra entries between writes.
	 *
	 * @return void
	 */
	private function enforce_notification_limit() {
		$notifications = $this->get_notifications();

		if ( count( $notifications ) <= self::$max_notification ) {
			return;
		}

		// Sort newest-first, then keep only the allowed maximum.
		usort(
			$notifications,
			function ( $a, $b ) {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);
		$notifications = array_slice( $notifications, 0, self::$max_notification );

		// Overwrite the option with the trimmed, sorted list.
		// Uses replace_array() so the value is stored as JSON, keeping it
		// consistent with the JSON-based read in get_notifications().
		$this->object_array->unsafe_replace( $notifications );
	}
}