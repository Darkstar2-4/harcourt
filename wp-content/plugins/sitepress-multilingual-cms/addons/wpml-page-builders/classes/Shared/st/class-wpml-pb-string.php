<?php

class WPML_PB_String {

	private $value;

	private $name;
	private $title;
	private $editor_type;

	private $wrap_tag;

	public function __construct( $value, $name, $title, $editor_type, $wrap_tag = '' ) {
		$this->value       = $value;
		$this->name        = $name;
		$this->title       = $title;
		$this->editor_type = $editor_type;
		$this->wrap_tag    = $wrap_tag;
	}

	public function get_value() {
		return $this->value;
	}

	public function set_value( $value ) {
		$this->value = $value;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_title() {
		return $this->title;
	}

	public function set_title( $title ) {
		$this->title = $title;
	}

	public function get_editor_type() {
		return $this->editor_type;
	}

	public function get_wrap_tag() {
		return $this->wrap_tag;
	}
}
