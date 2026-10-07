<?php

namespace OTGS\Installer\CommercialTab;

class SectionsManager {

	const SECTION_GENERAL = 'general';
	const SECTION_LEGACY = 'legacy';

	private $settings;

	private $installedPlugins;

	public function __construct( $settings ) {
		$this->settings         = $settings;
		$this->installedPlugins = $this->getInstalledPlugins();
	}

	public function getPluginsSections( $repositoryId, $downloads ) {
		return $this->addDownloadsToSections(
			$this->getSections( $repositoryId ),
			$downloads
		);
	}

	private function getSections( $repositoryId ) {
		$language = $this->getCurrentLanguage();
		$sections = [];

		if ( ! empty( $this->settings[ $repositoryId ]['data']['commecial_tab_sections'] ) ) {
			foreach (
				$this->settings[ $repositoryId ]['data']['commecial_tab_sections'] as $sectionName => $sectionData
			) {
				$sections[ $sectionName ]['name']      = isset( $sectionData[ $language ] )
					? $sectionData[ $language ]['name'] : $sectionData['en']['name'];
				$sections[ $sectionName ]['order']     = isset( $sectionData[ $language ] )
					? $sectionData[ $language ]['order'] : $sectionData['en']['order'];
				$sections[ $sectionName ]['downloads'] = [];
			}
		}

		uasort( $sections, function ( $a, $b ) {
			return (int) $a['order'] - (int) $b['order'];
		} );

		return $sections;
	}

	private function addDownloadsToSections( $sections, $downloads ) {
		foreach ( $downloads as $downloadSlug => $download ) {
			if ( empty( $download['download_commercial_tab_section'] ) ) {
				$download['download_commercial_tab_section'] = self::SECTION_GENERAL;
			}
			if ( $this->shouldDisplayOnCommercialTab( $download ) ) {
				$sections[ $download['download_commercial_tab_section'] ]['downloads'][ $downloadSlug ]
					= $download;
			}
		}

		return $sections;
	}

	private function shouldDisplayOnCommercialTab( $download ) {
		if ( $download['download_commercial_tab_section'] === self::SECTION_LEGACY ) {
			return $this->isPluginInstalled( $download['slug'] );
		} else {
			return true;
		}
	}

	private function isPluginInstalled( $slug ) {
		return isset( $this->installedPlugins[ $slug ] );
	}

	private function getInstalledPlugins() {
		$installed_plugins = [];

		foreach ( get_plugins() as $plugin_id => $plugin_data ) {
			$installed_plugins[ dirname( $plugin_id ) ] = true;
		}

		return $installed_plugins;
	}

	private function getCurrentLanguage() {
		global $sitepress;

		return $sitepress ? $sitepress->get_admin_language() : 'en';
	}

}
