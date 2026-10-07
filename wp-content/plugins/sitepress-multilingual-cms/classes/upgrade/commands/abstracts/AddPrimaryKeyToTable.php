<?php

namespace WPML\Upgrade\Commands;

abstract class AddPrimaryKeyToTable extends \WPML_Upgrade_Run_All {

	abstract protected function get_table();

	abstract protected function get_key_name();

	abstract protected function get_key_columns();

	private $upgrade_schema;

	public function __construct( array $args ) {
		$this->upgrade_schema = $args[0];
	}

	protected function run() {
		$this->result = false;

		if ( $this->upgrade_schema->does_table_exist( $this->get_table() ) ) {
			if ( ! $this->upgrade_schema->does_key_exist( $this->get_table(), $this->get_key_name() ) ) {
				$this->result = $this->upgrade_schema->add_primary_key( $this->get_table(), $this->get_key_columns() );
			} else {
				$this->result = true;
			}
		}

		return $this->result;
	}
}
