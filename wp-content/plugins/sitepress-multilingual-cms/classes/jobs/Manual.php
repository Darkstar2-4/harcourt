<?php


namespace WPML\TM\Jobs;

use WPML\Element\API\PostTranslations;
use WPML\FP\Lst;
use WPML\FP\Maybe;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\LIB\WP\User;
use WPML\Records\Translations as TranslationRecords;
use WPML\TM\API\Jobs;
use function WPML\FP\pipe;

class Manual {
	public function createOrReuse( array $params ) {
		$jobId    = (int) filter_var( Obj::propOr( 0, 'job_id', $params ), FILTER_SANITIZE_NUMBER_INT );
		$isReview = (bool) filter_var( Obj::propOr( 0, 'preview', $params ), FILTER_SANITIZE_NUMBER_INT );

		list( $jobId, $trid, $updateNeeded, $targetLanguageCode, $elementType ) = $this->get_job_data_for_restore( $jobId, $params );
		$sourceLangCode = filter_var( Obj::prop( 'source_language_code', $params ), FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		$needsUpdateAndIsNotReviewMode = $updateNeeded && ! $isReview;

		if ( $trid && $targetLanguageCode && ( $needsUpdateAndIsNotReviewMode || ! $jobId ) ) {
			$postId = $this->getOriginalPostId( $trid );

			if ( ! $jobId ) {
				$postId = $this->getPostIdInLang( $trid, $sourceLangCode ) ?: $postId;
			}

			if ( $postId && $this->can_user_translate( $sourceLangCode, $targetLanguageCode, $postId ) ) {
				$createdJob = $this->markJobAsManual( $this->createLocalJob( $postId, $sourceLangCode, $targetLanguageCode, $elementType ) );
				if ( $createdJob ) {
					JobLog::add( 'manual_editor_job_prepared', [
						'job_id'      => JobLog::safeCall( $createdJob, 'get_id' ),
						'post_id'     => $postId,
						'target_lang' => $targetLanguageCode,
						'reused'      => false,
					] );
				}
				return $createdJob;
			}
		}

		$reusedJob = $jobId ? $this->markJobAsManual( wpml_tm_load_job_factory()->get_translation_job_as_active_record( $jobId ) ) : null;
		if ( $reusedJob ) {
			JobLog::add( 'manual_editor_job_prepared', [
				'job_id'      => JobLog::safeCall( $reusedJob, 'get_id' ),
				'target_lang' => $targetLanguageCode,
				'reused'      => true,
			] );
		}
		return $reusedJob;
	}

	public function maybeGetDataIfTranslationCreatedInNativeEditorViaConnection( array $params ) {
		$jobId = (int) filter_var( Obj::propOr( 0, 'job_id', $params ), FILTER_SANITIZE_NUMBER_INT );
		list( $jobId, $trid, , $targetLanguageCode ) = $this->get_job_data_for_restore( $jobId, $params );

		if ( $trid && $targetLanguageCode && ! $jobId ) {
			$originalPostId = $this->getOriginalPostId( $trid );
			if ( $this->isDuplicate( $originalPostId, $targetLanguageCode ) ) {
				return null;
			}

			$translatedPostId = (int) $this->getPostIdInLang( $trid, $targetLanguageCode );

			if ( $translatedPostId ) {
				$translatedPost = get_post( $translatedPostId );

				if ( $translatedPost ) {
					$enforcedNativeEditor = get_post_meta( $originalPostId, \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_USE_NATIVE, true );
					if ( $enforcedNativeEditor === 'no' ) {
						return null;
					}

					return [
						'targetLanguageCode' => $targetLanguageCode,
						'translatedPostId'   => $translatedPostId,
						'originalPostId'     => $originalPostId,
						'postType'           => $translatedPost->post_type,
					];
				}
			}
		}

		return null;
	}

	private function getOriginalPostId( $trid ) {
		return Obj::prop( 'element_id', TranslationRecords::getSourceByTrid( $trid ) );
	}

	private function getPostIdInLang( $trid, $lang ) {
		$getElementId = pipe( Lst::find( Relation::propEq( 'language_code', $lang ) ), Obj::prop( 'element_id' ) );

		return $getElementId( TranslationRecords::getByTrid( $trid ) );
	}

	private function get_job_data_for_restore( $jobId, array $params ) {
		$trid         = (int) filter_var( Obj::prop( 'trid', $params ), FILTER_SANITIZE_NUMBER_INT );
		$updateNeeded = (bool) filter_var( Obj::prop( 'update_needed', $params ), FILTER_SANITIZE_NUMBER_INT );
		$languageCode = (string) filter_var( Obj::prop( 'language_code', $params ), FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		$job = null;

		if ( $trid && $languageCode ) {
			$job = Jobs::getTridJob( $trid, $languageCode );
		} elseif ( $jobId ) {
			$job = Jobs::get( $jobId );
		}

		if ( is_object( $job ) ) {
			return [
				Obj::prop( 'job_id', $job ),
				Obj::prop( 'trid', $job ),
				Obj::prop( 'needs_update', $job ),
				Obj::prop( 'language_code', $job ),
				Obj::prop( 'original_post_type', $job )
			];
		}

		$elementType = $trid ? Obj::path( [ 0, 'element_type' ], TranslationRecords::getByTrid( $trid ) ) : null;

		return [ $jobId, $trid, $updateNeeded, $languageCode, $elementType, ];
	}

	private function can_user_translate( $sourceLangCode, $targetLangCode, $postId ) {
		$args = [
			'lang_from' => $sourceLangCode,
			'lang_to'   => $targetLangCode,
			'post_id'   => $postId,
		];

		return wpml_tm_load_blog_translators()->is_translator( User::getCurrentId(), $args );
	}

	private function createLocalJob( $originalPostId, $sourceLangCode, $targetLangCode, $elementType ) {
		$jobId = wpml_tm_load_job_factory()->create_local_job( $originalPostId, $targetLangCode, null, $elementType, Jobs::SENT_MANUALLY, $sourceLangCode );

		return Maybe::fromNullable( $jobId )
		            ->map( [ wpml_tm_load_job_factory(), 'get_translation_job_as_active_record' ] )
		            ->map( $this->maybeAssignTranslator() )
		            ->map( $this->maybeSetJobStatus() )
		            ->getOrElse( null );
	}

	private function maybeAssignTranslator() {
		return function ( $jobObject ) {
			if ( $jobObject->get_translator_id() <= 0 ) {
				$jobObject->assign_to( User::getCurrentId() );
			}

			return $jobObject;
		};
	}

	private function maybeSetJobStatus() {
		return function ( $jobObject ) {
			if ( $this->isDuplicate( $jobObject->get_original_element_id(), $jobObject->get_language_code() ) ) {
				Jobs::setStatus( (int) $jobObject->get_id(), ICL_TM_DUPLICATE );
			} elseif ( (int) $jobObject->get_status_value() !== ICL_TM_COMPLETE ) {
				Jobs::setStatus( (int) $jobObject->get_id(), ICL_TM_IN_PROGRESS );
			}

			return $jobObject;
		};
	}

	private function markJobAsManual( $jobObject ) {
		$jobObject && Jobs::clearAutomatic( $jobObject->get_id() );

		return $jobObject;
	}

	private function isDuplicate( $originalElementId, $targetLanguageCode ): bool {
		return Maybe::of( $originalElementId )
		            ->map( PostTranslations::get() )
		            ->map( Obj::prop( $targetLanguageCode ) )
		            ->map( Obj::prop( 'element_id' ) )
		            ->map( [ wpml_get_post_status_helper(), 'is_duplicate' ] )
		            ->getOrElse( false );
	}
}
