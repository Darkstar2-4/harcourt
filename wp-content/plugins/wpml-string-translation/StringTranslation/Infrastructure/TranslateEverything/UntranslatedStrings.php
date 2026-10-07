<?php

namespace WPML\StringTranslation\Infrastructure\TranslateEverything;

use WPML\API\PostTypes;
use WPML\Core\Component\Translation\Application\String\Repository\StringBatchRepositoryInterface;
use WPML\Element\API\Languages;
use WPML\FP\Cast;
use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\Setup\Option;
use WPML\TM\API\ATE\CachedLanguageMappings;
use WPML\TM\API\ATE\LanguageMappings;
use WPML\TM\ATE\TranslateEverything\UntranslatedElementsInterface;
use WPML\TM\AutomaticTranslation\Actions\Actions;

class UntranslatedStrings implements UntranslatedElementsInterface {

	const ENGLISH_SOURCE_LANGUAGE = 'en';

	private $wpdb;

	private $stringBatchRepository;

	public function __construct( StringBatchRepositoryInterface $stringBatchRepository, ?\wpdb $wpdb = null ) {
		$this->stringBatchRepository = $stringBatchRepository;

		if ( ! $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	public function getTypeWithLanguagesToProcess() {
		$completed             = $this->getCompleted();
		$notCompletedLanguages = array_diff( $this->getEligibleLanguageCodes(), $completed );

		return [ 'string', $notCompletedLanguages ];
	}

	public function getElementsToProcess( $languages, $type, $queueSize ) {
		$languageSelect    = implode(
			' UNION ALL ',
			array_fill( 0, count( $languages ), 'SELECT %s AS code' )
		);
		$languageCrossJoin = "
			CROSS JOIN (
				$languageSelect	
			) as langs
		";

		$sql = "
			SELECT strings.id, langs.code AS language_code 
			FROM {$this->wpdb->prefix}icl_strings strings
			{$languageCrossJoin}
			LEFT JOIN {$this->wpdb->prefix}icl_string_translations translations
				ON strings.id = translations.string_id AND translations.language = langs.code
			WHERE strings.string_type = 1 
				AND ( translations.status IS NULL OR translations.status = 0 )
				AND EXISTS (
	        SELECT 1
	        FROM {$this->wpdb->prefix}icl_string_positions positions
	        WHERE positions.string_id = strings.id
	          AND positions.kind = %d
	    	) AND strings.language = %s
			ORDER BY langs.code, strings.id ASC 
			LIMIT %d
		";

		$sql = $this->wpdb->prepare(
			$sql,
			array_merge(
				array_values( $languages ),
				[
					ICL_STRING_TRANSLATION_STRING_TRACKING_TYPE_FRONTEND,
					self::ENGLISH_SOURCE_LANGUAGE,
					$queueSize,
				]
			)
		);

		$rowset = $this->wpdb->get_results( $sql, ARRAY_N );

		return Fns::map(
			function ( $row ) {
				return [ (int) $row[0], $row[1] ];
			},
			$rowset
		);
	}

	public function createTranslationJobs( Actions $actions, array $elements, $type ) {
		$stringsGroupedByLanguages = \wpml_collect( $elements )
			->groupBy( 1 )
			->map( Lst::pluck( 0 ) )
			->map( Fns::map( Cast::toInt() ) )
			->toArray();

		$batchElements = [];
		foreach ( $stringsGroupedByLanguages as $languageCode => $strings ) {
			$batchId = $this->stringBatchRepository->create(
				'translate everything|string|' . $languageCode,
				$strings,
				self::ENGLISH_SOURCE_LANGUAGE
			);

			$batchElements[] = [ $batchId, $languageCode ];
		}

		$jobs = $actions->createNewTranslationJobs( 'en', $batchElements, 'st-batch' );

		$jobsPerString = [];
		foreach ( $jobs as $job ) {
			$stringsInJobLanguages = $stringsGroupedByLanguages[ $job['lang'] ];

			foreach ( $stringsInJobLanguages as $string ) {
				$jobsPerString[] = [
					'elementId'   => $string,
					'lang'        => $job['lang'],
					'elementType' => 'string',
					'jobId'       => $job['jobId'],
				];
			}
		}

		return $jobsPerString;
	}

	public function isEverythingProcessed( $cached = false ) {
		$completed = $this->getCompleted();

		return count( array_diff( $this->getEligibleLanguageCodes( $cached ), $completed ) ) === 0;
	}

	public function getQueueSize(): int {
		return 150;
	}

	public function getEligibleLanguageCodes( bool $cached = false ): array {
		$languageMapper = $cached ? CachedLanguageMappings::class : LanguageMappings::class;

		$targetLanguages = $languageMapper::geCodesEligibleForAutomaticTranslations();

		$targetLanguages = $this->maybeAppendDefaultLanguage( $languageMapper, $targetLanguages );

		$targetLanguages = $this->removeEnglishFromTargetLanguages( $targetLanguages );

		return $targetLanguages;
	}

	private function getTargetLanguages(): array {
		$targetLanguages = Languages::getSecondaryCodes();

		if ( Languages::getDefaultCode() !== self::ENGLISH_SOURCE_LANGUAGE ) {
			$primary         = [ Languages::getDefaultCode() ];
			$targetLanguages = array_merge( $targetLanguages, $primary );
		}

		$targetLanguages = $this->removeEnglishFromTargetLanguages( $targetLanguages );

		return $targetLanguages;
	}

	public function markTypeAsCompleted( string $type ) {
		$this->setCompleted( $this->getTargetLanguages() );
	}

	public function markEverythingAsCompleted() {
		$this->setCompleted( $this->getTargetLanguages() );
	}

	public function markEverythingAsUncompleted() {
		$this->setCompleted( [] );
	}

	public function markLanguagesAsCompleted( array $languages ) {
		$completed = $this->getCompleted();
		$completed = array_merge( $completed, $languages );
		$this->setCompleted( $completed );
	}

	public function markLanguagesAsUncompleted( array $languages ) {
		$completed = $this->getCompleted();
		$completed = array_diff( $completed, $languages );
		$this->setCompleted( $completed );
	}

	private function getCompleted() {
		return Option::getTranslateEverythingCompletedStrings();
	}

	private function setCompleted( array $completed ) {
		Option::setTranslateEverythingCompletedStrings( $completed );
	}

	private function removeEnglishFromTargetLanguages( array $targetLanguages ): array {
		$targetLanguages = array_filter(
			$targetLanguages,
			function ( $languageCode ) {
				return $languageCode !== self::ENGLISH_SOURCE_LANGUAGE;
			}
		);

		return $targetLanguages;
	}

	private function maybeAppendDefaultLanguage( string $languageMapper, array $targetLanguages ): array {
		if ( Languages::getDefaultCode() !== self::ENGLISH_SOURCE_LANGUAGE && $languageMapper::doesDefaultLanguageSupportAutomaticTranslations() ) {
			$targetLanguages[] = Languages::getDefaultCode();
		}

		return $targetLanguages;
	}
}
