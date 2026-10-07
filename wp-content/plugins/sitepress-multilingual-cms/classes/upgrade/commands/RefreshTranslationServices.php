<?php

namespace WPML\TM\Upgrade\Commands;

use WPML\TM\Menu\TranslationServices\Troubleshooting\RefreshServices;
use WPML\TM\Menu\TranslationServices\Troubleshooting\RefreshServicesFactory;

class RefreshTranslationServices implements \IWPML_Upgrade_Command {

	const WPML_VERSION_SINCE_PREVIEW_LOGOS_AVAILABLE = '4.4.0';


	private $result = false;

	private $refreshServicesFactory;

	private $isHigherThanInstallationVersion;

	public function __construct( array $args ) {
		$this->refreshServicesFactory          = $args[0];
		$this->isHigherThanInstallationVersion = $args[1];
	}

	public function run_admin() {
		$this->result = true;
		if ( call_user_func( $this->isHigherThanInstallationVersion, self::WPML_VERSION_SINCE_PREVIEW_LOGOS_AVAILABLE ) ) {
			$this->result = $this->refreshServicesFactory->create_an_instance()->refresh_services();
		}

		return $this->result;
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return $this->result;
	}
}
