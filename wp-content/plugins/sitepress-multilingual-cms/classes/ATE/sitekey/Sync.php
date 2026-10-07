<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\Core\BackgroundTask\Service\BackgroundTaskService;
use function WPML\Container\make;

class Sync implements \IWPML_Backend_Action, \IWPML_DIC_Action {

	private $backgroundTaskService;

	public function __construct( BackgroundTaskService $backgroundTaskService ) {
		$this->backgroundTaskService = $backgroundTaskService;
	}

	public function add_hooks() {
		if ( ! SitekeyConfirmationFlag::isCompleted() && \WPML_TM_ATE_Status::is_enabled_and_activated() ) {
			$this->backgroundTaskService->addOnce(
				make( Endpoint::class ),
				wpml_collect( [] )
			);
		}
	}
}
