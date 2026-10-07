<?php

namespace WPML\TM\AutomaticTranslation\Actions;

use WPML\Element\API\Languages;
use WPML\FP\Cast;
use WPML\FP\Debug;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Str;
use WPML\LIB\WP\Hooks;
use WPML\LIB\WP\Post;
use WPML\Settings\PostType\Automatic;
use WPML\TM\API\ATE\LanguageMappings;
use WPML\TM\API\Job\Map;
use function WPML\Container\make;
use function WPML\FP\invoke;
use WPML\LIB\WP\User;
use WPML\Setup\Option;
use WPML\Infrastructure\WordPress\Component\StringPackage\Application\Query\PackageDefinitionQuery;

use function WPML\FP\partial;
use WPML\TM\API\Jobs;
use WPML\TM\Jobs\JobLog;
use function WPML\FP\pipe;
use function WPML\FP\spreadArgs;

class Actions implements \IWPML_Action {

	const PRIORITY_AFTER_PB_PROCESS = 100;

	private $translationElementFactory;

	private $packageDefinitionQuery;

	public function __construct(
		\WPML_Translation_Element_Factory $translationElementFactory,
		$packageDefinitionQuery = null
	) {
		$this->translationElementFactory = $translationElementFactory;
		$this->packageDefinitionQuery    = $packageDefinitionQuery ?: new PackageDefinitionQuery();
	}

	public function add_hooks() {
		Hooks::onAction( 'wpml_after_save_post', 100 )
		     ->then( spreadArgs( Fns::memorize( [ $this, 'sendToTranslation' ] ) ) );
		Hooks::onAction( 'wpml_st_package_string_registered' )
			->then( spreadArgs( [ $this, 'sendPackageToTranslation' ] ) );
	}

	public function sendToTranslation( $postId, $onComplete = null ) {
		$execOnComplete = function () use ( $postId, $onComplete ) {
			if ( is_callable( $onComplete ) ) {
				$onComplete( $postId );
			}
		};

		if ( empty( $_POST['icl_minor_edit'] ) ) {
			$postElement = $this->translationElementFactory->create_post( $postId );
			if ( $postElement->is_translatable() && Automatic::isAutomatic( $postElement->get_type() ) ) {
				Hooks::onAction( 'shutdown', self::PRIORITY_AFTER_PB_PROCESS )
				     ->then( function () use ( $postId, $execOnComplete ) {
					     JobLog::maybeInitRequest();
					     JobLog::createNewGroup(
						     JobLog::GROUP_ID_TRANSLATE_EVERYTHING,
						     'Auto-translate on save (TEA refill / per-post)',
						     [ 'post_id' => $postId ]
					     );
					     JobLog::addExtraLogData( 'trigger', current_action() ?: 'shutdown' );
					     JobLog::addExtraLogData( 'post_id', $postId );

					     try {
						     $postElement           = $this->translationElementFactory->create_post( $postId );
						     $postStatus            = $postElement->get_wp_object()->post_status;
						     $sourceLang            = $postElement->get_source_language_code();
						     $isOriginal            = $sourceLang === null;
						     $isDefaultLang         = $postElement->get_language_code() === Languages::getDefaultCode();
						     $isPublish             = 'publish' === $postStatus;
						     $isDraftOk             = 'draft' === $postStatus && \WPML\Setup\Option::getTranslateEverythingDrafts();
						     $excluded = apply_filters( 'wpml_exclude_post_from_auto_translate', false, $postId );

						     if (
							     ( $isPublish || $isDraftOk )
							     && $isDefaultLang
							     && $isOriginal
							     && ! $excluded
						     ) {
							     $secondaryLanguageCodes = LanguageMappings::geCodesEligibleForAutomaticTranslations();

							     JobLog::add( 'auto_translate_eligible', [
								     'post_status'      => $postStatus,
								     'target_languages' => $secondaryLanguageCodes,
								     'lang_count'       => Lst::length( $secondaryLanguageCodes ),
							     ] );

							     if ( ! Lst::length( $secondaryLanguageCodes ) ) {
								     JobLog::addError( 'auto_translate_no_eligible_languages', [ 'post_id' => $postId ] );
								     do_action( 'wpml_update_failed_jobs_notice', $postElement );
							     }

							     $this->cancelExistingTranslationJobs( $postElement, $secondaryLanguageCodes );
							     $this->createTranslationJobs( $postElement, $secondaryLanguageCodes );
							     JobLog::add( 'auto_translate_jobs_dispatched', [
								     'languages' => $secondaryLanguageCodes,
							     ] );
						     } else {
							     JobLog::add( 'auto_translate_skipped_not_eligible', [
								     'post_status'              => $postStatus,
								     'is_original'              => $isOriginal,
								     'source_lang_code'         => $sourceLang,
								     'is_default_lang'          => $isDefaultLang,
								     'excluded'                 => (bool) $excluded,
							     ] );
						     }

						     $execOnComplete();
					     } finally {
						     JobLog::removeExtraLogData( 'trigger' );
						     JobLog::removeExtraLogData( 'post_id' );
						     JobLog::finishCurrentGroup();
					     }
				     } );
			} else {
				$execOnComplete();
			}
		} else {
			$execOnComplete();
		}
	}

	public function sendPackageToTranslation( $package ) {
		static $updatedPackages = [];

		if ( ! $package || ! Obj::prop( 'ID', $package ) ) {
			return;
		}

		if ( isset( $updatedPackages[ $package->ID ] ) ) {
			return;
		}

		$updatedPackages[ $package->ID ] = true;

    $shouldTranslate = $this->packageDefinitionQuery->isPackageOnTheList( $package->kind_slug );

		$afterFilter = apply_filters( 'wpml_auto_translate_string_package', $shouldTranslate, (array) $package );

		if ( $afterFilter ) {
			Hooks::onAction( 'shutdown' )
				->then( $this->getPackageHandler( $package ) );
			return;
		}

		JobLog::maybeInitRequest();
		JobLog::createNewGroup(
			JobLog::GROUP_ID_TRANSLATE_EVERYTHING,
			'String package auto-translate skipped',
			[ 'package_id' => $package->ID, 'kind_slug' => $package->kind_slug ?? null ]
		);
		JobLog::add( 'auto_translate_package_skipped_by_decision', [
			'on_list'      => (bool) $shouldTranslate,
			'after_filter' => (bool) $afterFilter,
		] );
		JobLog::finishCurrentGroup();
	}

	private function getPackageHandler( \WPML_Package $package ) {
		return function() use ( $package ) {
			JobLog::maybeInitRequest();
			JobLog::createNewGroup(
				JobLog::GROUP_ID_TRANSLATE_EVERYTHING,
				'Auto-translate on save (string package)',
				[
					'package_id' => JobLog::safeProp( $package, 'ID' ),
					'kind_slug'  => JobLog::safeProp( $package, 'kind_slug' ),
				]
			);
			JobLog::addExtraLogData( 'trigger', current_action() ?: 'shutdown' );
			JobLog::addExtraLogData( 'package_id', JobLog::safeProp( $package, 'ID' ) );

			try {
				$packageElement  = $this->translationElementFactory->create_package( $package->ID, $package->kind_slug );
				$sourceLang      = JobLog::safeCall( $packageElement, 'get_source_language_code' );

				if ( $packageElement->get_language_code() === Languages::getDefaultCode() && $sourceLang === null ) {
					$secondaryLanguageCodes = LanguageMappings::geCodesEligibleForAutomaticTranslations();

					JobLog::add( 'auto_translate_package_eligible', [
						'target_languages' => $secondaryLanguageCodes,
						'lang_count'       => Lst::length( $secondaryLanguageCodes ),
					] );


					$this->cancelExistingTranslationJobs( $packageElement, $secondaryLanguageCodes );
					$this->createTranslationJobs( $packageElement, $secondaryLanguageCodes );
					JobLog::add( 'auto_translate_package_jobs_dispatched', [
						'languages' => $secondaryLanguageCodes,
					] );
				} else {
					JobLog::add( 'auto_translate_package_skipped_not_original', [
						'source_lang_code'  => $sourceLang,
					] );
				}
			} finally {
				JobLog::removeExtraLogData( 'trigger' );
				JobLog::removeExtraLogData( 'package_id' );
				JobLog::finishCurrentGroup();
			}
		};
	}

	private function cancelExistingTranslationJobs( \WPML_Translation_Element $translationElement, $languages ) {
		$getJobEntity = function ( $jobId ) use ( $translationElement ) {
			return wpml_tm_get_jobs_repository()->get_job( Map::fromJobId( $jobId ), $translationElement->get_element_type() );
		};

		wpml_collect( $languages )
			->map( Jobs::getElementJob( $translationElement->get_element_id(), $translationElement->get_wpml_element_type() ) )
			->filter()
			->reject( self::isCompleteAndUpToDateJob() )
			->map( Obj::prop( 'job_id' ) )
			->map( Jobs::clearReviewStatus() )
			->map( Jobs::setNotTranslatedStatus() )
			->map( Jobs::clearTranslated() )
			->map( $getJobEntity )
			->map( Fns::tap( partial( 'do_action', 'wpml_tm_job_cancelled' ) ) );
	}

	private static function isCompleteAndUpToDateJob() {
		return function ( $job ) {
			return Cast::toInt( $job->needs_update ) !== 1 && Cast::toInt( $job->status ) === ICL_TM_COMPLETE;
		};
	}

	public function createTranslationJobs( \WPML_Translation_Element $translationElement, $targetLanguages ) {
		if ( ! Option::shouldTranslateEverything() ) {
			return;
		}

		$isNotCompleteAndUpToDate      = Logic::complement( self::isCompleteAndUpToDateJob() );
		$isPostElementAndUsingTmEditor = $this->isPostElementAndUsingNativeEditor( $translationElement );

		$sendToTranslation = function ( $language ) use (
			$translationElement,
			$isNotCompleteAndUpToDate,
			$isPostElementAndUsingTmEditor
		) {
			$job = Jobs::getElementJob( $translationElement->get_element_id(), $translationElement->get_wpml_element_type(), $language );

			if (
				$isPostElementAndUsingTmEditor
				&& (
					! $job
					|| (
						$isNotCompleteAndUpToDate( $job )
						&& $this->canJobBeReTranslatedAutomatically( $job->job_id )
					)
				)
			) {
				$this->createJob( $translationElement, $language );
			}
		};

		Fns::map( $sendToTranslation, $targetLanguages );
	}

	private function canJobBeReTranslatedAutomatically( $jobId ) {
		$wpmlTmLoadOldJobsEditor = wpml_tm_load_old_jobs_editor();
		$editorForOldJobs        = $wpmlTmLoadOldJobsEditor->get( $jobId );
		$currentJobEditor        = $wpmlTmLoadOldJobsEditor->get_current_editor( $jobId );

		return $editorForOldJobs === \WPML_TM_Editors::ATE || $currentJobEditor === \WPML_TM_Editors::WP;
	}

	private function createJob( \WPML_Translation_Element $translationElement, $language ) {
		$batch = new \WPML_TM_Translation_Batch(
			[
				new \WPML_TM_Translation_Batch_Element(
					$translationElement->get_element_id(),
					$translationElement->get_element_type(),
					$translationElement->get_language_code(),
					[ $language => 1 ]
				),
			],
			\TranslationProxy_Batch::get_generic_batch_name( true ),
			[ $language => User::getCurrentId() ]
		);

		wpml_load_core_tm()->send_jobs( $batch, $translationElement->get_element_type(), Jobs::SENT_AUTOMATICALLY );
	}


	public function createNewTranslationJobs( $sourceLanguage, array $elements, $elementType ) {
		$getTargetLang      = Lst::nth( 1 );
		$setTranslateAction = Obj::objOf( Fns::__, \TranslationManagement::TRANSLATE_ELEMENT_ACTION );
		$setTranslatorId    = Obj::objOf( Fns::__, User::getCurrentId() );

		$wpmlType = 'post';
		if ( $elementType === 'st-batch' ) {
			$wpmlType = 'st-batch';
		} else if ( Str::startsWith( 'package_', $elementType ) ) {
			$wpmlType = 'package';
		}

		if ( 'post' === $wpmlType ) {
			$postIds   = array_unique( array_map( 'intval', array_column( $elements, 0 ) ) );
			$ordering  = \WPML\Translation\AteSyncOrderingServiceFactory::create()
				->getOrderingPayloadArrayForPosts( $postIds );
			$positions = $ordering['positions'] ?? [];
			if ( ! empty( $positions ) ) {
				usort(
					$elements,
					function ( $a, $b ) use ( $positions ) {
						$posA = $positions[ (string) $a[0] ] ?? PHP_INT_MAX;
						$posB = $positions[ (string) $b[0] ] ?? PHP_INT_MAX;
						return $posA - $posB;
					}
				);
			}
		}

		$targetLanguages = \wpml_collect( $elements )
			->map( $getTargetLang )
			->unique()
			->mapWithKeys( $setTranslatorId )
			->toArray();

		$makeBatchElement = function ( $targetLanguages, $postId ) use ( $sourceLanguage, $wpmlType ) {
			return new \WPML_TM_Translation_Batch_Element(
				$postId,
				$wpmlType,
				$sourceLanguage,
				$targetLanguages->toArray()
			);
		};

		$batchElements = \wpml_collect( $elements )
			->groupBy( 0 )
			->map( Fns::map( $getTargetLang ) )
			->map( invoke( 'mapWithKeys' )->with( $setTranslateAction ) )
			->map( $makeBatchElement )
			->values()
			->toArray();

		$batch = new \WPML_TM_Translation_Batch(
			$batchElements,
			\TranslationProxy_Batch::get_generic_batch_name( true ),
			$targetLanguages
		);
		$batch->setTranslationMode( 'auto' );

		wpml_load_core_tm()->send_jobs( $batch, $wpmlType, Jobs::SENT_AUTOMATICALLY );

		$getJobId = pipe(
			Fns::converge( Jobs::getElementJob(), [
				Obj::prop( 'elementId' ),
				Obj::prop( 'elementType' ),
				Obj::prop( 'lang' )
			] ),
			Obj::prop( 'job_id' ),
			Fns::unary( 'intval' )
		);

		return \wpml_collect( $elements )
			->map( Lst::zipObj( [ 'elementId', 'lang' ] ) )
			->map( Obj::addProp( 'elementType', Fns::always( $elementType === 'st-batch' ? 'st-batch_strings' : $elementType ) ) )
			->map( Obj::addProp( 'jobId', $getJobId ) )
			->toArray();
	}

	private function isPostElementAndUsingNativeEditor( \WPML_Translation_Element $translationElement ): bool {
		return $translationElement->get_element_type() === 'post'
			? \WPML_TM_Post_Edit_TM_Editor_Mode::is_using_tm_editor( null, $translationElement->get_element_id(), false )
			: true;
	}
}
