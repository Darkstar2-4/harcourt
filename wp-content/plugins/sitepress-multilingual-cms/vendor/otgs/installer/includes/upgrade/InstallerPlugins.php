<?php

namespace OTGS\Installer\Upgrade;

class InstallerPlugins {

	private $installer;

	private $installerPluginFinder;

	private $_filteredInstallerPlugins;

	public function __construct( \WP_Installer $installer, \OTGS_Installer_Plugin_Finder $installerPluginsFinder ) {
		$this->installer             = $installer;
		$this->installerPluginFinder = $installerPluginsFinder;
	}

	public function getFilteredInstallerPlugins() {
		return $this->filteredInstallerPlugins();
	}

	private function filteredInstallerPlugins() {
		if ( null === $this->_filteredInstallerPlugins ) {
			$this->_filteredInstallerPlugins = $this->filterInstallerPlugins();
		}
		return $this->_filteredInstallerPlugins;
	}

	private function filterInstallerPlugins() {
		$filteredInstallerPlugins = [];
		$installerPluginsFinder   = $this->installerPluginFinder;

		foreach ( $installerPluginsFinder->get_otgs_installed_plugins_by_repository() as $repositoryId => $installedRepositoryPlugins ) {
			foreach ( $installedRepositoryPlugins as $installedRepositoryPlugin ) {
				$pluginObj  = $installerPluginsFinder->get_plugin( $installedRepositoryPlugin['slug'], $repositoryId );
				if ( !$pluginObj || $pluginObj->get_external_repo() && $this->installer->plugin_is_registered( $pluginObj->get_external_repo(), $installedRepositoryPlugin['slug'] ) ) {
					continue;
				}

				$filteredInstallerPlugins[ $repositoryId ][] = $installedRepositoryPlugin;
			}
		}

		return $filteredInstallerPlugins;
	}

	public function getPluginData( $repositoryId, $pluginId ) {
		return current( array_filter( $this->filteredInstallerPlugins()[ $repositoryId ], function ( $plugin ) use ( $pluginId ) {
			return $plugin['id'] === $pluginId;
		} ) );
	}
}
