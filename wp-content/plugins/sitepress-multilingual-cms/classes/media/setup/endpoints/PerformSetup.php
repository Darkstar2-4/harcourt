<?php


namespace WPML\Media\Setup\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;

use WPML\FP\Right;
use WPML\FP\Left;

use WPML\LIB\WP\User;

use function WPML\Container\make;

class PerformSetup implements IHandler {
	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() && ! User::hasCap( 'wpml_manage_media_translation' ) ) {
			return Left::of( 'Insufficient permissions' );
		}

		if ( ! defined( 'WPML_MEDIA_VERSION' ) || ! class_exists( 'WPML_Media_Set_Posts_Media_Flag_Factory' ) ) {
			return Left::of( [ 'key' => false ] );
		}

		$offset    = $data->get( 'offset' );
		$mediaFlag = make( \WPML_Media_Set_Posts_Media_Flag_Factory::class )->create();
		list ( , $newOffset, $continue ) = $mediaFlag->process_batch( $offset );

		return Right::of( [ 'continue' => $continue, 'offset' => $newOffset, ] );
	}
}
