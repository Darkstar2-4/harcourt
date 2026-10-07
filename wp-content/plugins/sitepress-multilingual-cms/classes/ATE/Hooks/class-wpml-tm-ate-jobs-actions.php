<?php

use WPML\API\Sanitize;
use WPML\FP\Fns;
use WPML\FP\Json;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Str;
use WPML\FP\Relation;
use WPML\TM\API\Jobs;
use WPML\FP\Wrapper;
use WPML\Settings\PostType\Automatic;
use WPML\Setup\Option;
use WPML\TM\ATE\JobRecords;
use WPML\TM\ATE\Log\Storage;
use WPML\TM\ATE\Log\Entry;
use function WPML\FP\partialRight;
use function WPML\FP\pipe;
use WPML\TM\API\ATE\LanguageMappings;
use WPML\Element\API\Languages;
use WPML\TM\Jobs\JobLog;
use WPML\Translation\AteSyncOrderingServiceFactory;

class WPML_TM_ATE_Jobs_Actions implements IWPML_Action {
	const RESPONSE_ATE_NOT_ACTIVE_ERROR     = 403;
	const RESPONSE_ATE_DUPLICATED_SOURCE_ID = 417;
	const RESPONSE_ATE_UNEXPECTED_ERROR     = 500;

	const RESPONSE_ATE_ERROR_NOTICE_ID    = 'ate-update-error';
	const RESPONSE_ATE_ERROR_NOTICE_GROUP = 'default';

	const CREATE_ATE_JOB_CHUNK_WORDS_LIMIT = 2000;

	private $ate_api;
	private $ate_jobs;

	private $translator_activation_records;

	private $is_second_attempt_to_get_jobs_data = false;
	private $sitepress;
	private $current_screen;

	private $wp_api;

	public function __construct(
		WPML_TM_ATE_API $ate_api,
		WPML_TM_ATE_Jobs $ate_jobs,
		SitePress $sitepress,
		WPML_Current_Screen $current_screen,
		WPML_TM_AMS_Translator_Activation_Records $translator_activation_records,
		WPML_WP_API $wp_api
	) {
		$this->ate_api                       = $ate_api;
		$this->ate_jobs                      = $ate_jobs;
		$this->sitepress                     = $sitepress;
		$this->current_screen                = $current_screen;
		$this->translator_activation_records = $translator_activation_records;
		$this->wp_api                        = $wp_api;
	}

	public function add_hooks() {
		add_action( 'wpml_added_translation_job', [ $this, 'added_translation_job' ], 10, 2 );
		add_action( 'wpml_added_translation_jobs', [ $this, 'added_translation_jobs' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'handle_messages' ] );

		add_filter( 'wpml_tm_ate_jobs_data', [ $this, 'get_ate_jobs_data_filter' ], 10, 2 );
		add_filter( 'wpml_tm_ate_jobs_editor_url', [ $this, 'get_editor_url' ], 10, 3 );
	}

	public function handle_messages() {
		if ( $this->current_screen->id_ends_with( WPML_TM_FOLDER . '/menu/translations-queue' ) ) {

			if ( array_key_exists( 'message', $_GET ) ) {
				if ( array_key_exists( 'ate_job_id', $_GET ) ) {
					$ate_job_id = filter_var( $_GET['ate_job_id'], FILTER_SANITIZE_NUMBER_INT );

					$this->resign_job_on_error( $ate_job_id );
				}
				$message = Sanitize::stringProp( 'message', $_GET );
				?>

				<div class="error notice-error notice otgs-notice">
					<p><?php echo $message; ?></p>
				</div>

				<?php
			}
		}
	}

	public function added_translation_job( $job_id, $translation_service ) {
		$this->added_translation_jobs( array( $translation_service => array( $job_id ) ) );
	}

	public function added_translation_jobs( array $jobs, $sentFrom = null, ?\WPML_TM_Translation_Batch $batch = null ) {
		$additionalErrorMsg            = '';
		$translationModeSetInDashboard = null;

		try {
			$oldEditor = wpml_tm_load_old_jobs_editor();
			$job_ids   = Fns::reject( [ $oldEditor, 'shouldStickToWPMLEditor' ], Obj::propOr( [], 'local', $jobs ) );

			if ( ! $job_ids ) {
				return;
			}

			$applyTranslationMemory = $this->shouldApplyTranslationMemory( $batch );

			$jobs = Fns::map(
				function ( $jobId ) use ( $applyTranslationMemory ) {
					return wpml_tm_create_ATE_job_creation_model( $jobId, $applyTranslationMemory );
				},
				$job_ids
			);

			$translationModeSetInDashboard = $this->getTranslationModeFromBatch( $batch );
			$responses                     = Fns::map(
				Fns::unary( partialRight( [ $this, 'create_jobs' ], $sentFrom ) ),
				$this->getChunkedJobs( $jobs, $translationModeSetInDashboard )
			);
			$created_jobs                  = $this->getResponsesJobs( $responses, $jobs );
		} catch ( \Throwable $throwable ) {
			$created_jobs       = [];
			$additionalErrorMsg = $this->getErrorMessage( $throwable );
			$this->maybeLogRuntimeError( $additionalErrorMsg );

			if ( empty( $oldEditor ) || empty( $job_ids ) ) {
				return;
			}
		}

		if ( $created_jobs ) {

			$created_jobs = $this->map_response_jobs( $created_jobs );

			$this->ate_jobs->warm_cache( array_keys( $created_jobs ) );

			foreach ( $created_jobs as $wpml_job_id => $ate_job_id ) {
				$this->ate_jobs->store( $wpml_job_id, [ JobRecords::FIELD_ATE_JOB_ID => $ate_job_id ] );
				$oldEditor->set( $wpml_job_id, WPML_TM_Editors::ATE );
				$translationJob = wpml_tm_load_job_factory()->get_translation_job( $wpml_job_id, false, 0, true );
                if ( $translationJob ) {
	                $jobType = $this->getJobType( $translationJob, $translationModeSetInDashboard );
	                Jobs::setAutomaticStatus( $wpml_job_id, $jobType === 'auto' );
                }

				JobLog::add( 'ate_job_bound', [
					'job_id'              => (int) $wpml_job_id,
					'ate_job_id'          => (int) $ate_job_id,
					'sent_from'           => $sentFrom,
					'target_lang'         => JobLog::safeCall( $translationJob, 'get_language_code' ),
					'original_element_id' => JobLog::safeCall( $translationJob, 'get_original_element_id' ),
				] );

				if ( $sentFrom === Jobs::SENT_RETRY ) {
					Jobs::setStatus( $wpml_job_id, ICL_TM_WAITING_FOR_TRANSLATOR );
				}
			}

			$message = __( '%1$s jobs added to the Advanced Translation Editor.', 'sitepress' );
			$this->add_message( 'updated', sprintf( $message, count( $created_jobs ) ), 'wpml_tm_ate_create_job' );

			do_action( 'wpml_tm_ate_jobs_created', $created_jobs );
		} else {
			if ( Lst::includes( $sentFrom, [ Jobs::SENT_AUTOMATICALLY, Jobs::SENT_RETRY ] ) ) {
				if ( $sentFrom === Jobs::SENT_RETRY ) {
					$updateJob = function ( $jobId ) {
						Jobs::incrementRetryCount( $jobId );
						$this->logRetryError( $jobId );
					};
				} else {
					$updateJob = function ( $jobId ) use ( $oldEditor, $translationModeSetInDashboard, $additionalErrorMsg ) {
						$this->logError( $jobId, $additionalErrorMsg );

						$translationJob = wpml_tm_load_job_factory()->get_translation_job( $jobId, false, 0, true );
						if ( $translationJob ) {
                            $jobType = $this->getJobType( $translationJob, $translationModeSetInDashboard );
                            if ( $jobType === 'auto' ) {
                                Jobs::setStatus( $jobId, ICL_TM_ATE_NEEDS_RETRY );
                                $oldEditor->set( $jobId, WPML_TM_Editors::ATE );
                                wpml_tm_load_job_factory()->update_job_data( $jobId, [ 'automatic' => 1 ] );
                            }
                        }
					};
				}

				wpml_collect( $job_ids )->map( $updateJob );
			}
		}
	}

	private function map_response_jobs( $responseJobs ) {
		$result = [];
		foreach ( $responseJobs as $rid => $ate_job_id ) {
			$jobId = \WPML\TM\API\Job\Map::fromRid( $rid );
			if ( $jobId ) {
				$result[ $jobId ] = $ate_job_id;
			}
		}

		return $result;
	}

	private function add_message( $type, $message, $id = null ) {
		do_action( 'wpml_tm_basket_add_message', $type, $message, $id );
	}

	public function create_jobs( array $jobsData, $sentFrom  ) {
		$setJobType = Logic::ifElse( Fns::always( $sentFrom ), Obj::assoc( 'job_type', $sentFrom ), Fns::identity() );

		list( $existing, $new ) = Lst::partition(
			pipe( Obj::propOr( null, 'existing_ate_id' ), Logic::isNotNull() ),
			$jobsData['jobs']
		);

		$isAuto = Relation::propEq( 'type', 'auto', $jobsData );

		$ordering = $this->buildOrderingForJobs( $jobsData['jobs'] );

		return Wrapper::of( [ 'jobs' => $new, 'existing_jobs' => Lst::pluck( 'existing_ate_id', $existing ) ] )
			->map( Obj::assoc( 'auto_translate', $isAuto ) )
			->map( Obj::assoc( 'preview', $isAuto && Option::shouldBeReviewed() ) )
			->map( $setJobType )
			->map( $this->addOrderingToPayload( $ordering ) )
			->map( 'wp_json_encode' )
			->map( Json::toArray() )
			->map( [ $this->ate_api, 'create_jobs' ] )
			->get();
	}

	private function buildOrderingForJobs( array $jobs ): array {
		$postIds    = [];
		$stringIds  = [];
		$packageIds = [];
		$jobFactory = wpml_tm_load_job_factory();

		foreach ( $jobs as $job ) {
			$jobId = is_object( $job ) ? ( $job->id ?? null ) : ( $job['id'] ?? null );
			if ( ! $jobId ) {
				continue;
			}

			$translationJob = $jobFactory->get_translation_job( $jobId, false, 0, true );
			if ( $translationJob instanceof \WPML_Post_Translation_Job ) {
				$postIds[] = $translationJob->get_original_element_id();
			} elseif ( $translationJob instanceof \WPML_Package_Translation_Job ) {
				$packageIds[] = $translationJob->get_original_element_id();
			} elseif ( strpos( $translationJob->get_basic_data_property( 'original_post_type' ) ?? '', 'st-batch_' ) === 0 ) {
				$stringIds[] = $translationJob->get_original_element_id();
			}
		}

		if ( empty( $postIds ) && empty( $stringIds ) && empty( $packageIds ) ) {
			return [];
		}

		$orderingService = AteSyncOrderingServiceFactory::create();
		return $orderingService->getOrderingPayloadArrayForPosts(
			array_unique( $postIds ),
			array_unique( $stringIds ),
			array_unique( $packageIds )
		);
	}

	private function addOrderingToPayload( array $ordering ): callable {
		return function ( $payload ) use ( $ordering ) {
			if ( ! empty( $ordering['positions'] ) ) {
				$payload['ordering'] = $ordering;
			}
			return $payload;
		};
	}

	private function get_ate_jobs_data( array $translation_jobs ) {
		$ate_jobs_data      = array();
		$skip_getting_data  = false;
		$ate_jobs_to_create = array();

		$this->ate_jobs->warm_cache( wpml_collect( $translation_jobs )->pluck( 'job_id' )->toArray() );

		foreach ( $translation_jobs as $translation_job ) {
			if ( $this->is_ate_translation_job( $translation_job ) ) {
				$ate_job_id = $this->get_ate_job_id( $translation_job->job_id );
				if ( ! $ate_job_id ) {
					$ate_jobs_to_create[] = $translation_job->job_id;
					$skip_getting_data    = true;
				}

				if ( ! $skip_getting_data ) {
					$ate_jobs_data[ $translation_job->job_id ] = [ 'ate_job_id' => $ate_job_id ];
				}
			}
		}

		if (
			! $this->is_second_attempt_to_get_jobs_data &&
			$ate_jobs_to_create
		) {
			$this->added_translation_jobs( array( 'local' => $ate_jobs_to_create ) );
			$ate_jobs_data                            = $this->get_ate_jobs_data( $translation_jobs );
			$this->is_second_attempt_to_get_jobs_data = true;
		}

		return $ate_jobs_data;
	}

	public function get_editor_url( $default_url, $job_id, $return_url = null ) {
		$isUserActivated = $this->translator_activation_records->is_current_user_activated();

		if ( $isUserActivated || is_admin() ) {
			$ate_job_id = $this->ate_jobs->get_ate_job_id( $job_id );
			if ( $ate_job_id ) {
				if ( ! $return_url ) {
					$return_url = add_query_arg(
						array(
							'page'           => WPML_TM_FOLDER . '/menu/translations-queue.php',
							'ate-return-job' => $job_id,
						),
						admin_url( '/admin.php' )
					);
				}
				$ate_job_url = $this->ate_api->get_editor_url( $ate_job_id, $return_url );
				if ( $ate_job_url && ! is_wp_error( $ate_job_url ) ) {
					return $ate_job_url;
				}
			}
		}

		return $default_url;
	}

	public function get_ate_jobs_data_filter( $ignore, array $translation_jobs ) {
		return $this->get_ate_jobs_data( $translation_jobs );
	}

	private function get_ate_job_id( $job_id ) {
		return $this->ate_jobs->get_ate_job_id( $job_id );
	}

	protected function check_response_error( $response ) {
		if ( is_wp_error( $response ) ) {
			$code    = 0;
			$message = $response->get_error_message();
			if ( $response->error_data && is_array( $response->error_data ) ) {
				foreach ( $response->error_data as $error_data ) {
					$code    = (int) Obj::pathOr( 0, [ 0, 'status' ], $error_data );
					$message = ( $code ? $code . ' ' : '' ) . Obj::pathOr( '', [ 0, 'message' ], $error_data ) . "\n\n";

					switch ( $code ) {
						case self::RESPONSE_ATE_NOT_ACTIVE_ERROR:
							$wp_admin_url  = admin_url( 'admin.php' );
							$mcsetup_page  = add_query_arg(
								array(
									'page' => WPML_TM_FOLDER . WPML_Translation_Management::PAGE_SLUG_SETTINGS,
									'sm'   => 'mcsetup',
								),
								$wp_admin_url
							);
							$mcsetup_page .= '#ml-content-setup-sec-1';
							$message      .= __( 'WPML cannot send these documents to translation because the Advanced Translation Editor is not fully set-up yet.', 'wpml-translation-management' )
											. "\n\n"
											. __( 'Please open the confirmation email that you received and click on the link inside it to confirm your email.', 'wpml-translation-management' )
											. "\n\n"
											. '<a href="' . esc_attr( $mcsetup_page ) . '">' . esc_html__( 'Resend that email.', 'wpml-translation-management' ) . '</a>';
							break;
						case self::RESPONSE_ATE_DUPLICATED_SOURCE_ID:
						case self::RESPONSE_ATE_UNEXPECTED_ERROR:
						default:
							$message .= __( 'Advanced Translation Editor error.', 'wpml-translation-management' );
					}
				}
			}
			throw new RuntimeException( $message, $code );
		}
	}

	private function resign_job_on_error( $ate_job_id ) {
		$job_id = $this->ate_jobs->get_wpml_job_id( $ate_job_id );
		if ( $job_id ) {
			wpml_load_core_tm()->resign_translator( $job_id );
		}
	}

	private function is_ate_translation_job( $translation_job ) {
		return 'local' === $translation_job->translation_service
			   && WPML_TM_Editors::ATE === $translation_job->editor;
	}

	private function getResponsesJobs( $responses, $sentJobs ) {
		$jobs = [];

		foreach ( $responses as $response ) {
			try {
				$this->check_response_error( $response );

				if ( $response && isset( $response->jobs ) ) {
					$jobs = $jobs + (array) $response->jobs;
				}
			} catch ( RuntimeException $ex ) {
				do_action( 'wpml_tm_basket_add_message', 'error', $ex->getMessage() );
			}
		}

		$existingJobs = wpml_collect( $sentJobs )
			->filter( Obj::prop( 'existing_ate_id' ) )
			->map( Obj::pick( [ 'source_id', 'existing_ate_id' ] ) )
			->keyBy( 'source_id' )
			->map( Obj::prop( 'existing_ate_id' ) )
			->toArray();

		return $jobs + $existingJobs;
	}

	private function getChunkedJobs( $jobs, $translationModeSetInDashboard ) {
		usort(
			$jobs,
			function ( $a, $b ) {
				$tierA = isset( $a->tier ) ? (int) $a->tier : PHP_INT_MAX;
				$tierB = isset( $b->tier ) ? (int) $b->tier : PHP_INT_MAX;
				if ( $tierA !== $tierB ) {
					return $tierA - $tierB;
				}
				$rankA = $a->rank ?? [];
				$rankB = $b->rank ?? [];
				foreach ( array_keys( array_merge( $rankA, $rankB ) ) as $i ) {
					$ra = $rankA[ $i ] ?? PHP_INT_MAX;
					$rb = $rankB[ $i ] ?? PHP_INT_MAX;
					if ( $ra !== $rb ) {
						return $ra <=> $rb;
					}
				}
				return 0;
			}
		);
		$chunkedJobs      = [];
		$currentChunk     = -1;
		$currentWordCount = 0;
		$chunkType = 'auto';

		$newChunk = function( $chunkType ) use ( &$chunkedJobs, &$currentChunk, &$currentWordCount ) {
			$currentChunk ++;
			$currentWordCount             = 0;
			$chunkedJobs[ $currentChunk ] = [ 'type' => $chunkType, 'jobs' => [] ];
		};

		$newChunk( $chunkType );

		foreach ( $jobs as $job ) {

			$translationJob = wpml_tm_load_job_factory()->get_translation_job( $job->id, false, 0, true );
			if ( $translationJob ) {

				if ( ! Obj::prop( 'existing_ate_id', $job ) ) {
					$currentWordCount += $translationJob->estimate_word_count();
				}

				$jobType = $this->getJobType( $translationJob, $translationModeSetInDashboard );
				if ( $jobType !== $chunkType ) {
					$chunkType = $jobType;
					$newChunk( $chunkType );
				}
				if ( $currentWordCount > self::CREATE_ATE_JOB_CHUNK_WORDS_LIMIT && count( $chunkedJobs[ $currentChunk ] ) > 0 ) {
					$newChunk( $chunkType );
				}
			}

			$chunkedJobs[ $currentChunk ]['jobs'] [] = $job;

		}

		$hasJobs = pipe( Obj::prop( 'jobs' ), Lst::length() );

		return Fns::filter( $hasJobs, $chunkedJobs );
	}

	private function logRetryError( $jobId ) {
		$job = Jobs::get( $jobId );

		if ( isset( $job->ate_comm_retry_count ) && $job->ate_comm_retry_count ) {
			Storage::add( Entry::retryJob( $jobId,
				[
					'retry_count' => $job->ate_comm_retry_count
				]
			) );
		}
	}

	private function logError( $jobId, string $additionalErrorMsg = '' ) {
		$job = Jobs::get( $jobId );
		if ( $job ) {
			$extraData = [
				'retry_count' => 0,
				'comment'     => 'Sending job to ate failed, queued to be sent again.',
			];
			if ( $additionalErrorMsg ) {
				$extraData['errorMessage'] = $additionalErrorMsg;
			}

			Storage::add( Entry::retryJob( $jobId, $extraData ) );
		}
	}

	private function getJobType( $translationJob, $translationModeSetInDashboard ) {
		$document = $translationJob->get_original_document();
		if ( ! $document ) {
			return 'manual';
		} elseif ( $translationModeSetInDashboard ) {
			return $translationModeSetInDashboard;
		} else {
			return $translationJob->get_source_language_code() === Languages::getDefaultCode() &&
				   Jobs::isEligibleForAutomaticTranslations( $translationJob->get_id() ) ? 'auto' : 'manual';
		}
	}

	private function shouldApplyTranslationMemory(?\WPML_TM_Translation_Batch $batch = null) {
		if ( $this->getTranslationModeFromBatch( $batch ) === 'auto' ) {
			return ! ( $batch &&  $batch->getHowToHandleExisting() === \WPML_TM_Translation_Batch::HANDLE_EXISTING_OVERRIDE );
		} else {
			return true;
		}
	}

	private function getTranslationModeFromBatch(?\WPML_TM_Translation_Batch $batch = null) {
		return $batch ? $batch->getTranslationMode() : null;
	}

	private function maybeLogRuntimeError( string $message ) {
		JobLog::addError(
			'WPML_TM_ATE_Jobs_Actions error',
			[
				'message' => $message,
			]
		);

		if ( $this->wp_api->constant( 'WP_DEBUG' ) ) {
			$this->wp_api->error_log( $message );
		}
	}

	private function getErrorMessage( \Throwable $throwable ): string {
		return 'Error: ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ':' . $throwable->getLine();
	}
}
