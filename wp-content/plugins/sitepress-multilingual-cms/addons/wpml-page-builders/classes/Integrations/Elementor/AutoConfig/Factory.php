<?php

namespace WPML\PB\Elementor\AutoConfig;

use WPML\PB\Elementor\AutoConfig\Processors\AtomicWidgetProcessor;
use WPML\PB\Elementor\AutoConfig\Processors\ClassicWidgetProcessor;

class Factory implements \IWPML_Backend_Action_Loader, \IWPML_Frontend_Action_Loader {

	public function create() {
		$processors = [
			new AtomicWidgetProcessor(),
			new ClassicWidgetProcessor(),
		];

		return new Hooks( new Generator( $processors ), new Cache() );
	}
}
