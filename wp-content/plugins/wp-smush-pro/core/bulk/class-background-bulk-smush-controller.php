<?php

namespace Smush\Core\Bulk;

use Smush\Core\Helper;
use Smush\Core\Background\Process_Status_DTO;
use Smush\Core\Server_Utils;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Media_Library\Media_Library_Last_Process;
use Smush\Core\Media_Library\Background_Media_Library_Scanner;
use Smush\Core\Membership\Membership;
use WP_Smush;

class Background_Bulk_Smush_Controller {
	private static $required_mysql_version = '5.6';

	/**
	 * @var Bulk_Smush_Background_Process
	 */
	private $background_process;
	private $mail;
	private $logger;
	private $global_stats;
	private $server_utils;
	private $membership;

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

	public function __construct() {
		$process_manager          = new Background_Process_Manager(
			is_multisite(),
			get_current_blog_id()
		);
		$this->background_process = $process_manager->create_process();
		$this->mail               = new Mail( 'wp_smush_background' );
		$this->logger             = Helper::logger();
		$this->global_stats       = Global_Stats::get();
		$this->server_utils       = new Server_Utils();
		$this->membership         = Membership::get_instance();

		if ( ! $this->should_use_background() ) {
			return;
		}

		$this->register_ajax_handler( 'bulk_smush_start', array( $this, 'bulk_smush_start' ) );
		$this->register_ajax_handler( 'bulk_smush_cancel', array( $this, 'bulk_smush_cancel' ) );
		$this->register_ajax_handler( 'bulk_smush_pause', array( $this, 'bulk_smush_pause' ) );
		$this->register_ajax_handler( 'bulk_smush_resume', array( $this, 'bulk_smush_resume' ) );
		$this->register_ajax_handler( 'bulk_smush_get_status', array( $this, 'bulk_smush_get_status' ) );
		$this->register_ajax_handler( 'bulk_smush_reset_status', array( $this, 'bulk_smush_reset_status' ) );

		$background_scan = Background_Media_Library_Scanner::get_instance();
		$scan_identifier = $background_scan->get_background_process()->get_identifier();
		add_action( "{$scan_identifier}_completed", array( $this, 'on_scan_completed' ), 20 );

		add_filter( 'wp_smush_frontend_poll_data', array( $this, 'add_bulk_smush_progress_to_poll' ) );
		add_filter( 'wp_smush_localize_ui_script_data', array( $this, 'localize_background_stats' ), 10, 2 );
		add_action( 'init', array( $this, 'cancel_programmatically' ) );
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.2.0' );
	}

	public function get_background_process() {
		return $this->background_process;
	}

	public function cancel_programmatically() {
		$background_disabled = ! $this->is_background_enabled();
		$constant_value      = defined( 'WP_SMUSH_STOP_BACKGROUND_PROCESSING' ) && WP_SMUSH_STOP_BACKGROUND_PROCESSING;
		$filter_value        = apply_filters( 'wp_smush_stop_background_processing', false );
		$capability          = is_multisite() ? 'manage_network' : 'manage_options';
		$param_value         = ! empty( $_GET['wp_smush_stop_background_processing'] ) && current_user_can( $capability );
		$should_cancel       = $background_disabled || $constant_value || $filter_value || $param_value;
		$status              = $this->background_process->get_status();

		if ( $should_cancel && $status->is_in_processing() && ! $status->is_cancelled() ) {
			$this->logger->notice( 'Cancelling background processing because a constant/query param/filter indicated that the process needs to be stopped.' );

			$this->background_process->cancel();
		}
	}

	public function bulk_smush_start() {
		$this->check_ajax_referrer();

		if ( $this->membership->is_api_hub_access_required() ) {
			wp_send_json_error(
				array(
					'error'         => 'hub_access_required',
					'error_message' => esc_html__( 'A WPMU DEV Hub connection is required to optimize images.', 'wp-smushit' ),
				),
				403
			);
		}

		if ( $this->global_stats->is_outdated() ) {
			wp_send_json_error( array(
				'error'         => 'is_outdated',
				'error_message' => esc_html__( 'You need to run a scan before bulk Smush can be started.', 'wp-smushit' ),
			), 409 );
		}

		$process       = $this->background_process;
		$in_processing = $process->get_status()->is_in_processing();
		if ( $in_processing ) {
			// Already in progress
			wp_send_json_error();
		}

		$tasks = $this->prepare_background_tasks();
		if ( $tasks ) {
			$process->start( $tasks );

			wp_send_json_success( $process->get_status()->to_array() );
		}

		wp_send_json_error();
	}

	public function bulk_smush_cancel() {
		$this->check_ajax_referrer();

		if ( ! $this->background_process->get_status()->is_cancelled() ) {
			$this->background_process->cancel();
		}

		wp_send_json_success();
	}

	public function bulk_smush_pause() {
		$this->check_ajax_referrer();

		if ( ! $this->background_process->get_status()->is_paused() ) {
			$this->background_process->pause();
		}

		wp_send_json_success();
	}

	public function bulk_smush_resume() {
		$this->check_ajax_referrer();

		if ( $this->background_process->get_status()->is_paused() ) {
			$this->background_process->resume();
		}

		wp_send_json_success();
	}

	public function bulk_smush_get_status() {
		$this->check_ajax_referrer();

		$is_process_stuck = Media_Library_Last_Process::get_instance()->is_process_stuck();

		wp_send_json_success(
			array_merge(
				$this->background_process->get_status()->to_array(),
				array(
					'is_process_stuck'  => $is_process_stuck,
					'in_process_notice' => $this->get_in_process_notice(),
				)
			)
		);
	}

	private function check_ajax_referrer() {
		check_ajax_referer( 'wp-smush-ajax', '_nonce' );
		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}
	}

	private function register_ajax_handler( $action, $handler ) {
		add_action( "wp_ajax_$action", $handler );
	}

	/**
	 * @return Smush_Background_Task[]
	 */
	private function prepare_background_tasks() {
		$smush_tasks   = $this->prepare_smush_tasks();
		$resmush_tasks = $this->prepare_resmush_tasks();

		return array_merge(
			$smush_tasks,
			$resmush_tasks
		);
	}

	private function prepare_smush_tasks() {
		$to_smush = $this->global_stats->get_optimize_list()->get_ids();
		if ( empty( $to_smush ) || ! is_array( $to_smush ) ) {
			$to_smush = array();
		}

		return array_map(
			function ( $image_id ) {
				return new Smush_Background_Task(
					Smush_Background_Task::get_task_type_smush(),
					$image_id
				);
			},
			$to_smush
		);
	}

	private function prepare_resmush_tasks() {
		$to_resmush = $this->global_stats->get_redo_ids();

		return array_map(
			function ( $image_id ) {
				return new Smush_Background_Task(
					Smush_Background_Task::get_task_type_resmush(),
					$image_id
				);
			},
			$to_resmush
		);
	}

	private function prepare_error_tasks() {
		$error_items_to_retry = $this->global_stats->get_error_list()->get_ids();

		return array_map(
			function ( $image_id ) {
				return new Smush_Background_Task(
					Smush_Background_Task::get_task_type_error(),
					$image_id
				);
			},
			$error_items_to_retry
		);
	}

	public function localize_background_stats( $script_data, $page_slug ) {
		$script_data['bulkSmushStatus'] = $this->get_process_data_for_ui();

		return $script_data;
	}

	/**
	 * Whether BO is in processing or not.
	 *
	 * @return boolean
	 */
	public function is_in_processing() {
		return $this->background_process->get_status()->is_in_processing();
	}

	/**
	 * Whether BO is completed or not.
	 *
	 * @return boolean
	 */
	public function is_completed() {
		return $this->background_process->get_status()->is_completed();
	}

	/**
	 * Whether BO is dead or not.
	 *
	 * @return boolean
	 */
	public function is_dead() {
		return $this->background_process->get_status()->is_dead();
	}

	/**
	 * Get total items.
	 *
	 * @return int
	 */
	public function get_total_items() {
		return $this->background_process->get_status()->get_total_items();
	}

	/**
	 * Get processed items.
	 *
	 * @return int
	 */
	public function get_processed_items() {
		return $this->background_process->get_status()->get_processed_items();
	}

	/**
	 * Get failed items.
	 *
	 * @return int
	 */
	public function get_failed_items() {
		return $this->background_process->get_status()->get_failed_items();
	}

	/**
	 * Get revival count.
	 *
	 * @return int
	 */
	public function get_revival_count() {
		return $this->background_process->get_revival_count();
	}

	/**
	 * Get process id.
	 */
	public function get_process_id() {
		return $this->background_process->get_process_id();
	}

	/**
	 * Get email address of recipient.
	 *
	 * @return string
	 */
	public function get_mail_recipient() {
		$emails = $this->mail->get_mail_recipients();

		return ! empty( $emails ) ? $emails[0] : get_option( 'admin_email' );
	}

	public function get_in_process_notice() {
		return $this->mail->reporting_email_enabled()
			? $this->get_email_enabled_notice()
			: $this->get_email_disabled_notice();
	}

	private function get_email_disabled_notice() {
		$email_setting_link = sprintf(
			'<a href="#background_email-settings-row">%s</a>',
			esc_html__( 'Enable the email notification', 'wp-smushit' )
		);

		/* translators: %s: a link */
		return sprintf( __( 'Feel free to close this page while Smush works its magic in the background. %s to receive an email when the process finishes.', 'wp-smushit' ), $email_setting_link );
	}

	private function get_email_enabled_notice() {
		$mail_recipient = $this->get_mail_recipient();
		/* translators: %s: Email address */
		return sprintf( __( 'Feel free to close this page while Smush works its magic in the background. We’ll email you at <strong>%s</strong> when it’s done.', 'wp-smushit' ), $mail_recipient );
	}

	public function is_background_enabled() {
		if ( ! $this->can_use_background() ) {
			return false;
		}

		return defined( 'WP_SMUSH_BACKGROUND' ) && WP_SMUSH_BACKGROUND;
	}

	public function should_use_background() {
		// TODO: [WPMUDEV SMUSH UI] Always enabled on the new UI.
		// Check if we should continue support ajax for background issue cases.
		return true;

		return $this->is_background_enabled()
		       && $this->is_background_supported();
	}

	public function is_background_supported() {
		return $this->is_mysql_requirement_met();
	}

	public function can_use_background() {
		return true;
	}

	/**
	 * We need the right version of MySQL for locks used by the Mutex class
	 *
	 * @return bool|int
	 */
	private function is_mysql_requirement_met() {
		return version_compare( $this->get_actual_mysql_version(), $this->get_required_mysql_version(), '>=' );
	}

	public function get_required_mysql_version() {
		return self::$required_mysql_version;
	}

	public function get_actual_mysql_version() {
		return $this->server_utils->get_mysql_version();
	}

	public function start_bulk_smush_direct() {
		if ( ! $this->should_use_background() ) {
			return false;
		}

		if ( $this->membership->is_api_hub_access_required() ) {
			return false;
		}

		$process       = $this->background_process;
		$in_processing = $process->get_status()->is_in_processing();
		if ( $in_processing ) {
			return $process->get_status()->to_array();
		}

		if ( ! Helper::loopback_supported() ) {
			$this->logger->error( 'Loopback check failed. Not starting a new background process.' );

			return false;
		}

		$tasks = $this->prepare_background_tasks();
		if ( $tasks ) {
			$process->start( $tasks );
		}

		return $process->get_status()->to_array();
	}

	/**
	 * Add bulk smush progress data to frontend poll response
	 *
	 * @param array $data Polling data array.
	 *
	 * @return array Modified polling data with bulk smush progress.
	 */
	public function add_bulk_smush_progress_to_poll( $data ) {
		$data['bulk-smush-progress'] = $this->get_process_data_for_ui();

		if ( $this->background_process->get_status()->is_in_processing() ) {
			$this->background_process->maybe_do_healthcheck();
		}

		return $data;
	}

	private function get_process_data_for_ui() {
		$status = $this->background_process->get_status()->to_array();
		return Process_Status_DTO::to_react_props( $status );
	}

	public function on_scan_completed() {
		if ( $this->should_use_background() && Background_Media_Library_Scanner::get_instance()->enabled_optimize_on_scan_completed() ) {
			$this->start_bulk_smush_direct();
		}
	}

	public function bulk_smush_reset_status() {
		$this->check_ajax_referrer();

		$this->background_process->get_status()->reset();

		wp_send_json_success();
	}
}