<?php

namespace WPML\TM\ATE\TranslateEverything;

use WPML\TM\AutomaticTranslation\Actions\Actions;

interface UntranslatedElementsInterface extends CompletedTranslationsInterface {

	public function getTypeWithLanguagesToProcess();

	public function getElementsToProcess( $languages, $type, $queueSize );


	public function createTranslationJobs( Actions $actions, array $elements, $type );

	public function getQueueSize(): int;


	public function getEligibleLanguageCodes( bool $cached = false ): array;

	public function markTypeAsCompleted( string $type );

}
