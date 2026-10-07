<?php

namespace Smush\Core\Frontend;

class Admin_Page {
	private $title;
	private $slug;
	private $position;
	private $styles = array();
	private $scripts = array();

	public function get_title() {
		return $this->title;
	}

	public function set_title( $title ) {
		$this->title = $title;
		return $this;
	}

	public function get_slug() {
		return $this->slug;
	}

	public function set_slug( $slug ) {
		$this->slug = $slug;
		return $this;
	}

	public function get_position() {
		return $this->position;
	}

	public function set_position( $position ) {
		$this->position = $position;
		return $this;
	}

	public function get_styles() {
		return $this->styles;
	}

	public function set_styles( $styles ) {
		$this->styles = $styles;
		return $this;
	}

	public function get_scripts() {
		return $this->scripts;
	}

	public function set_scripts( $scripts ) {
		$this->scripts = $scripts;
		return $this;
	}
}