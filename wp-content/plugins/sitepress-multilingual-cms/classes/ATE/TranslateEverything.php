<?php

namespace WPML\TM\ATE;

use WPML\Collect\Support\Collection;
use WPML\Element\API\Languages;
use WPML\FP\Fns;
use WPML\FP\Left;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Right;
use WPML\LIB\WP\User;
use WPML\Media\Option as MediaOption;
use WPML\Setup\Option;
use WPML\TM\ATE\AutomaticTranslationCapabilities;
use WPML\TM\ATE\TranslateEverything\CompletedTranslationsInterface;
use WPML\TM\ATE\TranslateEverything\UntranslatedElementsInterface;
use WPML\TM\ATE\TranslateEverything\UntranslatedPackages;
use WPML\TM\ATE\TranslateEverything\UntranslatedPosts;
use WPML\Core\Component\Translation\Domain\Priority\Tier;
use WPML\TM\AutomaticTranslation\Actions\Actions;
use WPML\TM\Jobs\JobLog;
use WPML\Utilities\KeyedLock;
use function WPML\Container\make;

class TranslateEverything implements CompletedTranslationsInterface {

	private $untranslated_elements = [];

	const LOCK_RELEASE_TIMEOUT = 2 * MINUTE_IN_SECONDS;

	public function __construct( UntranslatedPosts $untranslated_posts, UntranslatedPackages $untranslated_packages ) {
		$this->untranslated_elements = [
			$untranslated_packages,
			$untranslated_posts,
		];

		$this->untranslated_elements = apply_filters(
			'wpml_translate_everything_untranslated_elements_strategies',
			$this->untranslated_elements
		);

		$this->untranslated_elements = Fns::filter( function ( $strategy ) {
			return $strategy instanceof UntranslatedElementsInterface;
		}, $this->untranslated_elements );

		$this->untranslated_elements = $this->sortStrategiesByTier( $this->untranslated_elements );
	}

	public function run(
		Collection $data,
		Actions $actions
	) {
		if ( ! User::canManageTranslations() ) {
			return Left::of( [ 'error' => 'insufficient-permissions' ] );
		}

		JobLog::maybeInitRequest();
		JobLog::createNewGroup(
			JobLog::GROUP_ID_TRANSLATE_EVERYTHING,
			'Translate Everything loop iteration',
			[ 'inputKey' => $data->get( 'key' ) ]
		);

		try {
			if ( ! MediaOption::isSetupFinished() ) {
				JobLog::addError( 'tea_run_aborted_media_setup', [] );
				return Left::of( [ 'key' => 'media-setup-not-finished' ] );
			}

			if ( ! AutomaticTranslationCapabilities::doesDefaultLanguageSupport() ) {
				JobLog::addError( 'tea_run_aborted_default_lang', [] );
				return Left::of( [ 'error' => 'default-language-does-not-support-automatic-translations' ] );
			}

			$lock = make( KeyedLock::class, [ ':name' => self::class ] );
			$key  = $lock->create( $data->get( 'key' ), self::LOCK_RELEASE_TIMEOUT );

			if ( ! $key ) {
				JobLog::add( 'tea_lock_busy_skipping', [] );
				return Left::of( [ 'key' => 'in-use' ] );
			}

			JobLog::add( 'tea_lock_acquired', [] );

			$createdJobs = [];
			if ( Option::shouldTranslateEverything() ) {
				$createdJobs = $this->translateEverything( $actions );
			} else {
				JobLog::add( 'tea_skipped_option_off', [] );
			}

			$everythingProcessed = $this->isEverythingProcessed();
			JobLog::add( 'tea_iteration_done', [
				'jobs_created_count'   => is_array( $createdJobs ) ? count( $createdJobs ) : 0,
				'everything_processed' => $everythingProcessed,
			] );

			if ( $everythingProcessed || ! Option::shouldTranslateEverything() ) {
				$lock->release();
				JobLog::add( 'tea_completed_or_disabled_releasing_lock', [] );
				$key = false;
			}

			return Right::of( [ 'key' => $key, 'createdJobs' => $createdJobs ] );
		} finally {
			JobLog::finishCurrentGroup();
		}
	}

	private function translateEverything( Actions $actions ) {
		foreach ( $this->untranslated_elements as $untranslated ) {
			JobLog::addExtraLogData( 'strategy', get_class( $untranslated ) );

			try {
				while ( ! $untranslated->isEverythingProcessed() ) {
					list( $types, $languages ) = $untranslated->getTypeWithLanguagesToProcess();
					if ( ! $types || ! $languages ) {
						JobLog::add( 'tea_strategy_skipped_no_types_or_langs', [] );
						continue;
					}

					$queueSize = $untranslated->getQueueSize();
					$elements  = $untranslated->getElementsToProcess( $languages, $types, $queueSize + 1 );

					JobLog::add( 'tea_strategy_batch', [
						'types'            => $types,
						'languages'        => $languages,
						'queue_size'       => $queueSize,
						'elements_found'   => count( $elements ),
						'will_create_jobs' => count( $elements ) > 0,
					] );

					if ( count( $elements ) <= $queueSize ) {
						$untranslated->markTypeAsCompleted( $types );
						JobLog::add( 'tea_strategy_type_completed', [ 'types' => $types ] );
					}

					if ( count( $elements ) ) {
						$created = $untranslated->createTranslationJobs( $actions, Lst::slice( 0, $queueSize, $elements ), $types );
						JobLog::add( 'tea_strategy_jobs_created', [
							'created_count' => is_array( $created ) ? count( $created ) : 0,
						] );
						return $created;
					}
				}
			} finally {
				JobLog::removeExtraLogData( 'strategy' );
			}
		}

		return [];
	}


	public function isEverythingProcessed( $cached = false ) {
		foreach ( $this->untranslated_elements as $untranslated ) {
			if ( ! $untranslated->isEverythingProcessed( $cached ) ) {
				return false;
			}
		}

		return true;
	}

	public function markEverythingAsCompleted() {
		foreach ( $this->untranslated_elements as $untranslated ) {
			$untranslated->markEverythingAsCompleted();
		}
	}


	public function markEverythingAsUncompleted() {
		foreach ( $this->untranslated_elements as $untranslated ) {
			$untranslated->markEverythingAsUncompleted();
		}
	}

	public function markLanguagesAsCompleted( array $languages ) {
		foreach ( $this->untranslated_elements as $untranslated ) {
			$untranslated->markLanguagesAsCompleted( $languages );
		}
	}

	public function markLanguagesAsUncompleted( array $languages ) {
		foreach ( $this->untranslated_elements as $untranslated ) {
			$untranslated->markLanguagesAsUncompleted( $languages );
		}
	}

	private function sortStrategiesByTier( array $strategies ): array {
		usort(
			$strategies,
			function ( UntranslatedElementsInterface $a, UntranslatedElementsInterface $b ): int {
				return self::getStrategyTier( $a ) <=> self::getStrategyTier( $b );
			}
		);
		return $strategies;
	}

	private static function getStrategyTier( UntranslatedElementsInterface $strategy ): int {
		if ( $strategy instanceof UntranslatedPosts ) {
			return Tier::PAGES_UNDER_HOMEPAGE;
		}
		return Tier::STRINGS;
	}
}