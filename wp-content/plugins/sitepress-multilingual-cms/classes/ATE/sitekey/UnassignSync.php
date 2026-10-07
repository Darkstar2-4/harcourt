<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\Core\BackgroundTask\Service\BackgroundTaskService;
use function WPML\Container\make;

class UnassignSync implements \IWPML_Backend_Action, \IWPML_DIC_Action {

	private $backgroundTaskService;

	private $sitekeyProvider;

	public function __construct( BackgroundTaskService $backgroundTaskService, SitekeyProvider $sitekeyProvider ) {
		$this->backgroundTaskService = $backgroundTaskService;
		$this->sitekeyProvider       = $sitekeyProvider;
	}

	public function add_hooks() {
		if (
			StoredSitekey::get()
			&& ! $this->sitekeyProvider->hasSitekey()
			&& \WPML_TM_ATE_Status::is_enabled_and_activated()
		) {
			$this->backgroundTaskService->addOnce(
				make( UnassignEndpoint::class ),
				wpml_collect( [] )
			);
		}
	}
}
