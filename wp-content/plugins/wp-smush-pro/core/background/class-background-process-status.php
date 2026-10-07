<?php

namespace Smush\Core\Background;

use Smush\Core\Threads\JSON_Record;

class Background_Process_Status {
	private static $processing = 'in_processing';
	private static $cancelled = 'is_cancelled';
	private static $completed = 'is_completed';
	private static $dead = 'is_dead';
	private static $paused = 'is_paused';
	private static $total_items = 'total_items';
	private static $processed_items = 'processed_items';
	private static $failed_items = 'failed_items';

	private $identifier;

	/**
	 * @var JSON_Record
	 */
	private $record;

	public function __construct( $identifier ) {
		$this->identifier = $identifier;
		$this->record     = new JSON_Record( $this->get_option_id() );
	}

	public function get_data() {
		$option_value = $this->record->get( array() );

		return wp_parse_args(
			$option_value,
			array(
				self::$processing      => false,
				self::$cancelled       => false,
				self::$completed       => false,
				self::$paused          => false,
				self::$total_items     => 0,
				self::$processed_items => 0,
				self::$failed_items    => 0,
			)
		);
	}

	public function to_array() {
		return $this->get_data();
	}

	private function set_status_values( $values ) {
		return $this->record->set_values( $values );
	}

	private function get_value( $key ) {
		return $this->record->get_value( $key );
	}

	private function set_value( $key, $value ) {
		$this->set_status_values( array( $key => $value ) );
	}

	private function get_option_id() {
		return $this->identifier . '_status_json';
	}

	public function is_in_processing() {
		return $this->get_value( self::$processing );
	}

	public function set_in_processing( $in_processing ) {
		$this->set_value( self::$processing, $in_processing );
	}

	public function get_total_items() {
		return $this->get_value( self::$total_items );
	}

	public function set_total_items( $total_items ) {
		$this->set_value( self::$total_items, $total_items );
	}

	public function get_processed_items() {
		return $this->get_value( self::$processed_items );
	}

	public function set_processed_items( $processed_items ) {
		$this->set_value( self::$processed_items, $processed_items );
	}

	public function get_failed_items() {
		return $this->get_value( self::$failed_items );
	}

	public function set_failed_items( $failed_items ) {
		// BUG FIX: was incorrectly writing to self::$processed_items.
		$this->set_value( self::$failed_items, $failed_items );
	}

	public function is_cancelled() {
		return $this->get_value( self::$cancelled );
	}

	public function set_is_cancelled( $is_cancelled ) {
		$this->set_value( self::$cancelled, $is_cancelled );
	}

	public function is_dead() {
		return $this->get_value( self::$dead );
	}

	public function is_completed() {
		return $this->get_value( self::$completed );
	}

	public function set_is_completed( $is_completed ) {
		$this->set_value( self::$completed, $is_completed );
	}

	public function is_paused() {
		return $this->get_value( self::$paused );
	}

	public function set_is_paused( $is_paused ) {
		$this->set_value( self::$paused, $is_paused );
	}

	/**
	 * @return int|false  Rows affected (non-zero = state changed, 0 = already in this state).
	 */
	public function start( $total_items ) {
		return $this->set_status_values( array(
			self::$processing      => true,
			self::$cancelled       => false,
			self::$dead            => false,
			self::$completed       => false,
			self::$paused          => false,
			self::$total_items     => $total_items,
			self::$processed_items => 0,
			self::$failed_items    => 0,
		) );
	}

	/**
	 * @return int|false  Rows affected (non-zero = state changed, 0 = already in this state).
	 */
	public function complete() {
		return $this->set_status_values( array(
			self::$processing => false,
			self::$cancelled  => false,
			self::$dead       => false,
			self::$completed  => true,
			self::$paused     => false,
		) );
	}

	/**
	 * @return int|false  Rows affected (non-zero = state changed, 0 = already in this state).
	 */
	public function cancel() {
		return $this->set_status_values( array(
			self::$processing => false,
			self::$cancelled  => true,
			self::$dead       => false,
			self::$completed  => false,
			self::$paused     => false,
		) );
	}

	/**
	 * @return int|false  Rows affected (non-zero = state changed, 0 = already in this state).
	 */
	public function pause() {
		return $this->set_status_values( array(
			self::$processing => false,
			self::$cancelled  => false,
			self::$dead       => false,
			self::$completed  => false,
			self::$paused     => true,
		) );
	}

	/**
	 * @return int|false  Rows affected (non-zero = state changed, 0 = already in this state).
	 */
	public function resume() {
		return $this->set_status_values( array(
			self::$processing => true,
			self::$cancelled  => false,
			self::$dead       => false,
			self::$completed  => false,
			self::$paused     => false,
		) );
	}

	/**
	 * @return int|false  Rows affected (non-zero = state changed, 0 = already in this state).
	 */
	public function mark_as_dead() {
		return $this->set_status_values( array(
			self::$processing => false,
			self::$cancelled  => false,
			self::$dead       => true,
			self::$completed  => false,
			self::$paused     => true,
		) );
	}

	public function reset() {
		return $this->set_status_values( array(
			self::$processing      => false,
			self::$cancelled       => false,
			self::$dead            => false,
			self::$completed       => false,
			self::$paused          => false,
			self::$total_items     => 0,
			self::$processed_items => 0,
			self::$failed_items    => 0,
		) );
	}

	public function task_successful() {
		$this->record->increment_values( array( self::$processed_items ) );
	}

	public function task_failed() {
		$this->record->increment_values( array( self::$processed_items, self::$failed_items ) );
	}
}