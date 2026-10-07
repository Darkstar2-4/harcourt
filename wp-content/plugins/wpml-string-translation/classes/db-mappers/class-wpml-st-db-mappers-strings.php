<?php

class WPML_ST_DB_Mappers_Strings {
	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get_all_by_context( $context ) {
		$where = strpos( $context, '%' ) === false ? '=' : 'LIKE';
		$query = "
			SELECT * FROM {$this->wpdb->prefix}icl_strings
        	WHERE context {$where} %s
		";

		$query = $this->wpdb->prepare( $query, esc_sql( $context ) );

		return $this->wpdb->get_results( $query, ARRAY_A );
	}

	public function getByDomainAndValue( $domain, $value ) {
		$sql = "SELECT * FROM {$this->wpdb->prefix}icl_strings WHERE `context` = %s and `value` = %s";

		return $this->wpdb->get_row( $this->wpdb->prepare( $sql, $domain, $value ) );
	}

	public function getById( $id ) {
		$sql = "SELECT * FROM {$this->wpdb->prefix}icl_strings WHERE id = %d";

		return $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ) );
	}
}
