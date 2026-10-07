<?php

namespace WPML\TM\Editor;

use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\FP\Left;
use WPML\FP\Logic;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\FP\Right;
use WPML\LIB\WP\User;
use WPML\Setup\Option as SetupOption;
use WPML\TM\API\Jobs;
use WPML\TM\ATE\Log\Entry;
use WPML\TM\ATE\Log\Storage;
use WPML\TM\ATE\Review\ReviewStatus;
use WPML\TM\ATE\Sync\Trigger;
use WPML\TM\Jobs\Manual;
use WPML\TM\Menu\TranslationQueue\CloneJobs;
use function WPML\Container\make;
use function WPML\FP\curryN;
use function WPML\FP\invoke;
use function WPML\FP\pipe;

class Editor {

	const ATE_JOB_COULD_NOT_BE_CREATED = 101;
	const ATE_EDITOR_URL_COULD_NOT_BE_FETCHED = 102;
	const ATE_IS_NOT_ACTIVE = 103;

	private $clone_jobs;

	private $manualJobs;

	public function __construct( CloneJobs $clone_jobs, Manual $manualJobs ) {
		$this->clone_jobs = $clone_jobs;
		$this->manualJobs = $manualJobs;
	}

	public function open( $params ) {
		$shouldOpenCTE = function ( $jobObject ) use ( $params ) {
			if ( ! \WPML_TM_ATE_Status::is_enabled() ) {
				return true;
			}

			$previousJob = $this->previousJob( $params, $jobObject );

			if ( ! $jobObject->get_basic_data_property( 'translated' ) && $previousJob ) {
				return wpml_tm_load_old_jobs_editor()->shouldStickToWPMLEditor( $jobObject->get_id(), $previousJob );
			}

			return wpml_tm_load_old_jobs_editor()->editorForTranslationsPreviouslyCreatedUsingCTE() === \WPML_TM_Editors::WPML &&
			       wpml_tm_load_old_jobs_editor()->get_current_editor( $jobObject->get_id() ) === \WPML_TM_Editors::WPML;
		};

		$maybeUpdateTranslationServiceColumn = function ( $jobObject ) {
			if ( $jobObject->get_translation_service() !== 'local' ) {
				$jobObject->set_basic_data_property( 'translation_service', 'local' );
				Jobs::setTranslationService( $jobObject->get_id(), 'local' );
			}

			return $jobObject;
		};

		$dataOfTranslationCreatedInNativeEditorViaConnection = $this->manualJobs->maybeGetDataIfTranslationCreatedInNativeEditorViaConnection( $params );
		if ( $dataOfTranslationCreatedInNativeEditorViaConnection ) {
			update_post_meta( $dataOfTranslationCreatedInNativeEditorViaConnection['originalPostId'], \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_USE_NATIVE, 'yes' );

			return $this->displayWPNative( $dataOfTranslationCreatedInNativeEditorViaConnection );
		}

		return Either::of( $params )
		             ->map( [ $this->manualJobs, 'createOrReuse' ] )
		             ->filter( Logic::isTruthy() )
		             ->filter( invoke( 'user_can_translate' )->with( User::getCurrent() ) )
		             ->map( $maybeUpdateTranslationServiceColumn )
		             ->map( Logic::ifElse( $shouldOpenCTE, $this->displayCTE(), $this->tryToDisplayATE( $params ) ) )
		             ->getOrElse( [ 'editor' => \WPML_TM_Editors::NONE, 'jobObject' => null ] );
	}

	private function tryToDisplayATE( $params = null, $jobObject = null ) {
		$fn = curryN( 2, function ( $params, $jobObject ) {
			$handleNotActiveATE = Logic::ifElse(
				[ \WPML_TM_ATE_Status::class, 'is_active' ],
				Either::of(),
				pipe( $this->handleATEJobCreationError( $params, self::ATE_IS_NOT_ACTIVE ), Either::left() )
			);

			$cloneCompletedATEJob = function ( $jobObject ) use ( $params ) {
				if ( $this->isValidATEJob( $jobObject ) && (int) $jobObject->get_status_value() === ICL_TM_COMPLETE ) {
					$sentFrom = isset( $params['preview'] ) ? Jobs::SENT_FROM_REVIEW : Jobs::SENT_MANUALLY;

					return $this->clone_jobs->cloneCompletedATEJob( $jobObject, $sentFrom )
					                        ->bimap( $this->handleATEJobCreationError( $params, self::ATE_JOB_COULD_NOT_BE_CREATED ), Fns::identity() );
				}

				return Either::of( $jobObject );
			};

			$handleMissingATEJob = function ( $jobObject ) use ( $params ) {
				if ( $this->isValidATEJob( $jobObject ) ) {
					return Either::of( $jobObject );
				}

				if ( $jobObject->get_basic_data_property( 'editor' ) === \WPML_TM_Editors::WPML ) {
					return $this->createATECounterpartForExistingWPMLJob( $params, $jobObject );
				}

				if ( ! $jobObject->get_basic_data_property( 'translated' ) && $this->previousJob( $params, $jobObject ) ) {
					return Either::left( $this->handleATEJobCreationError( $params, self::ATE_JOB_COULD_NOT_BE_CREATED, $jobObject ) );
				}

				return $this->createATECounterpartForExistingWPMLJob( $params, $jobObject );
			};

			return Either::of( $jobObject )
			             ->chain( $handleNotActiveATE )
			             ->chain( $cloneCompletedATEJob )
			             ->chain( $handleMissingATEJob )
			             ->map( Fns::tap( pipe( invoke( 'get_id' ), Jobs::setStatus( Fns::__, ICL_TM_IN_PROGRESS ) ) ) )
			             ->map( $this->openATE( $params ) )
			             ->coalesce( Fns::identity(), Fns::identity() )
			             ->get();
		} );

		return call_user_func_array( $fn, func_get_args() );
	}

	private function displayCTE( $jobObject = null ) {
		$fn = curryN( 1, function ( $jobObject ) {
			wpml_tm_load_old_jobs_editor()->set( $jobObject->get_id(), \WPML_TM_Editors::WPML );

			return [ 'editor' => \WPML_TM_Editors::WPML, 'jobObject' => $jobObject ];
		} );

		return call_user_func_array( $fn, func_get_args() );
	}

	private function displayWPNative( array $dataOfTranslationCreatedInNativeEditorViaConnection ) {
		$url = 'post.php?' . http_build_query(
				[
					'lang'      => $dataOfTranslationCreatedInNativeEditorViaConnection['targetLanguageCode'],
					'action'    => 'edit',
					'post_type' => str_replace( 'post_', '', $dataOfTranslationCreatedInNativeEditorViaConnection['postType'] ),
					'post'      => $dataOfTranslationCreatedInNativeEditorViaConnection['translatedPostId']
				]
			);


		return [ 'editor' => \WPML_TM_Editors::WP, 'jobObject' => null, 'url' => $url ];
	}

	private function maybeSetReviewStatus( $jobObject ) {
		if ( Relation::propEq( 'review_status', ReviewStatus::NEEDS_REVIEW, $jobObject->to_array() ) ) {
			Jobs::setReviewStatus( $jobObject->get_id(), SetupOption::shouldBeReviewed() ? ReviewStatus::EDITING : null );
		}
	}

	private function handleATEJobCreationError( $params = null, $code = null, $jobObject = null ) {
		$fn = curryN( 3, function ( $params, $code, $jobObject ) {
			ATERetry::incrementCount( $jobObject->get_id() );

			$retryCount = ATERetry::getCount( $jobObject->get_id() );
			if ( $retryCount > 0 ) {
				Storage::add( Entry::retryJob( $jobObject->get_id(),
					[
						'retry_count' => ATERetry::getCount( $jobObject->get_id() )
					]
				) );
			}

			return [
				'editor' => \WPML_TM_Editors::ATE,
				'url'    => add_query_arg( [ 'ateJobCreationError' => $code, 'jobId' => $jobObject->get_id() ], $this->getReturnUrl( $params ) )
			];
		} );

		return call_user_func_array( $fn, func_get_args() );
	}

	private function isJobEditorEqualTo( $editor, $jobObject ) {
		return $jobObject->get_basic_data_property( 'editor' ) === $editor;
	}

	private function previousJob( $params, $jobObject ) {

		$jobObjectJobId = (int) $jobObject->get_id();
		$jobIdInParams  = (int) Obj::prop( 'job_id', $params );

		if ( $jobObjectJobId !== $jobIdInParams ) {
			return Jobs::get( $jobIdInParams );
		}

		$previousJob = Jobs::getPreviousJob( $jobIdInParams );

		return $previousJob && (int) $previousJob->job_id !== $jobObjectJobId ? $previousJob : false;
	}

	private function createATECounterpartForExistingWPMLJob( $params, $jobObject ) {
		if ( $this->clone_jobs->cloneWPMLJob( $jobObject->get_id() ) ) {
			ATERetry::reset( $jobObject->get_id() );
			$jobObject->set_basic_data_property( 'editor', \WPML_TM_Editors::ATE );

			return Either::of( $jobObject );
		}

		return Either::left( $this->handleATEJobCreationError( $params, self::ATE_JOB_COULD_NOT_BE_CREATED, $jobObject ) );
	}

	private function openATE( $params = null, $jobObject = null ) {
		$fn = curryN( 2, function ( $params, $jobObject ) {
			$this->maybeSetReviewStatus( $jobObject );

			$editor_url = apply_filters( 'wpml_tm_ate_jobs_editor_url', '', $jobObject->get_id(), $this->getReturnUrl( $params ) );

			if ( $editor_url ) {
				$response['editor']    = \WPML_TM_Editors::ATE;
				$response['url']       = $editor_url;
				$response['jobObject'] = $jobObject;

				return $response;
			}

			return $this->handleATEJobCreationError( $params, self::ATE_EDITOR_URL_COULD_NOT_BE_FETCHED, $jobObject );
		} );

		return call_user_func_array( $fn, func_get_args() );
	}



	private function getReturnUrl( $params ) {
		$return_url = '';

		if ( array_key_exists( 'return_url', $params ) ) {
			$return_url = filter_var( $params['return_url'], FILTER_SANITIZE_URL );

			$return_url_parts = wp_parse_url( (string) $return_url );

			$admin_url       = get_admin_url();
			$admin_url_parts = wp_parse_url( $admin_url );

			if ( strpos( $return_url_parts['path'], $admin_url_parts['path'] ) === 0 ) {
				$admin_url_parts['path'] = $return_url_parts['path'];
			} else {
				$admin_url_parts = $return_url_parts;
			}

			$admin_url_parts['query'] = $this->prepareQueryParameters(
				Obj::propOr( '', 'query', $return_url_parts ),
				Obj::prop( 'lang', $params )
			);

			$return_url = http_build_url( $admin_url_parts );
		}

		return $return_url;
	}

	private function prepareQueryParameters( $query, $returnLanguage ) {
		$parameters = [];
		parse_str( $query, $parameters );

		unset( $parameters['ate_original_id'] );
		unset( $parameters['back'] );
		unset( $parameters['complete'] );

		if ( $returnLanguage ) {
			$parameters['lang'] = $returnLanguage;
		}

		return http_build_query( $parameters );
	}

	private function isValidATEJob( \WPML_Element_Translation_Job $jobObject ) {
		return $this->isJobEditorEqualTo( \WPML_TM_Editors::ATE, $jobObject ) &&
		       (int) $jobObject->get_basic_data_property( 'editor_job_id' ) > 0;
	}
}
