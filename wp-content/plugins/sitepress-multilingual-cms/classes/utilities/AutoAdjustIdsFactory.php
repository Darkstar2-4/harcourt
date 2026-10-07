<?php

namespace WPML\Utils;

class AutoAdjustIdsFactory {
	public static function create() {
		global $sitepress;

		return new AutoAdjustIds( $sitepress, $sitepress->get_wp_api() );
	}
}

