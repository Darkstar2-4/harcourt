<?php

namespace WPML\TM\Jobs\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\FP\Obj;
use WPML\LIB\WP\User;
use WPML\TM\API\Jobs;

class Resign implements IHandler {

	public function run( Collection $data ) {

		$jobs = \wpml_collect( $data->get( 'jobIds' ) )
			->map( Jobs::get() )
			->filter();

		if ( ! User::canManageTranslations() ) {
			$jobs = $jobs->filter( function ( $job ) {
				return (int) Obj::prop( 'translator_id', $job ) === get_current_user_id();
			} );
		}

		$result = $jobs
			->map( Obj::prop( 'job_id' ) )
			->map( Fns::tap( [ wpml_load_core_tm(), 'resign_translator' ] ) )
			->values()
			->toArray();

		return Either::of( $result );
	}
}
