<?php

namespace WPML\TM\ATE\Download;

use Exception;
use Error;
use Throwable;
use WPML\Collect\Support\Collection;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\TM\ATE\API\RequestException;
use WPML\TM\ATE\Log\Entry;
use WPML\TM\ATE\Log\EventsTypes;
use WPML\TM\ATE\Review\ReviewStatus;
use WPML_TM_ATE_API;
use WPML\Translation\TranslateJobErrorServiceFactory;
use function WPML\FP\pipe;
use WPML\TM\Jobs\JobLog;

class Process {
	private $consumer;

	private $ateApi;

	private $orphanPostCleaner;

	public function __construct( Consumer $consumer, WPML_TM_ATE_API $ateApi, OrphanPostCleaner $orphanPostCleaner ) {
		$this->consumer          = $consumer;
		$this->ateApi            = $ateApi;
		$this->orphanPostCleaner = $orphanPostCleaner;
	}

	public function run( $jobs ) {
		JobLog::maybeInitRequest();
		JobLog::createNewGroup(
			JobLog::GROUP_ID_DOWNLOAD_JOBS,
			'Downloading jobs',
			[
				'jobs' => $jobs,
			]
		);

		$this->orphanPostCleaner->incrementProcessCounter();
		$this->orphanPostCleaner->recordStateBeforeInsert();

		$appendNeedsReviewAndAutomaticValues = function ( $job ) {
			$data = \WPML\TM\API\Jobs::get( Obj::prop('jobId', $job) );

			$job = Obj::assoc( 'needsReview', ReviewStatus::doesJobNeedReview( $data ), $job );
			$job = Obj::assoc( 'automatic', (bool) Obj::prop( 'automatic', $data ), $job );
			$job = Obj::assoc( 'language_code', Obj::prop( 'language_code', $data ), $job );
			$job = Obj::assoc( 'original_element_id', (int) Obj::prop( 'original_doc_id', $data ), $job );

			return $job;
		};

		$downloadJob = function( $job ) {
			$processedJob = null;

			try {
				$processedJob = $this->consumer->process( $job );

				if ( ! $processedJob ) {
					global $iclTranslationManagement;
					$message = 'The translation job could not be applied.';

					if ( $iclTranslationManagement->messages_by_type( 'error' ) ) {
						$stringifyError = pipe(
							Lst::pluck( 'text' ),
							Lst::join( ' ' )
						);

						$message .= ' ' . $stringifyError(
								$iclTranslationManagement->messages_by_type( 'error ')
							);
					}

					throw new Exception( $message );
				}
			} catch ( Exception $e ) {
				$this->orphanPostCleaner->markCleanupNeeded();
				$this->logException( $e, $processedJob ?: $job );
			} catch ( Error $e ) {
				$this->orphanPostCleaner->markCleanupNeeded();
				$this->logError( $e, $processedJob ?: $job );
			}

			return $processedJob;
		};

		$jobs = \wpml_collect( $jobs )->map( $downloadJob )->filter()->values()->map( $appendNeedsReviewAndAutomaticValues );

		$this->flushElementTranslationsCacheAfterDownloadedJobs( $jobs );

		$this->acknowledgeAte( $jobs );

		do_action( 'wpml_tm_ate_jobs_downloaded', $jobs );
		JobLog::add(
			'Jobs download finished',
			[
				'jobs' => $jobs->toArray(),
			]
		);
		JobLog::finishCurrentGroup();

		$this->orphanPostCleaner->decrementProcessCounter();
		$this->orphanPostCleaner->tryCleanup();

		return $jobs;
	}

	private function flushElementTranslationsCacheAfterDownloadedJobs( Collection $jobs ) {
		if ( ! $jobs->count() || ! defined( 'WPML_ELEMENT_TRANSLATIONS_CACHE_GROUP' ) ) {
			return;
		}

		\WPML\LIB\WP\Cache::flushGroup( WPML_ELEMENT_TRANSLATIONS_CACHE_GROUP, true );

		$cache = new \WPML_WP_Cache( WPML_ELEMENT_TRANSLATIONS_CACHE_GROUP );
		$cache->flush_group_cache( true );

		$this->deleteFrontEndElementTranslationsCache( $jobs );
	}

	private function deleteFrontEndElementTranslationsCache( Collection $jobs ) {
		global $sitepress;

		if ( ! class_exists( 'WPML_Translations' ) || ! $sitepress instanceof \SitePress ) {
			return;
		}

		$elementIds     = $jobs->pluck( 'original_element_id' )->filter()->unique()->values();
		$processedTrids = [];

		foreach ( $elementIds as $elementId ) {
			$postType = get_post_type( (int) $elementId );
			if ( ! $postType ) {
				continue;
			}

			$elementType = 'post_' . $postType;
			$trid        = (int) $sitepress->get_element_trid( (int) $elementId, $elementType );
			if ( ! $trid || isset( $processedTrids[ $trid ] ) ) {
				continue;
			}

			$processedTrids[ $trid ] = true;

			$this->deleteGuestElementTranslationsCacheVariants( $trid, $elementType );
		}
	}

	private function deleteGuestElementTranslationsCacheVariants( $trid, $elementType ) {
		$variants = [
			[ false, false, false ],
			[ true, false, false ],
			[ false, true, false ],
		];

		foreach ( $variants as $variant ) {
			$cacheKey = \WPML_Translations::build_cache_key(
				$trid,
				$elementType,
				$variant[0],
				$variant[1],
				$variant[2],
				false,
				false
			);

			wp_cache_delete( $cacheKey, WPML_ELEMENT_TRANSLATIONS_CACHE_GROUP );
		}
	}

	private function acknowledgeAte( Collection $processedJobs ) {
		if ( $processedJobs->count() ) {
			$this->ateApi->confirm_received_job( $processedJobs->pluck( 'ateJobId' )->toArray() );
		}
	}

	private function logException( Exception $e, $job = null ) {
		$entry              = new Entry();
		$entry->description = $e->getMessage();
		$avoidDuplication   = false;
		$previous           = $e->getPrevious();

		if ( $job ) {
			$entry->ateJobId  = Obj::prop('ateJobId', $job);
			$entry->wpmlJobId = Obj::prop('jobId', $job);
			$entry->extraData = [ 'downloadUrl' => Obj::prop('url', $job) ];
			$this->createJobError( $entry, $previous ? $previous : $e );
		}

		if ( $e instanceof RequestException ) {
			$entry->eventType = EventsTypes::SERVER_XLIFF;
			if ( $e->getData() ) {
				$entry->extraData += is_array( $e->getData() ) ? $e->getData() : [ $e->getData() ];
			}
			$avoidDuplication = $e->shouldAvoidLogDuplication();
		} else {
			$entry->eventType = EventsTypes::JOB_DOWNLOAD;
		}

		wpml_tm_ate_ams_log( $entry, $avoidDuplication );
	}

	private function logError( Error $e, $job = null ) {
		$entry              = new Entry();
		$entry->description = sprintf( '%s %s:%s', $e->getMessage(), $e->getFile(), $e->getLine() );
		$previous           = $e->getPrevious();
		if ( $job ) {
			$entry->ateJobId  = Obj::prop( 'ateJobId', $job );
			$entry->wpmlJobId = Obj::prop( 'jobId', $job );
			$entry->extraData = [ 'downloadUrl' => Obj::prop( 'url', $job ) ];
			$this->createJobError( $entry, $previous ? $previous : $e );
		}

		$entry->eventType = EventsTypes::JOB_DOWNLOAD;

		wpml_tm_ate_ams_log( $entry, true );
	}

	private function createJobError( $entry, $error ) {
		$jobId     = Obj::prop( 'wpmlJobId', $entry );
		$ateJobId  = Obj::prop( 'ateJobId', $entry );
		$message   = Obj::prop( 'description', $entry );
		$errorData = $this->convertErrorToArray( $error );

		$service = TranslateJobErrorServiceFactory::create();
		$service->logError( $jobId, $ateJobId, 'DownloadError', $message, $errorData );
	}


	private function convertErrorToArray( $error ) {
		$trace      = $error->getTrace();
		$stackTrace = [];

		foreach ( $trace as $traceItem ) {
			$file = isset( $traceItem['file'] ) ? $traceItem['file'] : '[internal function]';
			$line = isset( $traceItem['line'] ) ? $traceItem['line'] : 0;

			$stackTrace[] = $file . ':' . $line;
		}

		return [
			'message' => $error->getMessage(),
			'code'    => $error->getCode(),
			'file'    => $error->getFile(),
			'line'    => $error->getLine(),
			'trace'   => $stackTrace,
		];
	}
}
