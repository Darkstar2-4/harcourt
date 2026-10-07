<?php

class WPML_ST_ICL_Strings extends WPML_WPDB_User {

	private $table     = 'icl_strings';
	private $string_id = 0;

	public function __construct( &$wpdb, $string_id ) {
		parent::__construct( $wpdb );
		$string_id = (int) $string_id;
		if ( $string_id > 0 ) {
			$this->string_id = $string_id;
		} else {
			throw new InvalidArgumentException( 'Invalid String ID: ' . $string_id );
		}
	}

	public function update( $args ) {
		$this->wpdb->update(
			$this->wpdb->prefix . $this->table,
			$args,
			array( 'id' => $this->string_id )
		);

		return $this;
	}

	public function value() {

		return $this->wpdb->get_var(
			$this->wpdb->prepare(
				" SELECT value
									FROM {$this->wpdb->prefix}{$this->table}
									WHERE id = %d LIMIT 1",
				$this->string_id
			)
		);
	}

	public function language() {

		return $this->wpdb->get_var(
			$this->wpdb->prepare(
				" SELECT language
									FROM {$this->wpdb->prefix}{$this->table}
									WHERE id = %d LIMIT 1",
				$this->string_id
			)
		);
	}

	public function status() {

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				" SELECT status
									FROM {$this->wpdb->prefix}{$this->table}
									WHERE id = %d LIMIT 1",
				$this->string_id
			)
		);
	}
}
