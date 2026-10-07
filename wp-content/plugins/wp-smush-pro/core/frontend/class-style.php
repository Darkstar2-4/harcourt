<?php

namespace Smush\Core\Frontend;

class Style {
	private $handle;
	private $source;
	private $dependencies = array();
	private $version;
	private $media = 'all';

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

	public function get_media() {
		return $this->media;
	}

	public function set_media( $media ) {
		$this->media = $media;
		return $this;
	}
}