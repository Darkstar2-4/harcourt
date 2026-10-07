<?php

namespace WPML\PB\Elementor\Config\DynamicElements;

use WPML\FP\Obj;
use WPML\FP\Relation;
use function WPML\FP\compose;


class IconList {

	public static function get() {
		$isIconList = Relation::propEq( 'widgetType', 'icon-list' );

		$iconListLinksLens = compose(
			Obj::lensProp( 'settings' ),
			Obj::lensMappedProp( 'icon_list' ),
			Obj::lensPath( [ '__dynamic__', 'link' ] )
		);

		return [ $isIconList, $iconListLinksLens, 'popup', 'popup' ];
	}
}
