<?php

namespace WPML\PB\Cornerstone;

class Utils {

	const MODULE_TYPE_PREFIX = 'classic:';
	const LAYOUT_TYPES       = [
		'bar',
		'container',
		'section',
		'row',
		'column',
		'layout-row',
		'layout-column',
		'layout-grid',
		'layout-cell',
		'layout-div',
		'layout-modal',
		'layout-off-canvas',
		'layout-slide-container',
		'layout-slide',
		'layout-dropdown',
	];

	const NODES_WITH_MODULES = [
		'accordion',
		'accordion-item-elements',
		'tabs',
		'tab-elements',
		'nav-inline',
	];

	public static function getNodeId( $data ) {
		return md5( serialize( $data ) );
	}

	public static function typeIsLayout( $type ) {
		$type = preg_replace( '/^' . self::MODULE_TYPE_PREFIX . '/', '', $type );

		return in_array( $type, self::getLayoutTypes(), true );
	}

	public static function getLayoutTypes() {
		return (array) apply_filters( 'wpml_cornerstone_layout_types', self::LAYOUT_TYPES );
	}

	public static function getNodesWithModules() {
		return (array) apply_filters( 'wpml_cornerstone_nodes_with_modules', self::NODES_WITH_MODULES );
	}

	public static function shouldCheckForSubmodules( $type ) {
		$shouldCheckForSubmodules = in_array( $type, self::getNodesWithModules() );

		return apply_filters( 'wpml_cornerstone_should_check_for_submodules', $shouldCheckForSubmodules, $type );
	}

}
