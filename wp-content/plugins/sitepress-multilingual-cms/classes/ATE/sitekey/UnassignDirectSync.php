<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\Core\BackgroundTask\Repository\BackgroundTaskRepository;
use WPML\Core\BackgroundTask\Service\BackgroundTaskService;
use WPML\LIB\WP\Hooks;
use function WPML\Container\make;
use function WPML\FP\spreadArgs;

class UnassignDirectSync implements \IWPML_Backend_Action, \IWPML_DIC_Action {

	private $backgroundTaskService;

	private $backgroundTaskRepository;

	private $unassignApiClient;

	private $sitekeyProvider;

	public function __construct(
		BackgroundTaskService $backgroundTaskService,
		BackgroundTaskRepository $backgroundTaskRepository,
		UnassignApiClient $unassignApiClient,
		SitekeyProvider $sitekeyProvider
	) {
		$this->backgroundTaskService    = $backgroundTaskService;
		$this->backgroundTaskRepository = $backgroundTaskRepository;
		$this->unassignApiClient        = $unassignApiClient;
		$this->sitekeyProvider          = $sitekeyProvider;
	}

	public function add_hooks() {
		Hooks::onAction( 'otgs_installer_before_site_key_removal' )
			->then( spreadArgs( [ $this, 'storeSitekeyBeforeRemoval' ] ) );

		Hooks::onAction( 'otgs_installer_site_key_update' )
			->then( spreadArgs( [ $this, 'handleSiteKeyUpdate' ] ) );
	}

	public function storeSitekeyBeforeRemoval( $repo ) {
		if ( $repo !== 'wpml' ) {
			return;
		}

		if ( $this->sitekeyProvider->hasSitekey() ) {
			StoredSitekey::store( $this->sitekeyProvider->getSitekey() );
		}
	}

	public function handleSiteKeyUpdate( $repo ) {
		if ( $repo !== 'wpml' ) {
			return;
		}

		if ( ! \WPML_TM_ATE_Status::is_enabled_and_activated() ) {
			return;
		}

		if ( $this->sitekeyProvider->hasSitekey() ) {
			return;
		}

		$storedKey = StoredSitekey::get();
		if ( ! $storedKey ) {
			return;
		}

		$result = $this->unassignApiClient->unassign( $storedKey );

		if ( $result->isSuccess() ) {
			StoredSitekey::clear();
			$this->cleanupExistingTasks();
		} elseif ( $result->isRetryable() ) {
			$this->scheduleBackgroundTask();
		} else {
			StoredSitekey::clear();
		}
	}

	private function scheduleBackgroundTask() {
		$this->backgroundTaskService->addOnce(
			make( UnassignEndpoint::class ),
			wpml_collect( [] )
		);
	}

	private function cleanupExistingTasks() {
		$task = $this->backgroundTaskRepository->getLastIncompletedByType( UnassignEndpoint::class );

		if ( $task ) {
			$this->backgroundTaskService->delete( $task->getTaskId() );
		}
	}
}
