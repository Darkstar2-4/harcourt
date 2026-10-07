<?php

namespace WPML\Upgrade\Commands;

abstract class DropIndexFromTable extends \WPML_Upgrade_Run_All {

	abstract protected function get_table();

	abstract protected function get_index();

	private $upgrade_schema;

	public function __construct( array $args ) {
		$this->upgrade_schema = $args[0];
	}

	protected function run() {
		$this->result = false;

		if ( $this->upgrade_schema->does_table_exist( $this->get_table() ) ) {
			if ( $this->upgrade_schema->does_index_exist( $this->get_table(), $this->get_index() ) ) {
				$this->result = $this->upgrade_schema->drop_index( $this->get_table(), $this->get_index() );
			} else {
				$this->result = true;
			}
		}

		return $this->result;
	}
}
