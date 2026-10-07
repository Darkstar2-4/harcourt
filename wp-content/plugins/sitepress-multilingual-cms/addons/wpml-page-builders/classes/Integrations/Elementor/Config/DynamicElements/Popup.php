<?php

namespace WPML\PB\Elementor\Config\DynamicElements;

use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\FP\Logic;


class Popup {

	public static function get() {
		$popupPath = [ 'settings', '__dynamic__', 'link' ];

		$isDynamicLink = Logic::allPass( [
			Relation::propEq( 'elType', 'widget' ),
			Obj::path( $popupPath ),
		] );

		$lens = Obj::lensPath( $popupPath );

		return [ $isDynamicLink, $lens, 'popup', 'popup' ];
	}
}
