<?php

class WPML_TM_Jobs_Batch {
	private $id;

	private $name;

	private $tp_id;

	public function __construct( $id, $name, $tp_id = null ) {
		$this->id    = (int) $id;
		$this->name  = (string) $name;
		$this->tp_id = $tp_id ? (int) $tp_id : null;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_tp_id() {
		return $this->tp_id;
	}
}
