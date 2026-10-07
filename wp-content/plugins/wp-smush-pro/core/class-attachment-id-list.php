<?php

namespace Smush\Core;

use Smush\Core\Threads\JSON_Scalar_Array;

class Attachment_Id_List {
	private $option_id;

	private $ids;

	/**
	 * @var JSON_Scalar_Array
	 */
	private $array;

	/**
	 * @var Array_Utils
	 */
	private $array_utils;

	public function __construct( $option_id ) {
		$this->option_id   = $option_id;
		$this->array_utils = new Array_Utils();
		$this->array       = new JSON_Scalar_Array( $option_id );
	}

	private function set_ids( $ids ) {
		$this->ids = $ids;
	}

	public function get_ids() {
		if ( is_null( $this->ids ) ) {
			// fast_array_unique also converts to integers
			$this->ids = $this->array_utils->fast_array_unique( $this->fetch_ids() );
		}

		return $this->ids;
	}

	private function fetch_ids() {
		$ids = $this->array->get( array() );

		return is_array( $ids ) ? $ids : array();
	}

	public function has_id( $id ) {
		return in_array( $id, $this->get_ids() );
	}

	public function add_id( $attachment_id ) {
		// Store as string so JSON_SEARCH can locate the value during remove_id.
		// JSON_SEARCH only works on string-typed JSON values, not numbers.
		$this->array->append( array( (string) $attachment_id ) );
		$this->ids = null;
	}

	public function add_ids( $attachment_ids ) {
		$this->array->append( array_map( 'strval', $attachment_ids ) );
		$this->ids = null;
	}

	public function remove_id( $attachment_id ) {
		// Must match the string storage format used by add_id/add_ids.
		$this->array->remove( (string) $attachment_id );
		$this->ids = null;
	}

	public function update_ids( $ids ) {
		// Not concurrent-write safe. Only call this from single-writer contexts.
		// Values stored as strings to match add_id/add_ids format for JSON_SEARCH compatibility.
		$this->array->unsafe_set( array_map( 'strval', array_values( $ids ) ) );
		$this->set_ids( array_values( $ids ) );
	}

	public function delete_ids() {
		$this->array->delete();
		$this->set_ids( array() );
	}

	public function get_count() {
		return count( $this->get_ids() );
	}
}