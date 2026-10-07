<?php

use WPML\TM\ATE\API\FingerprintGenerator;
use WPML\TM\ATE\Log\Entry;
use WPML\TM\ATE\Log\EventsTypes;
use WPML\TM\ATE\ClonedSites\ApiCommunication as ClonedSitesHandler;
use WPML\FP\Obj;
use WPML\FP\Fns;
use WPML\FP\Either;
use WPML\FP\Lst;
use WPML\FP\Logic;
use WPML\FP\Str;
use WPML\Element\API\Entity\LanguageMapping;
use WPML\LIB\WP\WordPress;
use WPML\TM\Editor\ATEDetailedErrorMessage;
use WPML\TM\Jobs\JobLog;
use function WPML\FP\invoke;
use function WPML\FP\pipe;
use WPML\Element\API\Languages;
use WPML\FP\Relation;
use WPML\FP\Maybe;
use WPML\TM\ATE\API\RequestException;
use WPML\Translation\AteSyncOrderingServiceFactory;

class WPML_TM_ATE_API {

	const TRANSLATED = 6;
	const DELIVERING = 7;
	const NOT_ENOUGH_CREDIT_STATUS = 31;
	private $wp_http;
	private $auth;
	private $endpoints;

	private static $forbidden_requests = [];

	private $clonedSitesHandler;

	private $fingerprintGenerator;

	public function __construct(
		WP_Http $wp_http,
		WPML_TM_ATE_Authentication $auth,
		WPML_TM_ATE_AMS_Endpoints $endpoints,
		ClonedSitesHandler $clonedSitesHandler,
		FingerprintGenerator $fingerprintGenerator
	) {
		$this->wp_http              = $wp_http;
		$this->auth                 = $auth;
		$this->endpoints            = $endpoints;
		$this->clonedSitesHandler   = $clonedSitesHandler;
		$this->fingerprintGenerator = $fingerprintGenerator;
	}

	public function create_jobs( array $params ) {
		return $this->requestWithLog(
			$this->endpoints->get_ate_jobs(),
			[
				'method' => 'POST',
				'body'   => $params,
			]
		);
	}

	public function confirm_received_job( $ate_job_id ) {
		return $this->requestWithLog( $this->endpoints->get_ate_confirm_job( $ate_job_id ) );
	}

	public function cancelJobs( $jobIds, $onlyFailed = false ) {
		return $this->requestWithLog(
			$this->endpoints->getAteCancelJobs(),
			[
				'method' => 'POST',
				'body'   => [
					'id' => (array) $jobIds,
					'only_failed' => $onlyFailed
				]
			]
		);
	}

	public function hideJobs( $jobIds, $force = false ) {
		return $this->requestWithLog(
			$this->endpoints->getAteHideJobs(),
			[
				'method' => 'POST',
				'body'   => [
					'id' => (array) $jobIds,
					'force' => $force
				]
			]
		);
	}

	public function get_editor_url( $job_id, $return_url ) {
		$lock = $this->clonedSitesHandler->checkCloneSiteLock();
		if ( $lock ) {
			JobLog::addError( 'manual_editor_url_blocked_cloned_site_lock', [
				'job_id' => $job_id,
			] );
			return new WP_Error( 'communication_error', 'ATE communication is locked, please update configuration' );
		}

		$translator_email = filter_var( wp_get_current_user()->user_email, FILTER_SANITIZE_URL );
		$return_url = filter_var( $return_url, FILTER_SANITIZE_URL );

		$url = $this->endpoints->get_ate_editor();

		$url = str_replace(
			[
				'{job_id}',
				'{translator_email}',
				'{return_url}',
				'{wpml_ph_distinct_id}',
				'{wpml_ph_session_id}',
			],
			[
				$job_id,
				$translator_email ? urlencode( $translator_email ) : '',
				$return_url ? urlencode( $return_url ) : '',
				isset( $_COOKIE['wpml_ph_distinct_id'] ) ? urlencode( $_COOKIE['wpml_ph_distinct_id'] ) : '',
				isset( $_COOKIE['wpml_ph_session_id'] ) ? urlencode( $_COOKIE['wpml_ph_session_id'] ) : '',
			],
			$url
		);

		JobLog::add( 'manual_editor_url_issued', [
			'job_id' => $job_id,
		] );

		return $this->auth->get_signed_url_with_parameters( 'GET', $url, null );
	}

	public function clone_job( $ate_job_id, WPML_Element_Translation_Job $job_object, $sentFrom = null ) {
		$url    = $this->endpoints->get_clone_job( $ate_job_id );
		$params = [
			'id'                  => $ate_job_id,
			'notify_url'          =>
				\WPML\TM\ATE\REST\PublicReceive::get_receive_ate_job_url( $job_object->get_id() ),
			'site_identifier'     => wpml_get_site_id( WPML_TM_ATE::SITE_ID_SCOPE ),
			'source_id'           => wpml_tm_get_records()
				->icl_translate_job_by_job_id( $job_object->get_id() )
				->rid(),
			'permalink'           => $job_object->get_url( true ),
			'ate_ams_console_url' => wpml_tm_get_ams_ate_console_url(),
		];

		if ( $sentFrom ) {
			$params['job_type'] = $sentFrom;
		}

		$result = $this->requestWithLog( $url, [ 'method' => 'POST', 'body' => $params ] );

		if ( ! is_object( $result ) || ! property_exists( $result, 'job_id' ) ) {
			return false;
		}

		return [
			'id'         => $result->job_id,
			'ate_status' => Obj::propOr( WPML_TM_ATE_AMS_Endpoints::ATE_JOB_STATUS_CREATED, 'status', $result ),
		];
	}

	public function get_job( $ate_job_id ) {
		if ( ! $ate_job_id ) {
			return null;
		}

		return $this->requestWithLog( $this->endpoints->get_ate_jobs( $ate_job_id ) );
	}

	public function get_jobs( $job_ids, $statuses = null ) {
		return $this->requestWithLog( $this->endpoints->get_ate_jobs( $job_ids, $statuses ) );
	}

	public function get_job_status_with_priority( $job_id ) {
		return $this->requestWithLog(
			$this->endpoints->get_ate_job_status(),
			[
				'method' => 'POST',
				'body'   => [ 'id' => $job_id,
				              'preview' => true],
			]
		);

	}

	public function get_jobs_by_wpml_ids( $wpml_job_ids ) {
		return $this->requestWithLog( $this->endpoints->get_ate_jobs_by_wpml_job_ids( $wpml_job_ids ) );
	}

	public function migrate_source_id( array $pairs ) {
		$lock = $this->clonedSitesHandler->checkCloneSiteLock();
		if ( $lock ) {
			return false;
		}

		$verb = 'POST';

		$url = $this->auth->get_signed_url_with_parameters( $verb, $this->endpoints->get_source_id_migration(), $pairs );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$body   = wp_json_encode( $pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = $this->wp_http->request(
			$url,
			array(
				'timeout' => 60,
				'method'  => $verb,
				'headers' => $this->json_headers(),
				'body'    => $body ?: '',
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$result = $this->clonedSitesHandler->handleClonedSiteError( $result );
		}

		return $this->get_response_errors( $result ) === null;
	}

	public function create_language_mapping( array $languagesToMap ) {
		$result = $this->requestWithLog(
			$this->endpoints->getLanguages(),
			[
				'method' => 'POST',
				'body'   => [
					'mappings' => Fns::map( invoke( 'toATEFormat' ), Obj::values( $languagesToMap ) )
				]
			]
		);

		$hasError = Lst::find( Logic::complement( Obj::path( [ 'result', 'created' ] ) ) );

		$logError = Fns::tap( function ( $data ) {
			$entry              = new Entry();
			$entry->eventType   = EventsTypes::SERVER_ATE;
			$entry->description = __( 'Saving of Language mapping to ATE failed', 'wpml-translation-management' );
			$entry->extraData   = $data;

			wpml_tm_ate_ams_log( $entry );
		} );

		return WordPress::handleError( $result )
		                ->map( Obj::prop( 'mappings' ) )
		                ->chain( Logic::ifElse( $hasError, pipe( $logError, Either::left() ), Either::right() ) );
	}

	public function remove_language_mapping( $mappingIds ) {
		$result = $this->requestWithLog(
			$this->endpoints->getDeleteLanguagesMapping(),
			[ 'method' => 'POST', 'body' => [ 'mappings' => $mappingIds ] ]
		);

		return is_wp_error( $result ) ? false : $result;
	}

	public function get_languages_supported_by_automatic_translations( $languageCodes, $sourceLanguage = null ) {
		$sourceLanguage = $sourceLanguage ?: Languages::getDefaultCode();

		$getLanguagesCheckPairs = function () use ( $languageCodes, $sourceLanguage ) {
			return $this->requestWithLog(
				$this->endpoints->getLanguagesCheckPairs(),
				[
					'method' => 'POST',
					'body'   => [
						[
							'source_language'  => $sourceLanguage,
							'target_languages' => $languageCodes,
						]
					]
				],
				__( 'WPML Failed to check language pairs', 'sitepress' )
			);
		};

		$extractData = function ( $response ) use ( $sourceLanguage ) {
			return Maybe::of( $response )
			            ->reject( 'is_wp_error' )
			            ->map( Obj::prop( 'results' ) )
			            ->map( Lst::find( Relation::propEq( 'source_language', $sourceLanguage ) ) )
			            ->map( Obj::prop( 'target_languages' ) );
		};


		$languagePairs = $getLanguagesCheckPairs();
		$result = $extractData( $languagePairs );

		if ( Fns::isNothing( $result ) ) {
			$languagePairs = $getLanguagesCheckPairs();
			$result = $extractData( $languagePairs );
		}

		return $result;
	}

	public function get_language_details( $languageCode, $inTheWebsiteContext = true ) {
		$result = $this->requestWithLog( sprintf( $this->endpoints->getShowLanguage(), $languageCode ), [ 'method' => 'GET' ] );

		return Maybe::of( $result )
		            ->reject( 'is_wp_error' )
		            ->map( Obj::prop( $inTheWebsiteContext ? 'website_language' : 'language' ) );
	}

	public function get_available_languages() {
		$result = $this->requestWithLog( $this->endpoints->getLanguages(), [ 'method' => 'GET' ] );

		return is_wp_error( $result ) ? [] : $result;
	}

	public function get_language_mapping() {
		$result = $this->requestWithLog( $this->endpoints->getLanguagesMapping(), [ 'method' => 'GET' ] );

		return Maybe::of( $result )->reject( 'is_wp_error' );
	}

	public function start_translation_memory_migration() {
		$result = $this->requestWithLog(
			$this->endpoints->startTranlsationMemoryIclMigration(),
			[
				'method' => 'POST',
				'body'   => [
					'site_identifier' => $this->get_website_id( site_url() ),
					'ts_id'           => 10,
					'ts_access_key'   => 20,
				],
			]
		);

		return WordPress::handleError( $result );
	}

	public function check_translation_memory_migration() {
		$result = $this->requestWithLog(
			$this->endpoints->checkStatusTranlsationMemoryIclMigration(),
			[
				'method' => 'GET',
				'body'   => [
					'site_identifier' => $this->get_website_id( site_url() ),
					'ts_id'           => 10,
					'ts_access_key'   => 20,
				],
			]
		);

		return WordPress::handleError( $result );
	}

	public function import_icl_translators( $tsId, $tsAccessKey ) {
		$params = [
			'site_identifier' => $this->auth->get_site_id(),
			'ts_id'           => $tsId,
			'ts_access_key'   => $tsAccessKey
		];

		$result = $this->requestWithLog( $this->endpoints->importIclTranslators(),
			[
				'method' => 'POST',
				'body'   => $params
			] );

		return WordPress::handleError( $result );
	}

	private function get_response( $result ) {
		$errors = $this->get_response_errors( $result );
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}

		return $this->get_response_body( $result );
	}

	private static function extractAppLevelSignals( $result ) {
		$signals = [
			'app_code'    => null,
			'message'     => null,
			'credit_info' => null,
		];

		if ( ! is_array( $result ) || ! isset( $result['body'] ) || ! is_string( $result['body'] ) ) {
			return $signals;
		}

		$body = json_decode( $result['body'], true );
		if ( ! is_array( $body ) ) {
			return $signals;
		}

		if ( isset( $body['code'] ) && is_numeric( $body['code'] ) ) {
			$signals['app_code'] = (int) $body['code'];
		}
		if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			$signals['message'] = substr( $body['message'], 0, 200 );
		}

		$creditKeys = [
			'credits_remaining',
			'remaining_credits',
			'credits_used',
			'used_credits',
			'credit_balance',
			'credits',
			'account_status',
			'subscription_status',
			'state',
			'limit',
			'limit_reached',
		];
		$creditInfo = [];
		foreach ( $creditKeys as $key ) {
			if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
				$creditInfo[ $key ] = $body[ $key ];
			}
		}
		if ( ! empty( $creditInfo ) ) {
			$signals['credit_info'] = $creditInfo;
		}

		return $signals;
	}

	private function get_response_body( $result ) {
		if ( is_array( $result ) && array_key_exists( 'body', $result ) ) {
			$body = json_decode( $result['body'] );

			if ( isset( $body->authenticated ) && ! (bool) $body->authenticated ) {
				return new WP_Error(
					'ate_auth_failed',
					isset( $body->message ) ? $body->message : ''
				);
			}

			return $body;
		}

		return $result;
	}

	private function get_response_errors( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response_errors = null;

		$response = (array) $response;
		if ( array_key_exists( 'body', $response ) && $response['response']['code'] >= 400 ) {
			$errors = array();

			$response_body = json_decode( $response['body'], true );

			if ( is_array( $response_body ) && array_key_exists( 'errors', $response_body ) ) {
				$errors = $response_body['errors'];
			}

			$response_errors = new WP_Error( $response['response']['code'], $response['response']['message'], $errors );
		}

		return $response_errors;
	}

	private function json_headers() {
		return [
			'Accept'                                      => 'application/json',
			'Content-Type'                                => 'application/json',
			FingerprintGenerator::SITE_FINGERPRINT_HEADER => $this->fingerprintGenerator->getSiteFingerprint(),
		];
	}

	private function encode_body_args( array $args ) {
		return wp_json_encode( $args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public function get_remote_xliff_content( $xliff_url, $job = null ) {

		$avoidLogDuplication = false;
		try {
			$response = $this->wp_http->get($xliff_url, array(
				'timeout' => min(30, ini_get('max_execution_time') ?: 10)
			));
		} catch ( \Error $e ) {
			$response = new \WP_Error(
				'ate_request_failed',
				'Started attempt to download xliff file. The process did not finish.',
				[ 'errorMessage' => $e->getMessage(), 'debugTrace' => $e->getTraceAsString() ]
			);
			$avoidLogDuplication = true;
		}

		if ( is_wp_error( $response ) ) {
			throw new RequestException(
				$response->get_error_message(),
				$response->get_error_code(),
				$response->get_error_data(),
			0,
				$avoidLogDuplication
			);
		}

		if ( class_exists( \WPML\TM\Jobs\JobLog::class ) ) {
			\WPML\TM\Jobs\JobLog::add( 'xliff_downloaded', [
				'job_id'     => Obj::prop( 'jobId', $job ),
				'ate_job_id' => Obj::prop( 'ateJobId', $job ),
				'size_bytes' => strlen( $response['body'] ),
				'sha1'       => sha1( $response['body'] ),
			] );
		}

		return $response['body'];
	}

	public function override_site_id( $site_id ) {
		$this->auth->override_site_id( $site_id );
	}

	public function get_website_id( $site_url ) {
		$lock = $this->clonedSitesHandler->checkCloneSiteLock();
		if ( $lock ) {
			return null;
		}

		$signed_url = $this->auth->get_signed_url_with_parameters( 'GET', $this->endpoints->get_websites() );
		if ( is_wp_error( $signed_url ) ) {
			return null;
		}

		$requestArguments = [ 'headers' => $this->json_headers() ];

		$response = $this->wp_http->request( $signed_url, $requestArguments );

		if ( ! is_wp_error( $response ) ) {
			$response = $this->clonedSitesHandler->handleClonedSiteError( $response );
		}

		$sites = $this->get_response( $response );

		foreach ( $sites as $site ) {
			if ( $site->url === $site_url ) {
				return $site->uuid;
			}
		}

		return null;
	}


	public function get_website_context() {
		return $this->requestWithLog( $this->endpoints->get_website_context() );
	}

	public function get_jobs_to_retranslation( int $page = 1 ) {
		try {
			$result = $this->requestWithLog(
				$this->endpoints->get_retranslate(),
				[
					'method' => 'GET',
					'body'   => [ 'page_number' => $page ],
				]
			);
		} catch ( \Exception $e ) {
			$result = new \WP_Error( $e->getCode(), $e->getMessage() );
		}

		return WordPress::handleError( $result );
	}


	public function sync_all( array $ateJobIds, array $postIds = [], array $stringIds = [], array $packageIds = [] ) {
		$orderingService = AteSyncOrderingServiceFactory::create();
		$payload         = $orderingService->buildSyncPayload( $ateJobIds, $postIds, $stringIds, $packageIds );

		return $this->requestWithLog(
			$this->endpoints->get_sync_all(),
			[
				'method' => 'POST',
				'body'   => $payload,
			]
		);
	}

	public function sync_page( $token, $page ) {
		return $this->requestWithLog( $this->endpoints->get_sync_page( $token, $page ) );
	}

	private function request( $url, array $requestArgs = [] ) {
		$lock = $this->clonedSitesHandler->checkCloneSiteLock( $url );
		if ( $lock ) {
			JobLog::add(
				'ATE WPML_TM_ATE_API request lock check failed',
				[
					'url'         => $url,
					'requestArgs' => $requestArgs,
				]
			);
			return $lock;
		}

		if ( isset( self::$forbidden_requests[ $url ] ) ) {
			return self::$forbidden_requests[ $url ];
		}

		$requestArgs = array_merge(
			[
				'timeout' => 60,
				'method'  => 'GET',
				'headers' => $this->json_headers(),
			],
			$requestArgs
		);

		$bodyArgs = isset( $requestArgs['body'] ) && is_array( $requestArgs['body'] )
			? $requestArgs['body'] : null;

		$signedUrl = $this->auth->get_signed_url_with_parameters( $requestArgs['method'], $url, $bodyArgs );

		if ( is_wp_error( $signedUrl ) ) {
			return $signedUrl;
		}

		JobLog::addExtraLogData( 'apiCall', $url );
		JobLog::add(
			'WPML_TM_ATE_API request',
			[
				'url'       => $url,
				'signedUrl' => $signedUrl,
				'method'    => $requestArgs['method'],
				'headers'   => $requestArgs['headers'] ?? null,
				'timeout'   => $requestArgs['timeout'] ?? null,
				'bodyArgs'  => $bodyArgs,
			]
		);

		if ( $bodyArgs && $requestArgs['method'] !== 'GET' ) {
			$requestArgs['body'] = $this->encode_body_args( $bodyArgs );
		}

		$result = $this->wp_http->request( $signedUrl, $requestArgs );

		$appSignals = self::extractAppLevelSignals( $result );

		JobLog::add(
			'WPML_TM_ATE_API request response',
			[
				'result'   => $result,
				'app_code' => $appSignals['app_code'],
				'credit'   => $appSignals['credit_info'],
			]
		);

		$httpCode = is_array( $result ) && isset( $result['response']['code'] )
			? (int) $result['response']['code']
			: null;
		if ( $httpCode !== null && ( $httpCode < 200 || $httpCode >= 400 ) ) {
			$bodyExcerpt = is_array( $result ) && isset( $result['body'] ) && is_string( $result['body'] )
				? substr( $result['body'], 0, 500 )
				: '';
			JobLog::addError(
				'WPML_TM_ATE_API response HTTP error',
				[
					'url'          => $url,
					'http_status'  => $httpCode,
					'http_message' => isset( $result['response']['message'] ) ? (string) $result['response']['message'] : '',
					'body_excerpt' => $bodyExcerpt,
				]
			);
		}

		if ( self::NOT_ENOUGH_CREDIT_STATUS === $appSignals['app_code'] ) {
			$bodyExcerpt = is_array( $result ) && isset( $result['body'] ) && is_string( $result['body'] )
				? substr( $result['body'], 0, 500 )
				: '';
			JobLog::addError(
				'ate_credit_exhausted',
				[
					'url'          => $url,
					'app_code'     => $appSignals['app_code'],
					'message'      => $appSignals['message'],
					'credit'       => $appSignals['credit_info'],
					'body_excerpt' => $bodyExcerpt,
				]
			);
		}

		JobLog::removeExtraLogData( 'apiCall' );

		if ( ! is_wp_error( $result ) ) {
			$result = $this->clonedSitesHandler->handleClonedSiteError( $result );
		}

		$response = $this->get_response( $result );

		if (
			is_array( $result )
			&& isset( $result['response']['code'] )
			&& 403 === $result['response']['code']
		) {
			self::$forbidden_requests[ $url ] = $response;
		}

		return $response;
	}

	private function requestWithLog( $url, array $requestArgs = [], $extraMessage = "" ) {
		$response = $this->request( $url, $requestArgs );

		if ( is_wp_error( $response ) ) {
			$entry              = new Entry();
			$entry->eventType   = EventsTypes::SERVER_ATE;
			$entry->description = $response->get_error_message();
			$errorCode = $response->get_error_code();
			$entry->extraData = [
				'url'         => $url,
				'requestArgs' => $requestArgs,
			];

			if ( $extraMessage ) {
				$entry->extraData['extraMessage'] = $extraMessage;
			}

            if ( $errorCode ) {
                $entry->extraData['status'] = $errorCode;
            }
			if ( $response->get_error_data( $errorCode ) ) {
				$entry->extraData['details'] = $response->get_error_data( $errorCode );
			}

			wpml_tm_ate_ams_log( $entry );

			ATEDetailedErrorMessage::saveDetailedError( $response );
		}

		return $response;
	}
}
