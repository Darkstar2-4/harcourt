<?php

namespace WPML\TM\ATE\Hooks;

use function WPML\Container\make;
use WPML\Element\API\Languages;
use WPML\FP\Fns;
use function WPML\FP\invoke;
use WPML\FP\Lst;
use WPML\FP\Obj;
use function WPML\FP\pipe;
use WPML\FP\Relation;
use WPML\Setup\Option;
use WPML\TM\ATE\TranslateEverything;
use WPML\TM\Jobs\JobLog;

class JobActions implements \IWPML_Action {

	private $apiClient;

	private $translateEverything;

	public function __construct( \WPML_TM_ATE_API $apiClient, TranslateEverything $translateEverything ) {
		$this->apiClient           = $apiClient;
		$this->translateEverything = $translateEverything;
	}

	public function add_hooks() {
		add_action( 'wpml_tm_job_cancelled', [ $this, 'cancelJobInATE' ] );
		add_action( 'wpml_tm_jobs_cancelled', [ $this, 'cancelJobsInATE' ] );
		add_action( 'wpml_set_translate_everything', [ $this, 'onTranslateEverythingModeChanged' ], 10, 2 );
		add_action( 'wpml_update_active_languages', [ $this, 'hideJobsAfterRemoveLanguage' ] );
		add_action( 'wpml_cancel_all_automatic_jobs', [ $this, 'cancelAllAutomaticJobs' ], 10 );
	}

	public function cancelJobInATE( \WPML_TM_Post_Job_Entity $job ) {
		if ( $job->is_ate_editor() ) {
			$this->apiClient->cancelJobs( $job->get_editor_job_id() );
		}
	}

	public function cancelJobsInATE( $jobs ) {
		if ( is_object( $jobs ) ) {
			$jobs = [ $jobs ];
		}

		$normalizedJobs = array_map( function( $job ) {
			if ( $job instanceof \WPML_TM_Post_Job_Entity ) {
				return (object) [
					'editor'        => $job->get_editor(),
					'editor_job_id' => $job->get_editor_job_id(),
				];
			}

			return $job;
		}, $jobs );

		$ateJobIds = array_values( array_filter( array_map( function( $job ) {
			return ( isset( $job->editor ) && $job->editor === 'ate' && isset( $job->editor_job_id ) )
				? $job->editor_job_id
				: null;
		}, $normalizedJobs ) ) );

		if ( ! empty( $ateJobIds ) ) {
			JobLog::add( 'cancel_jobs_in_ate', [
				'ate_job_ids' => array_map(
					function ( $id ) { return [ 'ate_job_id' => $id ]; },
					$ateJobIds
				),
			] );
			$this->apiClient->cancelJobs( $ateJobIds );
		}
	}

	public function hideJobsAfterRemoveLanguage( $oldLanguages = [] ) {
		$oldLanguagesArray = is_array( $oldLanguages ) ? array_keys( $oldLanguages ) : [];
		$removedLanguages = Lst::diff( $oldLanguagesArray, array_keys( Languages::getActive() ) );

		if ( $removedLanguages ) {
			$inProgressJobsSearchParams = self::getInProgressSearch()
			                                  ->set_target_language( array_values( $removedLanguages ) );

			$this->hideJobs( $inProgressJobsSearchParams );

			$this->translateEverything->markLanguagesAsUncompleted( $removedLanguages );
		}
	}

	public function onTranslateEverythingModeChanged( $translateEverythingActive, $options = [] ) {
		JobLog::maybeInitRequest();
		JobLog::createNewGroup(
			JobLog::GROUP_ID_TRANSLATE_EVERYTHING,
			'Translate Everything mode change',
			[
				'newState'          => $translateEverythingActive ? 'on' : 'off',
				'translateExisting' => $options['translateExisting'] ?? null,
				'reviewMode'        => $options['reviewMode'] ?? null,
			]
		);

		try {
			JobLog::add( 'tea_mode_changed', [
				'active'             => (bool) $translateEverythingActive,
				'translate_existing' => $options['translateExisting'] ?? false,
				'review_mode'        => $options['reviewMode'] ?? null,
			] );

			if ( $translateEverythingActive ) {
				$translateExistingContent = $options['translateExisting'] ?? false;
				if ( $translateExistingContent ) {
					JobLog::add( 'tea_kickoff_marking_uncompleted', [] );
					$this->translateEverything->markEverythingAsUncompleted();
				} else {
					JobLog::add( 'tea_kickoff_no_existing', [] );
					$this->translateEverything->markEverythingAsCompleted();
				}
			} else {
				JobLog::add( 'tea_disabled_cancelling_jobs', [] );
				$this->cancelAllAutomaticJobs();
			}
		} finally {
			JobLog::finishCurrentGroup();
		}
	}

	public function cancelAllAutomaticJobs() {
		JobLog::maybeInitRequest();
		$ownsGroup = ! JobLog::isGroupOpen();
		if ( $ownsGroup ) {
			JobLog::createNewGroup(
				JobLog::GROUP_ID_TRANSLATE_EVERYTHING,
				'TEA cancel all automatic jobs',
				[]
			);
		}

		try {
			JobLog::add( 'tea_cancel_all_started', [] );
			$this->hideJobs( self::getInProgressSearch() );
			JobLog::add( 'tea_cancel_all_finished', [] );
		} catch ( \Throwable $e ) {
			JobLog::addError( 'tea_cancel_all_failed', [
				'error' => $e->getMessage(),
				'file'  => $e->getFile(),
				'line'  => $e->getLine(),
			] );
			throw $e;
		} finally {
			if ( $ownsGroup ) {
				JobLog::finishCurrentGroup();
			}
		}
	}

	private static function getInProgressSearch() {
		return ( new \WPML_TM_Jobs_Search_Params() )->set_status( [
			ICL_TM_WAITING_FOR_TRANSLATOR,
			ICL_TM_IN_PROGRESS
		] );
	}

	private function hideJobs( \WPML_TM_Jobs_Search_Params $jobsSearchParams ) {
		$translationJobs = wpml_collect( wpml_tm_get_jobs_repository()->get( $jobsSearchParams ) )
			->filter( invoke( 'is_ate_editor' ) )
			->filter( invoke( 'is_automatic' ) );

		$canceledInATE = $this->apiClient->hideJobs(
			$translationJobs->map( invoke( 'get_editor_job_id' ) )->values()->toArray()
		);

		$isResponseValid = $canceledInATE && ! is_wp_error( $canceledInATE );
		$jobsHiddenInATE = $isResponseValid ? Obj::propOr( [], 'jobs', $canceledInATE ) : [];
		$isHiddenInATE   = function ( $job ) use ( $isResponseValid, $jobsHiddenInATE ) {
			return $isResponseValid && Lst::includes( $job->get_editor_job_id(), $jobsHiddenInATE );
		};

		$setStatus = Fns::tap( function ( \WPML_TM_Post_Job_Entity $job ) use ( $isHiddenInATE ) {
			$status = $isHiddenInATE( $job ) ? ICL_TM_ATE_CANCELLED : ICL_TM_NOT_TRANSLATED;
			$job->set_status( $status );
		} );

		$translationJobs->map( $setStatus )
		                ->map( Fns::tap( [ make( \WPML_TP_Sync_Update_Job::class ), 'update_state' ] ) );
	}
}
