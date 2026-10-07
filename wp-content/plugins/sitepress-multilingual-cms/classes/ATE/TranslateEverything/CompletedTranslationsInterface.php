<?php

namespace WPML\TM\ATE\TranslateEverything;

interface CompletedTranslationsInterface {

	public function isEverythingProcessed( $cached );

	public function markEverythingAsCompleted();


	public function markEverythingAsUncompleted();

	public function markLanguagesAsCompleted( array $languages );

	public function markLanguagesAsUncompleted( array $languages );
}
