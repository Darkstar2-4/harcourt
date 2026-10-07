<?php

namespace WPML\TM\ATE\AutoTranslate\Endpoint;

use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\Settings\PostType\Automatic;
use WPML\Setup\Option;
use WPML\Element\API\Languages;
use WPML\TM\ATE\TranslateEverything\UntranslatedPosts;

class SetForPostType {

	private $untranslatedPosts;

	public function __construct( UntranslatedPosts $untranslatedPosts ) {
		$this->untranslatedPosts = $untranslatedPosts;
	}

	public function run( Collection $data ) {
		$postTypes = $data->get( 'postTypes' );
		$onlyNew   = (bool) $data->get( 'onlyNew' );

		foreach ( $postTypes as $type ) {
			if ( $onlyNew ) {
				$this->untranslatedPosts->markTypeAsCompleted( $type );
			} else {
				$this->untranslatedPosts->markPostTypeAsUncompleted( $type );
			}
		}

		return Either::of( true );
	}
}
