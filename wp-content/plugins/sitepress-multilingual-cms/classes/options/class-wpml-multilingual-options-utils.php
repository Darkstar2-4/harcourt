<?php

class WPML_Multilingual_Options_Utils {
	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get_option_without_filtering( $option_name, $default = null ) {

		$value_query = "SELECT option_value
						FROM {$this->wpdb->options}
						WHERE option_name = %s
						LIMIT 1";
		$value_sql   = $this->wpdb->prepare( $value_query, $option_name );
		$value       = $this->wpdb->get_var( $value_sql );

		return $value ? maybe_unserialize( $value ) : $default;
	}
}
