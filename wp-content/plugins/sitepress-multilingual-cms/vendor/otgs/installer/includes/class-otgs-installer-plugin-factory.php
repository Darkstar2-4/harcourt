<?php

class OTGS_Installer_Plugin_Factory {

	public function create( array $params = array() ) {
		return new OTGS_Installer_Plugin( $params );
	}
}