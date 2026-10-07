<?php

namespace Smush\Core\Background;

use Smush\Core\Threads\JSON_Object_Array;
use Smush\Core\Threads\JSON_Record;

class Background_Queue {
	/**
	 * @var JSON_Object_Array
	 */
	private $object_array;

	/**
	 * @var JSON_Record
	 */
	private $meta;

	private $key;

	public function __construct( $key ) {
		$this->key          = $key;
		$this->object_array = new JSON_Object_Array( $key, false );
		$this->meta         = new JSON_Record( $key . '_queue_meta', false );
	}

	public function is_empty() {
		return empty( $this->object_array->get() );
	}

	/**
	 * @param \JsonSerializable[] $tasks
	 *
	 * @return bool
	 */
	public function populate( $tasks ) {
		if ( empty( $tasks ) || ! is_array( $tasks ) ) {
			return false;
		}

		$this->meta->init();
		$this->meta->set_values( array( 'current' => - 1, 'total' => count( $tasks ) ) );

		$this->object_array->init();
		return $this->object_array->unsafe_set( $tasks );
	}

	public function get_next_task() {
		$new_pointer = $this->meta->increment_and_get( 'current' );
		if ( $new_pointer === false ) {
			// Queue row was deleted
			return false;
		}

		$item = $this->object_array->get_item( $new_pointer );
		return empty( $item ) ? null : $item;
	}

	public function get_remaining_count() {
		$meta  = $this->meta->get();
		$total   = isset( $meta['total'] ) ? (int) $meta['total'] : 0;
		$current = isset( $meta['current'] ) ? (int) $meta['current'] : - 1;

		return max( 0, $total - ( $current + 1 ) );
	}

	public function cleanup() {
		$this->meta->delete();
		$this->object_array->delete();
	}
}