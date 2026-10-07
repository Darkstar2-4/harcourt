<?php

namespace WPML\TM\ATE\Loader\MarkPreviouslyUnsupportedContentAsCompletedInTEA;

use WPML\API\PostTypes;
use WPML\TM\ATE\TranslateEverything\UntranslatedPosts;

class PostTypesMigration {

	private $untranslatedPosts;

	private $executionStatus;


	public function __construct(
		UntranslatedPosts $untranslatedPosts,
		ExecutionStatus $executionStatus
	) {
		$this->untranslatedPosts = $untranslatedPosts;
		$this->executionStatus   = $executionStatus;
	}

	public function run() {
		$postTypes = PostTypes::getDisplayAsTranslated();

		foreach ( $postTypes as $postType ) {
			$this->untranslatedPosts->markTypeAsCompleted( $postType );
		}

		$this->executionStatus->markPostTypesAsExecuted();
	}

}
