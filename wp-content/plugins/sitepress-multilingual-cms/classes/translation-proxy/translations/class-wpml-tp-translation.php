<?php

class WPML_TP_Translation {
	private $field;

	private $source;

	private $target;

	public function __construct( $field, $source, $target ) {
		$this->field  = $field;
		$this->source = $source;
		$this->target = $target;
	}

	public function get_field() {
		return $this->field;
	}

	public function get_source() {
		return $this->source;
	}

	public function get_target() {
		return $this->target;
	}

	public function to_array() {
		return get_object_vars( $this );
	}
}
