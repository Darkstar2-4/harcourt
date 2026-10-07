<?php

namespace WPML\TM\ATE\TranslateEverything;

use WPML\API\PostTypes;
use WPML\Element\API\Languages;
use WPML\FP\Lst;
use WPML\Setup\Option;
use WPML\TM\API\ATE\CachedLanguageMappings;
use WPML\TM\API\ATE\LanguageMappings;

abstract class AbstractUntranslatedElements implements UntranslatedElementsInterface {

	protected $wpdb;

	private $oldJobsEditor;

	public function __construct( \wpdb $wpdb, ?\WPML_TM_Old_Jobs_Editor $oldJobsEditor = null ) {
		$this->wpdb = $wpdb;

		if ( $oldJobsEditor ) {
			$this->oldJobsEditor = $oldJobsEditor;
		} else {
			$this->oldJobsEditor = \wpml_tm_load_old_jobs_editor();
		}
	}

	public function getQueueSize(): int {
		return 15;
	}

	public function getEligibleLanguageCodes( bool $cached = false ): array {
		$mapper = $cached ? CachedLanguageMappings::class : LanguageMappings::class;

		return $mapper::geCodesEligibleForAutomaticTranslations();
	}

	public function isEverythingProcessed( $cached = false ) {
		$completed = $this->getCompleted();
		$languages = $this->getEligibleLanguageCodes( $cached );

		foreach ( $this->getTypes() as $type ) {
			$completedLanguages = $completed[ $type ] ?? [];
			$remainingLanguages = Lst::diff( $languages, $completedLanguages );
			if ( count( $remainingLanguages ) > 0 ) {
				return false;
			}
		}

		return true;
	}

	public function markTypeAsCompleted( string $type ) {
		$completed = $this->getCompleted();
		$languages          = Languages::getSecondaryCodes();
		$completed[ $type ] = array_merge( $completed[ $type ] ?? [], $languages );

		$this->setCompleted( $completed );
	}

	public function markEverythingAsCompleted() {
		$types     = $this->getTypes();
		$languages = Languages::getSecondaryCodes();
		$completed = $this->getCompleted();

		foreach ( $types as $type ) {
			$completed[ $type ] = array_merge( $completed[ $type ] ?? [], $languages );
		}

		$this->setCompleted( $completed );
	}


	public function markEverythingAsUncompleted() {
		$this->setCompleted( [] );
	}

	public function markLanguagesAsCompleted( array $languages ) {
		$types     = $this->getTypes();
		$completed = $this->getCompleted();

		foreach ( $types as $type ) {
			$completed[ $type ] = array_merge( $completed[ $type ] ?? [], $languages );
		}

		$this->setCompleted( $completed );
	}

	public function markLanguagesAsUncompleted( array $languages ) {
		$types     = $this->getTypes();
		$completed = $this->getCompleted();

		foreach ( $types as $type ) {
			$typeValues         = Lst::diff( $completed[ $type ] ?? [], $languages );
			$completed[ $type ] = is_array( $typeValues ) ? array_values( $typeValues ) : $typeValues;
		}

		$this->setCompleted( $completed );
	}

	abstract protected function getCompleted(): array;

	abstract protected function setCompleted( array $completed );

	abstract protected function getTypes(): array;

	protected function buildOldEditorCondition(): string {
		$oldEditorCondition = '';

		if ( $this->oldJobsEditor->editorForTranslationsPreviouslyCreatedUsingCTE() === \WPML_TM_Editors::WPML ) {
			$editor = \WPML_TM_Editors::WPML;
			$oldEditorCondition = "AND (
				translation_status.needs_update = 0 OR IFNULL(
					(
					  SELECT jobs.editor FROM {$this->wpdb->prefix}icl_translate_job jobs
					  WHERE jobs.job_id = (
					    SELECT MAX( job_id ) FROM {$this->wpdb->prefix}icl_translate_job
					    WHERE rid = translation_status.rid
					  )
					), 
					'ate'
				) != '{$editor}'
			)";
		}

		return $oldEditorCondition;
	}
}
