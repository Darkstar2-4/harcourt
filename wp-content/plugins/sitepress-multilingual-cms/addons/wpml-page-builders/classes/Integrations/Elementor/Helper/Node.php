<?php

namespace WPML\PB\Elementor\Helper;

class Node {

	public static function isTranslatable( $element ) {
		if ( ! isset( $element['elType'] ) ) {
			return false;
		}

		$elType = $element['elType'];

		return in_array( $elType, [ 'widget', 'container' ], true ) || strpos( $elType, 'e-' ) === 0;
	}

	public static function hasChildren( $element ) {
		return isset( $element['elements'] ) && count( $element['elements'] );
	}
}
