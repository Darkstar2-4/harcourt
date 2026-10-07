<?php

abstract class WPML_TM_Word_Count_Background_Process extends WP_Background_Process {

	protected $queue;

	private $setters;

	public function __construct( IWPML_TM_Word_Count_Queue_Items $queue, array $setters ) {
		$this->prefix = WPML_TM_Word_Count_Background_Process_Factory::PREFIX;
		$this->action = WPML_TM_Word_Count_Background_Process_Factory::ACTION_REQUESTED_TYPES;

		parent::__construct();

		$this->queue   = $queue;
		$this->setters = $setters;
	}

	protected function task( $item ) {}

	protected function handle() {
		$this->lock_process();

		while ( ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->queue->is_completed() ) {

			list( $id, $type ) = $this->queue->get_next();

			if ( $id && $type ) {
				$this->setters[ $type ]->process( $id );
				$this->queue->remove( $id, $type );
			}
		}

		$this->queue->save();
		$this->unlock_process();

		if ( $this->queue->is_completed() ) {
			$this->complete();
		} else {
			$this->dispatch();
		}

		wp_die();
	}

	protected function is_queue_empty() {
		return $this->queue->is_completed();
	}
}
