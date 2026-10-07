<?php

namespace OTGS\Installer\Recommendations;
class RecommendationsForInstallerPlugins {

	private $pluginsRecommendations;

	private $pluginsData;

	public function __construct( $pluginsRecommendations, $pluginsData ) {
		$this->pluginsRecommendations = $pluginsRecommendations;
		$this->pluginsData = $pluginsData;
	}

	public function getRecommendations() {
		return $this->pluginsRecommendations;
	}

	public function getPluginsData() {
		return $this->pluginsData;
	}
}
