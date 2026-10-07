<?php

namespace Smush\Core\Bulk;

use Smush\Core\Background\Background_Process;
use Smush\Core\Helper;

class Bulk_Smush_Background_Process extends Background_Process {
	/**
	 * Retrival limit per 1000.
	 *
	 * @var int
	 */
	private static $revival_limit_unit = 5;

	/**
	 * @var Bulk_Optimize
	 */
	private $bulk_optimize;

	public function __construct( $identifier ) {
		parent::__construct( $identifier );

		$this->set_logger( Helper::logger() );
		$this->bulk_optimize = new Bulk_Optimize();
	}

	public function start( $tasks ) {
		parent::start( $tasks );

		$this->bulk_optimize->start_bulk_optimization();
	}

	/**
	 * @param $task Smush_Background_Task
	 *
	 * @return boolean
	 */
	protected function task( $task ) {
		if ( is_array( $task ) ) {
			$task = Smush_Background_Task::from_json( $task );
		}

		if ( ! is_a( $task, Smush_Background_Task::class ) || ! $task->is_valid() ) {
			Helper::logger()->error( 'An invalid background task was encountered.' );

			return false;
		}

		$result = $this->bulk_optimize->optimize_attachment( $task->get_image_id() );
		return ! is_wp_error( $result );
	}

	/**
	 * Email when bulk smush complete.
	 */
	protected function complete() {
		parent::complete();
		// Send email.
		if ( $this->get_status()->get_total_items() ) {
			$mail = new Mail( 'wp_smush_background' );
			if ( $mail->reporting_email_enabled() ) {
				if ( $mail->send_email() ) {
					Helper::logger()->notice(
						sprintf(
							'Bulk Smush completed for %s, and sent a summary email to %s at %s.',
							get_site_url(),
							join( ',', $mail->get_mail_recipients() ),
							wp_date( 'd/m/y H:i:s' )
						)
					);
				} else {
					Helper::logger()->error(
						sprintf(
							'Bulk Smush completed for %s, but could not send a summary email to %s at %s.',
							get_site_url(),
							join( ',', $mail->get_mail_recipients() ),
							wp_date( 'd/m/y H:i:s' )
						)
					);
				}
			} else {
				Helper::logger()->info( sprintf( 'Bulk Smush completed for %s, and reporting email is disabled.', get_site_url() ) );
			}
		}

		$this->bulk_optimize->complete_bulk_optimization();
	}

	protected function get_revival_limit() {
		$constant_value = $this->get_revival_limit_constant();
		if ( $constant_value ) {
			return $constant_value;
		}

		$revival_limit = $this->calculate_default_revival_limit();

		return apply_filters( $this->identifier . '_revival_limit', $revival_limit );
	}

	private function get_revival_limit_constant() {
		if ( ! defined( 'WP_SMUSH_BULK_REVIVAL_LIMIT' ) ) {
			return 0;
		}

		$constant_value = (int) WP_SMUSH_BULK_REVIVAL_LIMIT;

		return max( $constant_value, 0 );
	}

	private function calculate_default_revival_limit() {
		$total_items           = $this->get_status()->get_total_items();
		$default_revival_limit = (int) ceil( $total_items / 1000 ) * self::$revival_limit_unit;

		return max( $default_revival_limit, 5 );
	}

	protected function mark_as_dead() {
		do_action( 'wp_smush_bulk_smush_dead', $this );

		return parent::mark_as_dead();
	}

	protected function get_instance_expiry_duration_seconds() {
		$expire_duration = 0;
		if ( defined( 'WP_SMUSH_BULK_SMUSH_EXPIRE_DURATION' ) ) {
			$expire_duration = (int) WP_SMUSH_BULK_SMUSH_EXPIRE_DURATION;
		}

		return $expire_duration > 0 ? $expire_duration : MINUTE_IN_SECONDS * 3;
	}
}