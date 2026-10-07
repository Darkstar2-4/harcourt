<?php

class OTGS_Installer_PHP_Functions {

	public function defined( $constant_name ) {
		return defined( $constant_name );
	}

	public function constant( $constant_name ) {
		return $this->defined( $constant_name ) ? constant( $constant_name ) : null;
	}

	public function time() {
		return time();
	}

	public function phpversion() {
		return phpversion();
	}
}