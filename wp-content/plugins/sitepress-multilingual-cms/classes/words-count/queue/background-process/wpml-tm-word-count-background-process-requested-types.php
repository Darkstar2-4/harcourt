<?php

class WPML_TM_Word_Count_Background_Process_Requested_Types extends WPML_TM_Word_Count_Background_Process {

	protected $queue;

	private $records;

	public function __construct(
		WPML_TM_Word_Count_Queue_Items_Requested_Types $queue_items,
		array $setters,
		WPML_TM_Word_Count_Records $records
	) {
		$this->action = WPML_TM_Word_Count_Background_Process_Factory::ACTION_REQUESTED_TYPES;
		parent::__construct( $queue_items, $setters );
		$this->records = $records;

		add_filter(
			'wpml_tm_word_count_background_process_requested_types_memory_exceeded',
			array(
				$this,
				'memory_exceeded_filter',
			)
		);
	}

	public function init( $requested_types ) {
		$this->queue->reset( $requested_types );
		$this->records->reset_all( $requested_types );
		$this->dispatch();
	}

	public function dispatch() {
		update_option(
			WPML_TM_Word_Count_Hooks_Factory::OPTION_KEY_REQUESTED_TYPES_STATUS,
			WPML_TM_Word_Count_Hooks_Factory::PROCESS_IN_PROGRESS
		);

		parent::dispatch();
	}

	public function complete() {
		update_option(
			WPML_TM_Word_Count_Hooks_Factory::OPTION_KEY_REQUESTED_TYPES_STATUS,
			WPML_TM_Word_Count_Hooks_Factory::PROCESS_COMPLETED
		);

		parent::complete();
	}

	public function memory_exceeded_filter() {
		$memory_limit   = $this->get_memory_limit() * 0.9;
		$current_memory = memory_get_usage( true );

		return $current_memory >= $memory_limit;
	}

	protected function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			$memory_limit = '128M';
		}

		if ( ! $memory_limit || - 1 === intval( $memory_limit ) ) {
			$memory_limit = '32000M';
		}

		return $this->convert_shorthand_to_bytes( $memory_limit );
	}

	protected function convert_shorthand_to_bytes( $value ) {
		$value = strtolower( trim( $value ) );
		$bytes = (int) $value;

		if ( false !== strpos( $value, 'g' ) ) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif ( false !== strpos( $value, 'm' ) ) {
			$bytes *= 1024 * 1024;
		} elseif ( false !== strpos( $value, 'k' ) ) {
			$bytes *= 1024;
		}

		return min( $bytes, PHP_INT_MAX );
	}
}
