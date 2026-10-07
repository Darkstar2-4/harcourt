<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration\Resetter;

use WPML\TM\ATE\ClonedSites\SetupMigration\SiteKeyRemoveServiceFactory;

class SiteKeyRegistrar {

	private $factory;

	public function __construct( ?SiteKeyRemoveServiceFactory $factory = null ) {
		$this->factory = $factory ?: new SiteKeyRemoveServiceFactory();
	}

	public function register( string $siteKey ): bool {
		icl_set_setting( 'site_key', null, true );

		$result = $this->factory->createInstaller()->save_site_key( [
			'repository_id' => 'wpml',
			'nonce'         => wp_create_nonce( 'save_site_key_wpml' ),
			'site_key'      => $siteKey,
			'return'        => 1,
		] );

		if ( ! empty( $result['error'] ) ) {
			return false;
		}

		icl_set_setting( 'site_key', $siteKey, true );

		return true;
	}
}
