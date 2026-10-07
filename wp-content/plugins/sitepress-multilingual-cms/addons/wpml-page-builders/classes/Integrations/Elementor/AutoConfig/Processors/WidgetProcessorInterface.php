<?php

namespace WPML\PB\Elementor\AutoConfig\Processors;

use Elementor\Widget_Base;

interface WidgetProcessorInterface {

	public function canProcess( $widget );

	public function process( $widget );
}
