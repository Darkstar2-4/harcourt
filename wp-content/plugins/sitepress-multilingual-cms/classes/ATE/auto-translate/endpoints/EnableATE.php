<?php

namespace WPML\TM\ATE\AutoTranslate\Endpoint;

use WPML\Ajax\IHandler;
use WPML\API\Settings;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\LIB\WP\User;
use WPML\Setup\Option;
use WPML\TM\API\ATE\LanguageMappings;
use function WPML\Container\make;
use function WPML\FP\pipe;

class EnableATE implements IHandler {


	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		return $this->enable();
	}

	public function enable() {

		$cache = wpml_get_cache( \WPML_Translation_Roles_Records::CACHE_GROUP );
		$cache->flush_group_cache();

		$ateApi = make( \WPML_TM_AMS_API::class );
		$status = $ateApi->get_status();
		if ( Obj::propOr( false, 'activated', $status ) ) {
			$result = Either::right( true );
		} else {
			$amsUsers = make( \WPML_TM_AMS_Users::class );

			$amsApi = make( \WPML_TM_AMS_API::class );

			$saveLanguageMapping = Fns::tap( pipe(
				[ Option::class, 'getLanguageMappings' ],
				Logic::ifElse( Logic::isEmpty(), Fns::always( true ), [ LanguageMappings::class, 'saveMapping'] )
			) );

			$result = $amsApi->register_manager(
				User::getCurrent(),
				$amsUsers->get_translators(),
				$amsUsers->get_managers()
			)->map( $saveLanguageMapping );

			$ateApi->get_status();
		}

		$setTranslationMethod = Fns::tap( function () {
			Settings::assoc( 'translation-management', 'doc_translation_method', ICL_TM_TMETHOD_ATE );
		} );

		return $result->map( Fns::tap( [ make( \WPML_TM_AMS_Synchronize_Actions::class ), 'synchronize_translators' ] ) )
									->map( $this->confirmSiteKey() )
									->map( $setTranslationMethod )
		              ->bimap(
		              	$this->formatError(),
		              	Fns::identity()
		              );
	}

	private function confirmSiteKey() {
		return Fns::tap(function() {
			$confirmationService = make( \WPML\TM\ATE\Sitekey\SitekeyConfirmationService::class );
			$confirmationService->confirm();
		});
	}

	private function formatError() {
		return function( $errorData ) {
			return [ 'error' => $errorData ];
		};
	}
}
