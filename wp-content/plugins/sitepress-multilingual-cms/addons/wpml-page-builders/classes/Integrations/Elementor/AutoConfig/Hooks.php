<?php

namespace WPML\PB\Elementor\AutoConfig;

use Elementor\Plugin;

class Hooks implements \IWPML_Action {

	private $generator;

	private $cache;

	public function __construct( Generator $generator, Cache $cache ) {
		$this->generator = $generator;
		$this->cache     = $cache;
	}

	public function add_hooks() {
		add_filter( 'wpml_elementor_widgets_to_translate', [ $this, 'extendTranslatableWidgets' ], PHP_INT_MAX );
		add_action( 'activated_plugin', [ $this, 'clearCache' ] );
		add_action( 'deactivated_plugin', [ $this, 'clearCache' ] );
		add_action( 'switch_theme', [ $this, 'clearCache' ] );
		add_action( 'wpml_elementor_auto_config_clear_cache', [ $this, 'clearCache' ] );
	}

	public function extendTranslatableWidgets( $widgetsToTranslate ) {
		$widgetInstances = $this->getWidgetInstances();
		$currentHash     = $this->cache->generateHash( $widgetInstances );
		$config          = $this->cache->get( $currentHash );

		if ( null === $config ) {
			$config = $this->generator->generate( $widgetsToTranslate, $widgetInstances );

			$this->cache->set( $config, $currentHash );
		}

		return array_merge( $widgetsToTranslate, $config );
	}

	public function clearCache() {
		$this->cache->clear();
	}

	protected function getWidgetInstances() {
		try {
			return Plugin::$instance->widgets_manager->get_widget_types();
		} catch ( \Throwable $e ) {
			return [];
		}
	}
}
