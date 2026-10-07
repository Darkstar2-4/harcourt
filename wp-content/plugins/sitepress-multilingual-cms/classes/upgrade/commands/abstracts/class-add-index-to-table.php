<?php

namespace WPML\Upgrade\Commands;

abstract class AddIndexToTable extends \WPML_Upgrade_Run_All {

	abstract protected function get_table();

	abstract protected function get_index();

	abstract protected function get_index_definition();

	private $upgrade_schema;

	public function __construct( array $args ) {
		$this->upgrade_schema = $args[0];
	}

	protected function run() {
		$this->result = false;

		if ( $this->upgrade_schema->does_table_exist( $this->get_table() ) ) {
			if ( ! $this->upgrade_schema->does_index_exist( $this->get_table(), $this->get_index() ) ) {
				$this->result = $this->upgrade_schema->add_index( $this->get_table(), $this->get_index(), $this->get_index_definition() );
			} else {
				$this->result = true;
			}
		}

		return $this->result;
	}
}
