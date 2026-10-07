<?php

namespace Smush\Core\Background;

use Smush\Core\Server_Utils;

/**
 * Abstract WP_Background_Process class.
 *
 * @abstract
 * @extends Async_Request
 */
abstract class Background_Process extends Async_Request {
	private static $tasks_per_request_unlimited = PHP_INT_MAX;

	/**
	 * Start time of current process.
	 *
	 * (default value: 0)
	 *
	 * @var int
	 * @access protected
	 */
	private $start_time = 0;

	/**
	 * Cron_hook_identifier
	 *
	 * @var mixed
	 * @access protected
	 */
	private $cron_hook_identifier;

	/**
	 * Cron_interval_identifier
	 *
	 * @var mixed
	 * @access protected
	 */
	private $cron_interval_identifier;

	/**
	 * @var Background_Logger_Container
	 */
	private $logger_container;

	/**
	 * @var Background_Process_Status
	 */
	private $status;

	private $tasks_per_request = null;
	private $server_utils;

	/**
	 * @var Background_Queue
	 */
	private $queue;

	/**
	 * Initiate new background process
	 */
	public function __construct( $identifier ) {
		parent::__construct( $identifier );

		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_healthcheck' ) );
		add_action( 'init', function () {
			add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ) );
		} );

		$this->logger_container = new Background_Logger_Container( $this->identifier );
		$this->status           = new Background_Process_Status( $this->identifier );
		$this->server_utils     = new Server_Utils();
		$this->queue            = new Background_Queue( $this->get_queue_key() );
	}

	private function generate_unique_id() {
		return md5( microtime() . rand() );
	}

	/**
	 * Dispatch
	 *
	 * @access public
	 * @return array|\WP_Error
	 */
	public function dispatch( $instance_id ) {
		$this->logger()->info( "Dispatching a new request for instance $instance_id." );

		// Schedule cron healthcheck as fallback for when the browser tab is closed.
		$this->schedule_event();

		// Perform remote post.
		return parent::dispatch( $instance_id );
	}

	public function spawn() {
		$instance_id = $this->generate_unique_id();

		$this->logger()->info( "Spawning a brand new instance (ID: $instance_id) for the process." );

		$this->set_active_instance_id( $instance_id );
		$this->dispatch( $instance_id );
	}

	/**
	 * Generate key
	 *
	 * Generates a unique key based on microtime. Queue items are
	 * given a unique key so that they can be merged upon save.
	 *
	 * @return string
	 */
	protected function get_queue_key() {
		return $this->identifier . '_queue';
	}

	/**
	 * Maybe process queue
	 *
	 * Checks whether data exists within the queue and that
	 * the process is not already running.
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing
		session_write_close();
		$instance_id = empty( $_GET['instance_id'] )
			? false
			: wp_unslash( $_GET['instance_id'] );

		if ( ! $instance_id ) {
			$this->logger()->warning( 'Handler called without instance ID. Killing this instance.' );

			return;
		}

		if ( $this->queue->is_empty() ) {
			$this->logger()->warning( "Handler called with instance ID $instance_id but the queue is empty. Killing this instance." );

			return;
		}

		if ( ! $this->is_active_instance( $instance_id ) ) {
			// We thought the process died, so we spawned a new instance.
			// Kill this instance and let the new one continue.
			$active_instance_id = $this->get_active_instance_id();
			$this->logger()->warning( "Handler called with instance ID $instance_id but the active instance ID is $active_instance_id. Killing $instance_id so $active_instance_id can continue." );

			return;
		}

		if ( ! check_ajax_referer( $this->identifier, 'nonce', false ) ) {
			return;
		}

		$this->handle( $instance_id );

		wp_die();
	}

	/**
	 * Is process running
	 *
	 * Check whether the current process is already running
	 * in a background process.
	 */
	protected function is_process_running() {
		if ( get_site_transient( $this->get_last_run_transient_key() ) ) {
			// Process already running.
			return true;
		}

		return false;
	}

	protected function update_timestamp( $instance_id ) {
		$timestamp        = time();
		$this->start_time = $timestamp; // Set start time of current process.
		set_site_transient(
			$this->get_last_run_transient_key(),
			$timestamp,
			$this->get_instance_expiry_duration_seconds()
		);

		$human_readable_timestamp = wp_date( 'Y-m-d H:i:s', $timestamp );
		$this->logger()->info( "Setting last run timestamp for instance ID $instance_id to $human_readable_timestamp" );
	}

	/**
	 * Handle
	 *
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 */
	protected function handle( $instance_id ) {
		$this->logger()->info( "Task handler: Handling instance ID $instance_id." );
		$this->update_timestamp( $instance_id );

		$queue                 = $this->queue;
		$processed_tasks_count = 0;
		$complete              = false;

		for ( $i = 0; $i < $this->get_tasks_per_request(); ++ $i ) {
			$value = $queue->get_next_task();
			if ( is_null( $value ) ) {
				// Null indicates that the queue doesn't have any more items left.
				$complete = true;
				break;
			}

			if ( $value === false ) {
				// Data was deleted from under us. The process may have been marked as dead by the health check.
				return;
			}

			if ( $this->status->is_paused() ) {
				$this->logger()->info( 'Task handler: Process paused during task loop. Exiting handler.' );
				return;
			} elseif ( $this->status->is_cancelled() ) {
				return;
			}

			$value_string = var_export( $value, true );
			$this->logger()->info( "Task handler: Executing task $value_string." );
			$task = $this->task( $value );
			if ( $task ) {
				$this->status->task_successful();
			} else {
				$this->status->task_failed();
			}

			if ( $this->status->is_cancelled() ) {
				$this->logger()->info( "Task handler: While we were busy doing the task $value_string, the process got cancelled. Clean up and stop." );

				break;
			}

			$processed_tasks_count ++;
			if ( $this->task_limit_reached( $processed_tasks_count ) ) {
				$tasks_per_request = $this->get_tasks_per_request();
				$this->logger()->info( "Task handler: Stopping because we are only supposed to perform $tasks_per_request tasks in a single request and we have reached that limit." );

				break;
			}

			if ( $this->time_exceeded() || $this->memory_exceeded() ) {
				$this->logger()->warning( "Task handler: Time/Memory limits reached, save the queue and dispatch a new request." );
				break;
			}
		}

		if ( $this->queue->get_remaining_count() === 0 ) {
			$complete = true;
		}

		$this->logger()->info( sprintf( 'Task handler: Processing time: %d seconds', time() - $this->start_time ) );

		if ( $complete ) {
			$this->complete();
		} else {
			$this->dispatch( $instance_id );
		}
	}

	/**
	 * Memory exceeded
	 *
	 * Ensures the process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @return bool
	 */
	protected function memory_exceeded() {
		$memory_limit   = $this->server_utils->get_memory_limit() * 0.75; // 75% of max memory
		$current_memory = $this->server_utils->get_memory_usage();
		$return         = false;

		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_memory_exceeded', $return );
	}

	/**
	 * Time exceeded.
	 *
	 * Ensures the process never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @return bool
	 */
	protected function time_exceeded() {
		$finish = $this->start_time + $this->get_time_limit();
		$return = false;

		if ( time() >= $finish ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_time_exceeded', $return );
	}

	/**
	 * Complete.
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		$status_updated = $this->status->complete();
		if ( ! $status_updated ) {
			// Avoid parallel requests from doing completion multiple times
			return;
		}

		$this->do_action( 'completed' );
		$this->logger()->info( "Process completed." );
		$this->cleanup();
	}

	/**
	 * Schedule cron healthcheck — registers the custom interval.
	 */
	public function schedule_cron_healthcheck( $schedules ) {
		$interval = $this->get_cron_interval_seconds();

		$schedules[ $this->identifier . '_cron_interval' ] = array(
			'interval' => $interval,
			/* translators: %s: Cron interval in minutes */
			'display'  => sprintf( __( 'Every %d Minutes', 'wp-smushit' ), $interval / MINUTE_IN_SECONDS ),
		);

		return $schedules;
	}

	/**
	 * Handle cron healthcheck (legacy hook — delegates to maybe_do_healthcheck).
	 * Kept so any residual scheduled cron events still fire correctly until they expire.
	 */
	public function handle_cron_healthcheck() {
		$this->maybe_do_healthcheck();
		exit;
	}

	/**
	 * Maybe do healthcheck.
	 *
	 * Restarts the background process if it has stalled.
	 * Rate-limited to once per 2 minutes via a transient so rapid ajax polls
	 * do not trigger multiple concurrent revival attempts.
	 */
	public function maybe_do_healthcheck() {
		$mutex = new Mutex( $this->identifier . '_cron_healthcheck' );
		$mutex->set_break_on_timeout( true )
		      ->set_timeout( 1 ) // We don't want two health checks running
		      ->execute(
				function () {
					if ( $this->healthcheck_recently_ran() ) {
						$this->logger()->info( 'Health check: skipped — ran within the last 2 minutes.' );
						return;
					}
					$this->mark_healthcheck_as_ran();

					$this->logger()->info( 'Running health check.' );

					if ( $this->is_process_running() ) {
						$this->logger()->info( 'Health check: Process seems healthy, no action required.' );
						return;
					}

					if ( $this->status->is_paused() ) {
						$this->logger()->info( 'Health check: Process is paused, no action required.' );
						return;
					}

					if ( $this->queue->is_empty() ) {
						$this->logger()->info( 'Health check: Process not in progress but the queue is empty, no action required.' );
						return;
					}

					if ( $this->status->is_cancelled() ) {
						$this->logger()->info( 'Health check: Process has been cancelled already, no action required.' );
						return;
					}

					if ( ! $this->is_revival_limit_reached() ) {
						$this->logger()->warning( 'Health check: Process instance seems to have died. Spawn a new instance.' );
						$this->revive_process();
					} else {
						$this->logger()->warning( 'Health check: Process instance seems to have died. Restart disabled, marking the process as dead.' );
						$this->mark_as_dead();
					}
				}
			);
	}

	private function revive_process() {
		$this->do_action( 'revived' );
		$this->increment_revival_count();
		$this->spawn();
	}

	protected function mark_as_dead() {
		$status_updated = $this->status->mark_as_dead();
		if ( ! $status_updated ) {
			// Avoid parallel requests from doing cleanup multiple times
			return;
		}

		$this->do_action( 'dead' );
		$this->cleanup();
	}

	/**
	 * Schedule event
	 */
	protected function schedule_event() {
		$hook = $this->cron_hook_identifier;
		if ( ! wp_next_scheduled( $hook ) ) {
			$interval = $this->cron_interval_identifier;
			$next_run = time() + $this->get_cron_interval_seconds();
			wp_schedule_event( $next_run, $interval, $hook );

			$this->logger()->info( "Scheduling new event with hook $hook to run $interval." );
		}
	}

	/**
	 * Clear scheduled event
	 */
	protected function clear_scheduled_event() {
		$hook = $this->cron_hook_identifier;
		$this->logger()->info( "Cancelling event with hook $hook." );
		wp_clear_scheduled_hook( $hook );
	}

	public function cancel() {
		// Update the cancel flag first
		$active_instance_id = $this->get_active_instance_id();
		$this->logger()->info( "Starting cancellation (Instance: $active_instance_id)." );
		$this->status->cancel();

		// Do this before cleanup, so we still have data available to us
		$this->do_action( 'cancelled' );

		$this->logger()->info( "Cancelling the process (Instance: $active_instance_id)." );
		$this->cleanup();
		$this->logger()->info( "Process cancelled." );
		$this->logger()->info( "Cancellation completed (Instance: $active_instance_id)." );
	}

	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $task Queue item to iterate over.
	 *
	 * @return mixed
	 */
	abstract protected function task( $task );

	private function is_active_instance( $instance_id ) {
		return $instance_id === $this->get_active_instance_id();
	}

	/**
	 * Save the unique ID of the process we are presuming to be dead, so we can prevent it from coming back.
	 *
	 * @param $instance_id
	 *
	 * @return void
	 */
	private function set_active_instance_id( $instance_id ) {
		update_site_option( $this->get_active_instance_option_id(), $instance_id );
	}

	private function get_active_instance_id() {
		return get_site_option( $this->get_active_instance_option_id(), '' );
	}

	private function get_active_instance_option_id() {
		return $this->identifier . '_active_instance';
	}

	private function set_process_id( $instance_id ) {
		update_site_option( $this->get_process_id_option_key(), $instance_id );
	}

	public function get_process_id() {
		return get_site_option( $this->get_process_id_option_key() );
	}

	private function delete_process_id() {
		delete_site_option( $this->get_process_id_option_key() );
	}

	private function get_process_id_option_key() {
		return $this->identifier . '_process_id';
	}

	public function set_logger( $logger ) {
		$this->logger_container->set_logger( $logger );
	}

	/**
	 * @return Background_Logger_Container
	 */
	private function logger() {
		return $this->logger_container;
	}

	public function get_status() {
		return $this->status;
	}

	/**
	 * @param $tasks array
	 *
	 * @return void
	 */
	public function start( $tasks ) {
		$this->do_action( 'before_start' );

		$total_items    = count( $tasks );
		$status_updated = $this->status->start( $total_items );
		if ( ! $status_updated ) {
			// Prevent parallel requests from spawning two instances
			return;
		}

		$this->queue->populate( $tasks );
		// Generate ID for the whole process.
		$this->set_process_id( $this->generate_unique_id() );

		$this->logger()->info( "Starting new process with $total_items tasks" );

		// Trigger the started event before dispatching the request to ensure it is called before the completed event.
		$this->do_action( 'started' );

		$this->logger()->info( "Marking health check as ran at start." );
		$this->mark_healthcheck_as_ran();

		$this->spawn();
	}

	private function get_time_limit() {
		return apply_filters( $this->identifier . '_default_time_limit', 20 ); // 20 seconds
	}

	protected function get_instance_expiry_duration_seconds() {
		return MINUTE_IN_SECONDS * 2;
	}

	private function get_last_run_transient_key() {
		return $this->identifier . '_last_run';
	}

	private function clear_last_run_timestamp() {
		delete_site_transient( $this->get_last_run_transient_key() );
	}

	private function get_healthcheck_transient_key() {
		return $this->identifier . '_healthcheck_ran';
	}

	private function healthcheck_recently_ran() {
		return (bool) get_site_transient( $this->get_healthcheck_transient_key() );
	}

	private function mark_healthcheck_as_ran() {
		set_site_transient( $this->get_healthcheck_transient_key(), 1, 2 * MINUTE_IN_SECONDS );
	}

	private function cleanup() {
		// Delete options and transients
		$this->queue->cleanup();
		delete_site_option( $this->get_active_instance_option_id() );
		$this->delete_process_id();
		$this->delete_revival_count();
		$this->clear_last_run_timestamp();

		// Cancel all events
		$this->clear_scheduled_event();
	}

	private function task_limit_reached( $processed_tasks_count ) {
		if ( $this->get_tasks_per_request() === self::$tasks_per_request_unlimited ) {
			return false;
		}

		return $processed_tasks_count >= $this->get_tasks_per_request();
	}

	public function get_tasks_per_request() {
		return $this->tasks_per_request ?? self::$tasks_per_request_unlimited;
	}

	/**
	 * @param int $tasks_per_request
	 */
	public function set_tasks_per_request( $tasks_per_request ) {
		$this->tasks_per_request = $tasks_per_request;
	}

	private function do_action( $action ) {
		do_action( $this->action_name( $action ), $this->identifier, $this );
	}

	private function get_cron_interval_seconds() {
		$minutes = property_exists( $this, 'cron_interval' )
			? $this->cron_interval
			: 5;

		$interval = apply_filters( $this->identifier . '_cron_interval', $minutes );

		return $interval * MINUTE_IN_SECONDS;
	}

	public function get_identifier() {
		return $this->identifier;
	}

	private function increment_revival_count() {
		$revival_count = $this->get_revival_count();
		$this->set_revival_count( $revival_count + 1 );
	}

	private function set_revival_count( $instance_id ) {
		update_site_option( $this->get_revival_count_option_key(), $instance_id );
	}

	public function get_revival_count() {
		return (int) get_site_option( $this->get_revival_count_option_key(), 0 );
	}

	private function delete_revival_count() {
		delete_site_option( $this->get_revival_count_option_key() );
	}

	private function get_revival_count_option_key() {
		return $this->identifier . '_revival_count';
	}

	protected function get_revival_limit() {
		return apply_filters( $this->identifier . '_revival_limit', 5 );
	}

	protected function is_revival_limit_reached() {
		return $this->get_revival_count() >= $this->get_revival_limit();
	}

	/**
	 * @param $action
	 *
	 * @return string
	 */
	public function action_name( $action ) {
		return "{$this->identifier}_$action";
	}

	public function pause() {
		$this->status->pause();
		$this->do_action( 'paused' );
		$active_instance_id = $this->get_active_instance_id();
		$this->logger()->info( sprintf( 'Process paused (Instance: %d).', $active_instance_id ) );
	}

	/**
	 * Resume the process
	 */
	public function resume() {
		$this->status->resume();
		$active_instance_id = $this->get_active_instance_id();
		$this->logger()->info( sprintf( 'Process resumed (Instance: %d).', $active_instance_id ) );
		$this->do_action( 'resumed' );
		$this->spawn();
	}
}