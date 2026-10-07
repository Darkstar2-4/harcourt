<?php

namespace WPML\TM\Menu\TranslationServices;

use function WPML\Container\make;

class AuthenticationAjaxFactory implements \IWPML_Backend_Action_Loader {
	public function create() {
		return make( AuthenticationAjax::class );
	}
}
