<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration;

class SiteKeyRemoveServiceFactory {

	public function createRemoveService(): \OTGS_Installer_Site_Key_Remove_Service {
		$installer           = \OTGS_Installer();
		$repositoriesFactory = new \OTGS_Installer_Repositories_Factory();
		$repositories        = $repositoriesFactory->create( $installer );
		$removeRequest       = new \OTGS_Installer_Site_Key_Remove_Request();

		return new \OTGS_Installer_Site_Key_Remove_Service( $repositories, $removeRequest );
	}

	public function createInstaller(): \WP_Installer {
		return \OTGS_Installer();
	}
}
