<?php

namespace OTGS\Installer\Recommendations;

class GluePluginData {

	private $pluginSlug;

	private $gluePluginData;

	public function __construct( $pluginSlug, $gluePluginData ) {
		$this->pluginSlug = $pluginSlug;
		$this->gluePluginData = $gluePluginData;
	}

	public function getPluginSlug() {
		return $this->pluginSlug;
	}

	public function getGluePluginData() {
		return $this->gluePluginData;
	}
}
