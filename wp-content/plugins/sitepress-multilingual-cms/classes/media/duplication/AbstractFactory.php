<?php

namespace WPML\Media\Duplication;

abstract class AbstractFactory implements \IWPML_Backend_Action_Loader, \IWPML_Frontend_Action_Loader {
	protected static function shouldActivateHooks() {
		return \WPML_Element_Sync_Settings_Factory::createPost()->is_sync( 'attachment' );
	}

}
