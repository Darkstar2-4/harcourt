<?php

namespace WPML\Setup\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\LIB\WP\User;
use WPML\Setup\Option;
use function WPML\Container\make;

class EnableAte implements IHandler {

	public function run( Collection $data ) {
		if ( ! User::hasCap( 'wpml_manage_languages' ) ) {
			return Either::left( 'Insufficient permissions' );
		}

		if ( ! Option::isTMAllowed() ) {
			return Either::left( __( 'The user does not have a proper license to enable ATE.', 'sitepress' ) );
		}

		if ( \WPML_TM_ATE_Status::is_enabled_and_activated() ) {
			return Either::of( true );
		}

		return make( \WPML\TM\ATE\AutoTranslate\Endpoint\EnableATE::class )
			->enable()
			->map( Fns::tap( [ Option::class, 'setTranslateEverythingDefault' ] ) );
	}
}
