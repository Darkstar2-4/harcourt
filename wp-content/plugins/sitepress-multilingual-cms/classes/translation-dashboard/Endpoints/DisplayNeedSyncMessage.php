<?php

namespace WPML\TM\TranslationDashboard\Endpoints;

use WPML\Collect\Support\Collection;
use WPML\FP\Either;

class DisplayNeedSyncMessage {

	public function run( Collection $data ) {
		$postIds = $data->get( 'postIds', [] );
		do_action( 'wpml_new_duplicated_terms', $postIds );

		return Either::of( $postIds );
	}
}
