<?php

namespace WPML\Media\Translate\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Left;
use WPML\FP\Obj;
use WPML\LIB\WP\User;
use WPML\Media\Option;
use function WPML\Container\make;
use WPML\FP\Right;

class DuplicateFeaturedImages implements IHandler {
	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() && ! User::hasCap( 'wpml_manage_media_translation' ) ) {
			return Left::of( 'Insufficient permissions' );
		}

		if ( ! $this->shouldDuplicateFeaturedImages() ) {
			return Right::of( 0 );
		}

		$numberLeft = $data->get( 'remaining', null );

		return Right::of(
			make( \WPML_Media_Attachments_Duplication::class )->batch_duplicate_featured_images( false, $numberLeft )
		);
	}

	private function shouldDuplicateFeaturedImages() {
		return (bool) Obj::prop( 'duplicate_featured', Option::getNewContentSettings() );
	}
}
