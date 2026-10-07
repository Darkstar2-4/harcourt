<?php

class OTGS_Installer_Instance {

	public $bootfile;

	public $version;

	public $high_priority;

	public $delegated;

	public function set_bootfile( $bootfile ) {
		$this->bootfile = $bootfile;
		return $this;
	}

	public function set_high_priority( $high_priority ) {
		$this->high_priority = $high_priority;
		return $this;
	}

	public function set_version( $version ) {
		$this->version = $version;
		return $this;
	}

	public function set_delegated( $delegated ) {
		$this->delegated = (bool) $delegated;
		return $this;
	}
}