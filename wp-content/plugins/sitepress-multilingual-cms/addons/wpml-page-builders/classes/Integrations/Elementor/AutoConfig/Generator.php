<?php

namespace WPML\PB\Elementor\AutoConfig;

use Elementor\Widget_Base;
use WPML\PB\Elementor\AutoConfig\Processors\WidgetProcessorInterface;

class Generator {

	private $processors;

	public function __construct( array $processors ) {
		$this->processors = $processors;
	}

	public function generate( array $existingWidgets, array $widgetInstances ) {
		$config = [];

		foreach ( $widgetInstances as $widgetType => $widgetInstance ) {
			if ( array_key_exists( $widgetType, $existingWidgets ) ) {
				continue;
			}

			$widgetConfig = $this->generateWidgetConfig( $widgetInstance );

			if ( ! empty( $widgetConfig ) ) {
				$widgetConfig['conditions'] = [ 'widgetType' => $widgetType ];
				$config[ $widgetType ]      = $widgetConfig;
			}
		}

		return $config;
	}

	private function generateWidgetConfig( $widgetInstance ) {
		foreach ( $this->processors as $processor ) {
			if ( $processor->canProcess( $widgetInstance ) ) {
				return $processor->process( $widgetInstance );
			}
		}

		return [];
	}
}
