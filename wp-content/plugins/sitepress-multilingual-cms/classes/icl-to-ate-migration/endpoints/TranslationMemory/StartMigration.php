<?php

namespace WPML\ICLToATEMigration\Endpoints\TranslationMemory;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML_TM_ATE_API;



class StartMigration implements IHandler {
	
	private $apiClient;
	
	public function __construct( WPML_TM_ATE_API $apiClient ) {
		$this->apiClient = $apiClient;
	}
	
	public function run( Collection $data ) {
		
		
		return Either::of( [
		'started_at'           => null,
		'finished_at'          => null,
		'status'               => 'in_progress',
		'last_imported_icl_id' => 15,
		'imported_count'       => 4
		] );
	}
}
