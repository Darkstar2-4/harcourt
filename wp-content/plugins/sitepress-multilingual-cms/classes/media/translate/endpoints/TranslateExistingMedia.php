<?php

namespace WPML\Media\Translate\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use function WPML\Container\make;
use WPML\FP\Left;
use WPML\FP\Right;
use WPML\LIB\WP\User;

class TranslateExistingMedia implements IHandler {
	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() && ! User::hasCap( 'wpml_manage_media_translation' ) ) {
			return Left::of( 'Insufficient permissions' );
		}

		return Right::of(
			make( \WPML_Media_Attachments_Duplication::class )->batch_translate_media( false )
		);
	}
}
