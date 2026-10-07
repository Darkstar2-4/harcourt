<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration\Resetter;

use WPML\TM\ATE\ClonedSites\SetupMigration\SiteKeyRemoveServiceFactory;

class SiteKeyCleaner {

	private $factory;

	public function __construct( SiteKeyRemoveServiceFactory $factory ) {
		$this->factory = $factory;
	}

	public function unregister() {
		if ( $this->isWPMLSiteKeyDefinedInWPConfig() ) {
			return;
		}

		$this->factory->createRemoveService()->remove( 'wpml', false );
		icl_set_setting( 'site_key', null, true );
	}

	private function isWPMLSiteKeyDefinedInWPConfig(): bool {
		return class_exists( 'WP_Installer' ) && \WP_Installer::get_repository_hardcoded_site_key( 'wpml' );
	}
}
