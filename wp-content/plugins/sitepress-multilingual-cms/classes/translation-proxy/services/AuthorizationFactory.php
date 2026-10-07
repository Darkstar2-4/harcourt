<?php

namespace WPML\TM\TranslationProxy\Services;

use WPML\TM\TranslationProxy\Services\Project\Manager;
use function WPML\Container\make;

class AuthorizationFactory {
	public function create() {
		$projectManager = make(
			Manager::class,
			[
				':projectApi' => wpml_tm_get_tp_project_api(),
			]
		);

		return make( Authorization::class, [ ':projectManager' => $projectManager ] );
	}

}
