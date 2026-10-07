<?php

class WPML_ST_DB_Mappers_String_Positions {
	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get_count_of_positions_by_string_and_kind( $string_id, $kind ) {
		$query = "
			SELECT COUNT(id)
	        FROM {$this->wpdb->prefix}icl_string_positions 
	        WHERE string_id = %d AND kind = %d
        ";

		$sql = $this->wpdb->prepare( $query, $string_id, $kind );
		return (int) $this->wpdb->get_var( $sql );
	}

	public function get_positions_by_string_and_kind( $string_id, $kind ) {
		$query = "
			SELECT position_in_page
            FROM {$this->wpdb->prefix}icl_string_positions
          	WHERE string_id = %d AND kind = %d
        ";

		$sql = $this->wpdb->prepare( $query, $string_id, $kind );

		return $this->wpdb->get_col( $sql );
	}

	public function is_string_tracked( $string_id, $position, $kind ) {
		$query = "
			SELECT id
            FROM {$this->wpdb->prefix}icl_string_positions
            WHERE string_id=%d AND position_in_page=%s AND kind=%s
		";

		$sql = $this->wpdb->prepare( $query, $string_id, $position, $kind );

		return (bool) $this->wpdb->get_var( $sql );
	}

	public function insert( $string_id, $position, $kind ) {
		$this->wpdb->insert( $this->wpdb->prefix . 'icl_string_positions', array(
			'string_id'        => $string_id,
			'kind'             => $kind,
			'position_in_page' => $position,
		) );
	}
}