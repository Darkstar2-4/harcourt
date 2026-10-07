<?php

namespace Smush\Core\Frontend;

class Script {
	private $handle;
	private $source;
	private $dependencies = array();
	private $version;
	private $in_footer = true;
	private $localization_data = array();

	public function get_handle() {
		return $this->handle;
	}

	public function set_handle( $handle ) {
		$this->handle = $handle;
		return $this;
	}

	public function get_source() {
		return $this->source;
	}

	public function set_source( $source ) {
		$this->source = $source;
		return $this;
	}

	public function get_dependencies() {
		return $this->dependencies;
	}

	public function set_dependencies( $dependencies ) {
		$this->dependencies = $dependencies;
		return $this;
	}

	public function get_version() {
		return $this->version;
	}

	public function set_version( $version ) {
		$this->version = $version;
		return $this;
	}

	public function get_in_footer() {
		return $this->in_footer;
	}

	public function set_in_footer( $in_footer ) {
		$this->in_footer = $in_footer;
		return $this;
	}

	public function get_localization_data() {
		return $this->localization_data;
	}

	public function set_localization_data( $localization_data ) {
		$this->localization_data = $localization_data;
		return $this;
	}
}