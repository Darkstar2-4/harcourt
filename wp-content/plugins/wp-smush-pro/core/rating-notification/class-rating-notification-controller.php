<?php

namespace Smush\Core\Rating_Notification;

use Smush\Core\Controller;
use Smush\Core\Helper;
use Smush\Core\Server_Utils;

class Rating_Notification_Controller extends Controller {
	public function __construct() {
		$this->register_action( 'wp_ajax_smush_rating_completed', array( $this, 'handle_rating_completed' ) );
		$this->register_action( 'wp_ajax_smush_rating_remind_later', array( $this, 'handle_rating_remind_later' ) );
		$this->register_action( 'wp_ajax_smush_rating_dismissed', array( $this, 'handle_rating_dismissed' ) );
		$this->register_action( 'wp_ajax_smush_rating_first_completion', array( $this, 'handle_rating_first_completion' ) );
		$this->register_action( 'wp_smush_localize_ui_script_data', array( $this, 'localize_ui_script_data' ) );
	}

	/**
	 * Handle rating notification - user rated the plugin.
	 *
	 * @since 3.17.0
	 */
	public function handle_rating_completed() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		// Store all rating statuses in a single option as an array.
		$rating_status              = get_option( 'wp-smush-rating-status', array() );
		$rating_status['completed'] = true;
		update_option( 'wp-smush-rating-status', $rating_status );
		wp_send_json_success();
	}

	/**
	 * Handle rating notification - user wants to be reminded later.
	 *
	 * @since 3.17.0
	 */
	public function handle_rating_remind_later() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		$rating_status                 = get_option( 'wp-smush-rating-status', array() );
		$rating_status['remind_later'] = time();
		update_option( 'wp-smush-rating-status', $rating_status );
		wp_send_json_success();
	}

	/**
	 * Handle rating notification - user dismissed permanently.
	 *
	 * @since 3.17.0
	 */
	public function handle_rating_dismissed() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		$rating_status              = get_option( 'wp-smush-rating-status', array() );
		$rating_status['dismissed'] = true;
		update_option( 'wp-smush-rating-status', $rating_status );
		wp_send_json_success();
	}

	/**
	 * Handle rating notification - mark first completion.
	 *
	 * @since 3.17.0
	 */
	public function handle_rating_first_completion() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		// Only set if not already set.
		$rating_status = get_option( 'wp-smush-rating-status', array() );
		if ( empty( $rating_status['first_completion'] ) ) {
			$rating_status['first_completion'] = time();
			update_option( 'wp-smush-rating-status', $rating_status );
		}

		wp_send_json_success();
	}

	public function localize_ui_script_data( $script_data ) {
		$script_data['ratingNotification'] = $this->get_rating_notification_data();

		return $script_data;
	}

	/**
	 * Get rating notification data for React component.
	 *
	 * @return array Rating notification state.
	 * @since 3.17.0
	 */
	private function get_rating_notification_data() {
		$rating_status    = get_option( 'wp-smush-rating-status', array() );
		$server_utils     = new Server_Utils();
		$dismissed        = isset( $rating_status['dismissed'] ) ? $rating_status['dismissed'] : false;
		$completed        = isset( $rating_status['completed'] ) ? $rating_status['completed'] : false;
		$remind_later     = isset( $rating_status['remind_later'] ) ? $rating_status['remind_later'] : 0;
		$first_completion = isset( $rating_status['first_completion'] ) ? $rating_status['first_completion'] : 0;

		// Check if 7 days have passed since "remind later" was clicked.
		$should_show_after_reminder = false;
		if ( $remind_later > 0 ) {
			$seven_days_in_seconds      = 7 * 24 * 60 * 60;
			$time_since_reminder        = time() - $remind_later;
			$should_show_after_reminder = $time_since_reminder >= $seven_days_in_seconds;
		}

		// Check if 60 seconds have passed since first completion to show notification.
		$should_show_notification = false;
		$remaining_seconds        = 0;
		if ( $first_completion > 0 && ! $completed && ! $dismissed ) {
			$sixty_seconds         = 60;
			$time_since_completion = time() - $first_completion;
			// Show if 60+ seconds have passed and either no reminder or 7 days passed.
			if ( $time_since_completion >= $sixty_seconds ) {
				$should_show_notification = ( $remind_later === 0 ) || $should_show_after_reminder;
			} else {
				// Calculate remaining seconds until notification should show.
				$remaining_seconds = $sixty_seconds - $time_since_completion;
			}
		}

		return array(
			'dismissed'               => (bool) $dismissed,
			'completed'               => (bool) $completed,
			'remindLater'             => (int) $remind_later,
			'firstCompletion'         => (int) $first_completion,
			'shouldShowAfterReminder' => $should_show_after_reminder,
			'shouldShowNotification'  => $should_show_notification,
			'remainingSeconds'        => (int) $remaining_seconds,
			'isCurlMultiExecAvailable' => $server_utils->curl_multi_exec_available(),
		);
	}
}