<?php

namespace WPML\TM\API;


use WPML\FP\Curryable;
use WPML\FP\Fns;
use WPML\FP\Obj;
use WPML\TM\Jobs\Dispatch\Messages;
use WPML\Translation\CancelJobsServiceFactory;

class Batch {

	use Curryable;

	public static function init() {

		self::curryN( 'rollback', 1, function ( $basketName ) {
			$batchId = \WPML_Translation_Basket::get_batch_id_from_name( $basketName );

			if ( $batchId ) {
				$cancelJobsService = CancelJobsServiceFactory::create();
				$cancelJobsService->cancelJobsInBatch( $batchId );
			}

			\TranslationProxy_Basket::set_batch_data( null );
			icl_cache_clear();
		} );


	}

	public static function sendStrings( Messages $messages, $batch ) {
		$dispatchActions = function ( $batch ) {
			do_action( 'wpml_tm_send_st-batch_jobs', $batch, 'st-batch' );
		};

		self::send( $dispatchActions, [ $messages, 'showForStrings' ], $batch );
	}

	private static function send( callable $dispatchAction, callable $displayErrors, $batch ) {
		$dispatchAction( $batch );

		$errors = wpml_load_core_tm()->messages_by_type( 'error' );

		if ( $errors ) {
			self::rollback( $batch->get_basket_name() );

			$displayErrors( Fns::map( Obj::prop( 'text' ), $errors ), 'error' );
		}
	}
}

Batch::init();
