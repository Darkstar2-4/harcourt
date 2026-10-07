<?php

class WPML_TM_Jobs_Sorting_Param {
	private $column;

	private $direction;

	public function __construct( $column, $direction = 'asc' ) {
		$direction = strtolower( $direction );
		if ( 'asc' !== $direction && 'desc' !== $direction ) {
			$direction = 'asc';
		}

		$this->column    = $column;
		$this->direction = $direction;
	}

	public function get_column() {
		return $this->column;
	}

	public function get_direction() {
		return $this->direction;
	}
}
