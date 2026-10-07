<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration\Resetter;

use WPML\TM\ATE\ClonedSites\Lock;

class AmsCredentialsCleaner {

	private $authentication;

	public function __construct( \WPML_TM_ATE_Authentication $authentication ) {
		$this->authentication = $authentication;
	}

	public function clear() {
		$this->authentication->reset();
		delete_option( Lock::CLONED_SITE_OPTION );
	}
}
