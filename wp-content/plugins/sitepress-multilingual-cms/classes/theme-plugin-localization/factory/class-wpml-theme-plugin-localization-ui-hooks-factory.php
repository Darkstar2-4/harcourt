<?php

use WPML\FP\Relation;
use function WPML\Container\make;

class WPML_Themes_Plugin_Localization_UI_Hooks_Factory implements IWPML_Backend_Action_Loader, IWPML_Deferred_Action_Loader {

	public function create() {
		return Relation::propEq( 'id', WPML_PLUGIN_FOLDER . '/menu/theme-localization', get_current_screen() )
			? make( WPML_Theme_Plugin_Localization_UI_Hooks::class )
			: null;
	}

	public function get_load_action() {
		return 'current_screen';
	}
}
