<?php

namespace WPML\TM\Upgrade\Commands\SynchronizeSourceIdOfATEJobs;

use WPML\Utils\Pager;
use WPML\TM\Upgrade\Commands\MigrateAteRepository;
use WPML\Collect\Support\Collection;
use WPML\Upgrade\CommandsStatus;
use function WPML\Container\make;

class Command implements \IWPML_Upgrade_Command {

	const CHUNK_SIZE = 1000;

	private $repository;

	private $api;

	private $pager;

	private $commandStatus;

	private $result = false;

	public function __construct(
		Repository $repository,
		\WPML_TM_ATE_API $api,
		Pager $pager,
		CommandsStatus $commandStatus
	) {
		$this->repository    = $repository;
		$this->api           = $api;
		$this->pager         = $pager;
		$this->commandStatus = $commandStatus;
	}


	public function run_admin() {
		if ( ! $this->hasBeenMigrateATERepositoryUpgradeRun() ) {
			return false;
		}

		$chunks       = $this->repository->getPairs()->chunk( self::CHUNK_SIZE );
		$this->result = $this->pager->iterate(
			$chunks,
			function ( Collection $pairs ) {
				return $this->api->migrate_source_id( $pairs->toArray() );
			}
		) === 0;

		return $this->result;
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return $this->result;
	}

	private function hasBeenMigrateATERepositoryUpgradeRun() {
		return $this->commandStatus->hasBeenExecuted( MigrateAteRepository::class );
	}
}
