<?php

namespace Smush\Core\Media_Library;

use Smush\Core\Background\Process_Status_DTO;
use Smush\Core\Controller;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Query;
use Smush\Core\Stats\Global_Stats;
use WP_Error;

class Background_Media_Library_Scanner extends Controller {
	private static $optimize_on_completed_option_key = 'wp_smush_run_optimize_on_scan_completed';
	private static $last_scan_completed_option_key = 'wp_smush_last_scan_completed';
	/**
	 * @var Media_Library_Scanner
	 */
	private $scanner;
	/**
	 * @var Media_Library_Scan_Background_Process
	 */
	private $background_process;

	private $logger;

	/**
	 * @var bool
	 */
	private $optimize_on_scan_completed;
	/**
	 * @var Global_Stats
	 */
	private $global_stats;

	/**
	 * Static instance
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

	private function __construct() {
		$this->scanner            = new Media_Library_Scanner();
		$this->logger             = Helper::logger();
		$this->global_stats       = Global_Stats::get();
		$identifier               = $this->make_identifier();
		$this->background_process = new Media_Library_Scan_Background_Process( $identifier, $this->scanner );
		$this->background_process->set_logger( Helper::logger() );

		$this->register_action( 'wp_ajax_wp_smush_start_background_scan', array( $this, 'start_background_scan' ) );
		$this->register_action( 'wp_ajax_wp_smush_cancel_background_scan', array( $this, 'cancel_background_scan' ) );
		$this->register_action( 'wp_ajax_wp_smush_get_background_scan_status', array( $this, 'send_status' ) );
		$this->register_action( 'wp_ajax_wp_smush_reset_background_scan_status', array( $this, 'reset_background_scan_status' ) );
		$this->register_action( "{$identifier}_completed", array( $this, 'background_process_completed' ) );
		$this->register_action( "{$identifier}_dead", array( $this, 'background_process_dead' ) );

		$this->register_filter( 'wp_smush_frontend_poll_data', array( $this, 'add_scan_progress_to_poll' ) );

		// TODO: [WPMUDEV SMUSH UI] None is localized via old script data, need to implement localization.
		add_filter( 'wp_smush_script_data', array( $this, 'localize_media_library_scan_script_data' ) );
		add_filter( 'wp_smush_localize_ui_script_data', array( $this, 'localize_scan_stats' ) );
	}

	public function start_background_scan() {
		check_ajax_referer( 'wp_smush_media_library_scanner' );

		if ( ! Helper::is_user_allowed() ) {
			wp_send_json_error();
		}

		$status = $this->start_background_scan_direct();
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ) );
		}

		wp_send_json_success( $this->get_scan_status() );
	}

	public function start_background_scan_direct() {
		$in_processing = $this->background_process->get_status()->is_in_processing();
		if ( $in_processing ) {
			// Already in progress
			return new WP_Error( 'in_processing', __( 'Background scan is already in processing.', 'wp-smushit' ) );
		}

		$this->set_optimize_on_scan_completed( ! empty( $_REQUEST['optimize_on_scan_completed'] ) );

		if ( $this->background_process->get_status()->is_dead() ) {
			$this->scanner->reduce_slice_size_option();
		}

		$this->scanner->before_scan_library();

		$slice_size  = $this->scanner->get_slice_size();
		$query       = new Media_Item_Query();
		$slice_count = $query->get_slice_count( $slice_size );
		$tasks       = array_map( function ( $slice_number ) {
			return array( 'slice' => $slice_number );
		}, range( 1, $slice_count ) );
		$this->background_process->start( $tasks );

		return $this->background_process->get_status()->to_array();
	}

	public function cancel_background_scan() {
		check_ajax_referer( 'wp_smush_media_library_scanner' );

		if ( ! Helper::is_user_allowed() ) {
			wp_send_json_error();
		}

		if ( ! $this->background_process->get_status()->is_cancelled() ) {
			$this->background_process->cancel();
		}

		$this->set_optimize_on_scan_completed( false );

		wp_send_json_success( $this->get_scan_status() );
	}

	public function send_status() {
		check_ajax_referer( 'wp_smush_media_library_scanner' );

		if ( ! Helper::is_user_allowed() ) {
			wp_send_json_error();
		}

		$this->background_process->maybe_do_healthcheck();

		wp_send_json_success( $this->get_scan_status() );
	}

	public function background_process_completed() {
		$this->scanner->after_scan_library();

		// Cache latest scan completion status.
		update_option( self::$last_scan_completed_option_key, time() );
	}

	/**
	 * Get the last scan completed time in "time ago" format.
	 *
	 * @return string|null
	 */
	private function get_last_scan_completed_human() {
		$timestamp = $this->get_last_scan_completed();
		if ( ! $timestamp ) {
			return __( 'No scans yet', 'wp-smushit' );
		}
		$now  = isset( $_GET['smush-current-time'] ) ? (int) $_GET['smush-current-time'] : time();
		$from = (int) $timestamp;
		$diff = (int) abs( $now - $from );

		if ( $diff < 60 ) {
			return __( 'Scanned just now', 'wp-smushit' );
		}
		if ( $diff < 120 ) {
			return __( 'Scanned a min ago', 'wp-smushit' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = floor( $diff / 60 );
			/* translators: Time difference between two dates, in minutes. %s: Number of minutes. */
			return sprintf( __( 'Scanned %d mins ago', 'wp-smushit' ), $mins );
		}
		if ( $diff < 2 * HOUR_IN_SECONDS ) {
			return __( 'Scanned 1h ago', 'wp-smushit' );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hours = floor( $diff / HOUR_IN_SECONDS );
			/* translators: Time difference between two dates, in hours. %s: Number of hours. */
			return sprintf( __( 'Scanned %dh ago', 'wp-smushit' ), $hours );
		}

		if ( $diff < 2 * DAY_IN_SECONDS ) {
			return __( 'Scanned a day ago', 'wp-smushit' );
		}

		if ( $diff < WEEK_IN_SECONDS ) {
			$days = floor( $diff / DAY_IN_SECONDS );
			/* translators: Time difference between two dates, in days. %s: Number of days. */
			return sprintf( __( 'Scanned %d days ago', 'wp-smushit' ), $days );
		}
		if ( $diff < 2 * WEEK_IN_SECONDS ) {
			return __( 'Scanned last week', 'wp-smushit' );
		}
		if ( $diff < MONTH_IN_SECONDS ) {
			$weeks = floor( $diff / WEEK_IN_SECONDS );
			/* translators: Time difference between two dates, in weeks. %s: Number of weeks. */
			return sprintf( __( 'Scanned %d weeks ago', 'wp-smushit' ), $weeks );
		}
		if ( $diff < YEAR_IN_SECONDS ) {
			$months = floor( $diff / MONTH_IN_SECONDS );
			/* translators: Time difference between two dates, in months. %s: Number of months. */
			return sprintf( __( 'Scanned %d mo ago', 'wp-smushit' ), $months );
		}
		return __( 'Scanned over a year ago', 'wp-smushit' );
	}


	/**
	 * Get the last scan completion timestamp.
	 *
	 * @return mixed|null
	 */
	private function get_last_scan_completed() {
		return get_option( self::$last_scan_completed_option_key, 0 );
	}

	public function background_process_dead() {
		$this->global_stats->mark_as_outdated();
	}

	private function make_identifier() {
		$identifier = 'wp_smush_background_scan_process';
		if ( is_multisite() ) {
			$post_fix   = '_' . get_current_blog_id();
			$identifier .= $post_fix;
		}

		return $identifier;
	}

	public function localize_media_library_scan_script_data( $script_data ) {
		$scan_script_data                  = $this->background_process->get_status()->to_array();
		$scan_script_data['nonce']         = wp_create_nonce( 'wp_smush_media_library_scanner' );
		$script_data['media_library_scan'] = $scan_script_data;

		return $script_data;
	}

	/**
	 * Localize scan stats
	 *
	 * @param array $script_data Script data.
	 *
	 * @return array
	 */
	public function localize_scan_stats( $script_data ) {
		$scan_status               = $this->get_scan_data();
		$scan_status['nonce']      = wp_create_nonce( 'wp_smush_media_library_scanner' );
		$script_data['scanStatus'] = Process_Status_DTO::to_react_props( $scan_status );

		return $script_data;
	}

	private function set_optimize_on_scan_completed( $status ) {
		$this->optimize_on_scan_completed = $status;
		if ( $this->optimize_on_scan_completed ) {
			update_option( self::$optimize_on_completed_option_key, 1, false );
		} else {
			delete_option( self::$optimize_on_completed_option_key );
		}
	}

	public function enabled_optimize_on_scan_completed() {
		if ( null === $this->optimize_on_scan_completed ) {
			$this->optimize_on_scan_completed = get_option( self::$optimize_on_completed_option_key );
		}

		return ! empty( $this->optimize_on_scan_completed );
	}

	private function get_scan_status() {
		$status = $this->background_process->get_status()->to_array();

		$status['optimize_on_scan_completed'] = $this->enabled_optimize_on_scan_completed();
		$status['lastScanRun']                = $this->get_last_scan_completed_human();

		return $status;
	}

	public function get_background_process() {
		return $this->background_process;
	}

	/**
	 * Add scan progress data to frontend poll response
	 *
	 * @param array $data Polling data array.
	 *
	 * @return array Modified polling data with scan progress.
	 */
	public function add_scan_progress_to_poll( $data ) {
		$status                = $this->get_scan_data();
		$data['scan-progress'] = Process_Status_DTO::to_react_props( $status );
		return $data;
	}

	/**
	 * Get the last scan completed time in "time ago" format.
	 *
	 * @return string|null
	 */
	private function get_last_scan_date_time() {
		$timestamp = $this->get_last_scan_completed();
		if ( ! $timestamp ) {
			return '';
		}

		$now  = current_time( 'timestamp' );
		$diff = $now - $timestamp;

		// <24 hours: "00:00 AM/PM" or "Yesterday, 00:00 AM/PM"
		if ( $diff < DAY_IN_SECONDS ) {
			return date_i18n( 'g:i A', $timestamp );
		}

		// 48+ hours but less than 12 months: "Day, DD Month 00:00 AM/PM"
		if ( $diff < YEAR_IN_SECONDS ) {
			return date_i18n( 'D, j M g:i A', $timestamp );
		}

		// 12+ months: "DD Month YYYY, 00:00 AM/PM"
		return date_i18n( 'j M Y, g:i A', $timestamp );
	}

	public function get_scan_data() {
		$status                 = $this->background_process->get_status()->to_array();
		$status['lastScanRun']  = $this->get_last_scan_completed_human();
		$status['scanDateTime'] = $this->get_last_scan_date_time();

		return $status;
	}

	public function reset_background_scan_status() {
		check_ajax_referer( 'wp_smush_media_library_scanner' );

		if ( ! Helper::is_user_allowed() ) {
			wp_send_json_error();
		}

		$this->background_process->get_status()->reset();

		wp_send_json_success();
	}
}