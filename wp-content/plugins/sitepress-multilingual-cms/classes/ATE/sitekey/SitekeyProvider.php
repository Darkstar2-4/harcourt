<?php

namespace WPML\TM\ATE\Sitekey;

class SitekeyProvider {

	public function hasSitekey(): bool {
		return ! empty( $this->getSitekey() );
	}

	public function getSitekey() {
		if ( ! $this->isInstallerAvailable() ) {
			return null;
		}

		return \OTGS_Installer()->get_site_key( 'wpml' );
	}

	private function isInstallerAvailable() {
		return function_exists( 'OTGS_Installer' );
	}
}
