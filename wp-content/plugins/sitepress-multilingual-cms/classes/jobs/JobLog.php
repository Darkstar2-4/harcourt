<?php


namespace WPML\TM\Jobs;

class JobLog {


	const IS_DISABLED_OPTION_NAME = 'wpml_tm_job_log_is_disabled';

	const LOG_TYPE_INFO  = 0;
	const LOG_TYPE_ERROR = 1;

	const GROUP_ID_SEND_JOBS = 0;

	const GROUP_ID_SYNC_JOBS = 1;

	const GROUP_ID_DOWNLOAD_JOBS = 2;

	const GROUP_ID_SITE_MIGRATION = 3;

	const GROUP_ID_CLONED_SITE_ACTIONS = 4;

	const GROUP_ID_TRANSLATE_EVERYTHING = 5;

	const GROUP_ID_JOB_LIFECYCLE = 6;

	const MAX_DEPTH         = 10;
	const MAX_STRING_LENGTH = 1000;
	const MAX_ARRAY_ITEMS   = 1000;

	const MAX_LINE_BYTES = 65536;

	const MAX_EVENTS_PER_REQUEST = 10000;

	const MAX_EXTRA_LOG_DATA_BYTES = 1024;

	const MAX_INPUT_BYTES = 1048576;

	const SECRET_KEY_NEEDLES = [
		'authorization',
		'cookie',
		'password',
		'passwd',
		'secret',
		'token',
		'bearer',
		'signature',
		'api_key',
		'apikey',
		'shared_key',
		'access_key',
		'private_key',
		'signing_key',
		'site_key',
		'license_key',
		'x_api',
		'x_auth',
	];

	private static $isInitialised = false;

	private static $hasRequestAnyErrorLog = false;

	private static $isEnabled;

	private static $requestUrl;

	private static $requestParams = [];

	private static $requestDateTime;

	private static $logUid;

	private static $requestStartTime;

	private static $groupId;

	private static $extraLogData = [];

	private static $stringBatchIds = [];

	private static $alreadyAddedToLog = [];

	private static $seenTraceHashes = [];

	private static $fp;

	private static $inProgressPath;

	private static $streamOpenAttempted = false;

	private static $streamOpenFailed = false;

	private static $requestStartedWritten = false;

	private static $requestFinishedWritten = false;

	private static $writeFailureReported = false;

	private static $orphanAddReported = false;

	private static $logEventCount = 0;

	private static $eventsTruncatedReported = false;

	private static $phpPid;

	private static $ID_KEY_MAP = [
		'rid'                  => 'rids',
		'wpmlJobId'            => 'rids',

		'job_id'               => 'job_ids',
		'jobId'                => 'job_ids',

		'ateJobId'             => 'ate_job_ids',
		'ate_job_id'           => 'ate_job_ids',
		'editor_job_id'        => 'ate_job_ids',

		'post_id'              => 'post_ids',
		'new_post_id'          => 'post_ids',
		'source_post_id'       => 'post_ids',
		'original_doc_id'      => 'post_ids',
		'original_element_id'  => 'post_ids',
		'originalElementId'    => 'post_ids',

		'element_id'           => 'element_ids',
		'elementId'            => 'element_ids',

		'trid'                 => 'trids',

		'target_lang'          => 'target_langs',
		'language_code'        => 'target_langs',
		'source_lang'          => 'source_langs',
		'source_language_code' => 'source_langs',

		'element_type'         => 'element_types',
	];

	private static $idsTouched = [];

	public static function isSendJobsLogsGroup( $groupId ) {
		return self::GROUP_ID_SEND_JOBS === (int) $groupId;
	}

	public static function init() {
		self::$isInitialised = true;
		add_action( 'shutdown', [ __CLASS__, 'shutdown' ], PHP_INT_MAX );
		add_action( 'wpml_after_save_post', [ __CLASS__, 'onSourcePostSaved' ], 10, 4 );

		register_shutdown_function( [ __CLASS__, 'onPhpShutdown' ] );
	}

	public static function onPhpShutdown() {
		static $alreadyRan = false;
		if ( $alreadyRan ) {
			return;
		}
		$alreadyRan = true;

		if ( ! self::wasRequestInitialised() ) {
			return;
		}

		if ( ! is_resource( self::$fp ) ) {
			return;
		}

		$error   = error_get_last();
		$isFatal = is_array( $error )
			&& in_array(
				(int) ( $error['type'] ?? 0 ),
				[ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ],
				true
			);

		if ( $isFatal ) {
			self::writeLine(
                [
					'type'    => 'log',
					'ts'      => microtime( true ),
					'groupId' => self::$groupId,
					'id'      => 'php_fatal',
					'data'    => [
						'error_type'      => (int) $error['type'],
						'error_type_name' => self::phpErrorTypeName( (int) $error['type'] ),
						'message'         => substr( (string) ( $error['message'] ?? '' ), 0, 1000 ),
						'file'            => (string) ( $error['file'] ?? '' ),
						'line'            => isset( $error['line'] ) ? (int) $error['line'] : null,
					],
					'logType' => self::LOG_TYPE_ERROR,
				]
            );
			self::$hasRequestAnyErrorLog = true;
		}

		if ( ! self::$requestFinishedWritten ) {
			self::writeLine(
                [
					'type'          => 'request_finished',
					'ts'            => microtime( true ),
					'hasErrorLogs'  => self::$hasRequestAnyErrorLog,
					'requestParams' => self::dataToArray( self::$requestParams ),
					'ids'           => self::buildEntityIdsSummary(),
					'aborted'       => true,
				]
            );
			self::$requestFinishedWritten = true;
		}

		FsJobLogStorage::finaliseStream( self::$fp, self::$inProgressPath );
		self::$fp             = null;
		self::$inProgressPath = null;
	}

	public static function safeCall( $obj, $method, $default = null ) {
		if ( ! is_object( $obj ) || ! method_exists( $obj, $method ) ) {
			return $default;
		}
		try {
			return $obj->$method();
		} catch ( \Throwable $e ) {
			return $default;
		}
	}

	public static function safeProp( $obj, $property, $default = null ) {
		if ( ! is_object( $obj ) ) {
			return $default;
		}
		try {
			if ( ! isset( $obj->$property ) ) {
				return $default;
			}
			return $obj->$property;
		} catch ( \Throwable $e ) {
			return $default;
		}
	}

	private static function phpErrorTypeName( $type ) {
		$map = [
			E_ERROR             => 'E_ERROR',
			E_PARSE             => 'E_PARSE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_WARNING           => 'E_WARNING',
			E_NOTICE            => 'E_NOTICE',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
		];
		return isset( $map[ $type ] ) ? $map[ $type ] : 'UNKNOWN(' . $type . ')';
	}

	public static function onSourcePostSaved( $post_id, $trid = null, $language_code = null, $source_language = null ) {
		if ( ! self::canLog() ) {
			return;
		}
		if ( ! empty( $source_language ) ) {
			return;
		}
		if ( ! $trid ) {
			return;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}

		global $wpdb;

		$hasInFlight = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				 FROM {$wpdb->prefix}icl_translations t
				 INNER JOIN {$wpdb->prefix}icl_translation_status s ON s.translation_id = t.translation_id
				 WHERE t.trid = %d
				   AND s.status IN (1, 2, 3)
				 LIMIT 1",
				(int) $trid
			)
		);

		if ( ! $hasInFlight ) {
			return;
		}

		$verbose = defined( 'WPML_JOB_LOG_VERBOSE_SOURCE_EDIT' ) && WPML_JOB_LOG_VERBOSE_SOURCE_EDIT;

		$pending          = [];
		$pendingJobCount  = 0;

		if ( $verbose ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT j.job_id,
					        j.rid,
					        s.status,
					        t.language_code,
					        UNIX_TIMESTAMP() - UNIX_TIMESTAMP(s.timestamp) AS age_seconds
					 FROM {$wpdb->prefix}icl_translate_job j
					 INNER JOIN {$wpdb->prefix}icl_translation_status s ON s.rid = j.rid
					 INNER JOIN {$wpdb->prefix}icl_translations t       ON t.translation_id = s.translation_id
					 WHERE t.trid = %d
					   AND j.translated = 0
					 LIMIT 50",
					(int) $trid
				),
				ARRAY_A
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$pending[] = [
						'job_id'      => (int) $row['job_id'],
						'rid'         => (int) $row['rid'],
						'target_lang' => isset( $row['language_code'] ) ? (string) $row['language_code'] : '',
						'status'      => isset( $row['status'] ) ? (int) $row['status'] : null,
						'age_seconds' => isset( $row['age_seconds'] ) ? (int) $row['age_seconds'] : 0,
					];
				}
				$pendingJobCount = count( $pending );
			}
		} else {
			$pendingJobCount = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}icl_translate_job j
					 INNER JOIN {$wpdb->prefix}icl_translation_status s ON s.rid = j.rid
					 INNER JOIN {$wpdb->prefix}icl_translations t       ON t.translation_id = s.translation_id
					 WHERE t.trid = %d
					   AND j.translated = 0",
					(int) $trid
				)
			);
		}

		if ( $pendingJobCount === 0 ) {
			return;
		}

		$ownsGroup = ! self::isGroupOpen();
		if ( $ownsGroup ) {
			self::createNewGroup( self::GROUP_ID_JOB_LIFECYCLE, 'Source post saved with pending translations', [] );
		}
		try {
			$payload = [
				'post_id'           => (int) $post_id,
				'trid'              => (int) $trid,
				'source_lang'       => $language_code,
				'pending_job_count' => $pendingJobCount,
			];
			if ( $verbose ) {
				$payload['pending_jobs'] = $pending;
			}
			self::addError( 'source_edited_with_pending_translation', $payload );
		} finally {
			if ( $ownsGroup ) {
				self::finishCurrentGroup();
			}
		}
	}

	public static function resetForTests() {
		if ( is_resource( self::$fp ) ) {
			@fclose( self::$fp );
		}
		self::$isInitialised          = false;
		self::$hasRequestAnyErrorLog  = false;
		self::$isEnabled              = null;
		self::$requestUrl             = null;
		self::$requestParams          = [];
		self::$requestDateTime        = null;
		self::$logUid                 = null;
		self::$requestStartTime       = null;
		self::$groupId                = null;
		self::$extraLogData           = [];
		self::$stringBatchIds         = [];
		self::$alreadyAddedToLog      = [];
		self::$seenTraceHashes        = [];
		self::$fp                     = null;
		self::$inProgressPath         = null;
		self::$streamOpenAttempted    = false;
		self::$streamOpenFailed       = false;
		self::$requestStartedWritten  = false;
		self::$requestFinishedWritten = false;
		self::$writeFailureReported   = false;
		self::$orphanAddReported      = false;
		self::$phpPid                 = null;
		self::$idsTouched             = [];
		self::$logEventCount          = 0;
		self::$eventsTruncatedReported = false;
	}

	public static function isEnabled() {
		if ( ! self::$isInitialised ) {
			return false;
		}

		if ( ! is_null( self::$isEnabled ) ) {
			return self::$isEnabled;
		}

		if ( self::isCliOrCronContext() ) {
			return self::$isEnabled = false;
		}

		global $sitepress;
		if ( ! $sitepress ) {
			return false;
		}

		$isDisabled             = (bool) $sitepress->get_setting( self::IS_DISABLED_OPTION_NAME, false );
		return self::$isEnabled = ! $isDisabled;
	}

	private static function isCliOrCronContext() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( PHP_SAPI === 'cli' ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return false;
	}

	public static function canLog() {
		return self::wasRequestInitialised() && self::isEnabled();
	}

	public static function setIsEnabled( $isEnabled ) {
		global $sitepress;
		$sitepress->set_setting( self::IS_DISABLED_OPTION_NAME, ! (bool) $isEnabled );
		$sitepress->save_settings();
	}

	public static function maybeInitRequest() {
		if ( ! is_null( self::$requestDateTime ) ) {
			return;
		}

		self::$requestUrl       = $_SERVER['REQUEST_URI'] ?? null;
		self::$requestParams    = self::getUrlParams();
		self::$requestDateTime  = gmdate( 'Y-m-d\TH:i:s\Z' );
		self::$logUid           = uniqid();
		self::$requestStartTime = isset( $_SERVER['REQUEST_TIME_FLOAT'] )
			? (float) $_SERVER['REQUEST_TIME_FLOAT']
			: microtime( true );
	}

	public static function wasRequestInitialised() {
		return is_string( self::$requestUrl );
	}

	public static function isGroupOpen() {
		return self::$groupId !== null;
	}

	public static function createNewGroup( $groupId, $groupLabel = '', $groupData = [] ) {
		if ( ! self::canLog() ) {
			return;
		}

		if ( ! self::ensureStreamOpen() ) {
			return;
		}

		self::$groupId = $groupId;

		self::$alreadyAddedToLog = [];

		if ( is_array( $groupData ) ) {
			self::recordEntityIds( $groupData );
		}

		self::writeLine(
            [
				'type'    => 'group_started',
				'ts'      => microtime( true ),
				'groupId' => $groupId,
				'label'   => $groupLabel,
				'data'    => self::dataToArray( $groupData ),
			]
        );
	}

	public static function finishCurrentGroup() {
		if ( ! self::canLog() ) {
			return;
		}

		if ( self::$groupId === null ) {
			return;
		}

		if ( ! self::ensureStreamOpen() ) {
			self::$groupId      = null;
			self::$extraLogData = [];
			return;
		}

		self::writeLine(
            [
				'type'    => 'group_finished',
				'ts'      => microtime( true ),
				'groupId' => self::$groupId,
			]
        );

		self::$groupId = null;

		self::$extraLogData = [];
	}

	public static function addExtraLogData( $key, $value ) {
		if ( ! self::canLog() ) {
			return;
		}

		if (
			$key === 'element_id'
			&& isset( self::$extraLogData['type'] )
			&& self::$extraLogData['type'] === 'st-batch'
		) {
			if ( ! in_array( $value, self::$stringBatchIds, true ) ) {
				self::$stringBatchIds[] = $value;
			}
		}

		self::$alreadyAddedToLog = [];
		$bounded                 = self::dataToArray( $value );

		$encoded       = json_encode( $bounded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$originalBytes = is_string( $encoded ) ? strlen( $encoded ) : 0;
		if ( $originalBytes > self::MAX_EXTRA_LOG_DATA_BYTES ) {
			$bounded = [
				'_truncated'                  => true,
				'_too_big_for_extra_log_data' => $originalBytes,
				'_cap_bytes'                  => self::MAX_EXTRA_LOG_DATA_BYTES,
			];
		}
		self::$extraLogData[ $key ] = $bounded;

		self::recordEntityIds( [ $key => $value ] );
	}

	public static function removeExtraLogData( $key ) {
		if ( ! self::canLog() ) {
			return;
		}

		unset( self::$extraLogData[ $key ] );
	}

	public static function add( $id, $data = [], $logType = self::LOG_TYPE_INFO ) {
		if ( ! self::canLog() ) {
			return;
		}

		if ( self::$groupId === null ) {
			if (
				defined( 'WPML_JOB_LOG_SAVE_ORPHAN_GROUPS_CALLS' )
				&& ! self::$orphanAddReported
			) {
				self::$orphanAddReported = true;
				@error_log(
					'WPML JobLog: add() called without an open group (id=' . (string) $id . '). '
					. 'Call JobLog::createNewGroup() before logging.'
				);
			}
			if ( ! is_resource( self::$fp ) ) {
				return;
			}
		}

		if ( ! self::ensureStreamOpen() ) {
			return;
		}

		if ( self::$logEventCount >= self::MAX_EVENTS_PER_REQUEST ) {
			if ( ! self::$eventsTruncatedReported ) {
				self::$eventsTruncatedReported = true;
				self::writeLine( [
					'type'    => 'log',
					'ts'      => microtime( true ),
					'groupId' => self::$groupId,
					'id'      => 'events_truncated',
					'data'    => [
						'cap'                  => self::MAX_EVENTS_PER_REQUEST,
						'last_id'              => $id,
						'remaining_calls_dropped_until_shutdown' => true,
					],
					'logType' => self::LOG_TYPE_ERROR,
				] );
				self::$hasRequestAnyErrorLog = true;
			}
			return;
		}
		self::$logEventCount++;

		self::$alreadyAddedToLog = [];

		if ( is_array( $data ) ) {
			self::recordEntityIds( $data );
		}

		$traceFrames = self::getTrace();
		$traceHash   = self::hashTrace( $traceFrames );

		$traceFields = isset( self::$seenTraceHashes[ $traceHash ] )
			? [ 'traceHash' => $traceHash ]
			: [
				'trace'     => $traceFrames,
				'traceHash' => $traceHash,
			];

		self::$seenTraceHashes[ $traceHash ] = true;

		$line = array_merge(
			[
				'type'    => 'log',
				'ts'      => microtime( true ),
				'groupId' => self::$groupId,
				'id'      => $id,
				'data'    => self::dataToArray( $data ),
			],
			$traceFields,
			[ 'logType' => $logType ]
		);

		$line += self::$extraLogData;

		self::writeLine( $line );
	}

	private static function hashTrace( array $frames ) {
		return substr( md5( implode( "\n", $frames ) ), 0, 10 );
	}

	private static function recordEntityIds( $payload, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		if ( is_object( $payload ) ) {
			$payload = get_object_vars( $payload );
		}
		if ( ! is_array( $payload ) ) {
			return;
		}
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				self::recordEntityIds( $value, $depth + 1 );
				continue;
			}
			if ( ! isset( self::$ID_KEY_MAP[ $key ] ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$str = (string) $value;
			if ( $str === '' ) {
				continue;
			}
			$bucket                              = self::$ID_KEY_MAP[ $key ];
			self::$idsTouched[ $bucket ][ $str ] = true;
		}
	}

	private static function buildEntityIdsSummary() {
		$out = [];
		foreach ( self::$idsTouched as $bucket => $valueMap ) {
			if ( empty( $valueMap ) ) {
				continue;
			}
			$out[ $bucket ] = array_keys( $valueMap );
		}
		return $out;
	}

	public static function isErrorLog( $log ) {
		return (int) $log['logType'] === self::LOG_TYPE_ERROR;
	}

	public static function addError( $id, $data = [] ) {
		self::$hasRequestAnyErrorLog = true;
		self::add( $id, $data, self::LOG_TYPE_ERROR );
	}

	public static function getLogsCount() {
		return FsJobLogStorage::getLogsCount();
	}

	public static function getSummaries() {
		return FsJobLogStorage::getRequestSummaries();
	}

	public static function getEvents( $logUid ) {
		return FsJobLogStorage::readEvents( $logUid );
	}

	public static function clearLogs() {
		return FsJobLogStorage::clearAllLogs();
	}

	public static function shutdown() {
		if ( ! self::canLog() ) {
			return;
		}

		if ( ! self::$requestStartedWritten ) {
			return;
		}

		if ( self::$groupId !== null ) {
			self::finishCurrentGroup();
		}

		$enrichedRequestParams = self::enrichRequestParams( self::$requestParams );
		$stringIdsByBatchId    = self::resolveStringIdsByBatchId( self::$stringBatchIds );

		self::$alreadyAddedToLog = [];

		self::writeLine(
            [
				'type'               => 'request_finished',
				'ts'                 => microtime( true ),
				'hasErrorLogs'       => self::$hasRequestAnyErrorLog,
				'requestParams'      => self::dataToArray( $enrichedRequestParams ),
				'stringIdsByBatchId' => $stringIdsByBatchId,
				'ids'                => self::buildEntityIdsSummary(),
			]
        );
		self::$requestFinishedWritten = true;

		if ( ! FsJobLogStorage::finaliseStream( self::$fp, self::$inProgressPath ) ) {
			self::reportIoFailure( 'finalise' );
		}

		self::$fp             = null;
		self::$inProgressPath = null;
	}

	private static function ensureStreamOpen() {
		if ( self::$streamOpenFailed ) {
			return false;
		}

		if ( is_resource( self::$fp ) ) {
			return true;
		}

		if ( self::$streamOpenAttempted ) {
			return false;
		}

		self::$streamOpenAttempted = true;


		[ $fp, $path ] = FsJobLogStorage::openRequestStream( self::$logUid, self::$requestStartTime );
		if ( ! is_resource( $fp ) ) {
			self::$streamOpenFailed = true;
			return false;
		}

		self::$fp             = $fp;
		self::$inProgressPath = $path;

		self::$alreadyAddedToLog = [];

		$ok = self::writeLine(
            [
				'type'            => 'request_started',
				'ts'              => self::$requestStartTime,
				'logUid'          => self::$logUid,
				'requestUrl'      => self::$requestUrl,
				'requestParams'   => self::dataToArray( self::$requestParams ),
				'requestDateTime' => self::$requestDateTime,
				'blogId'          => is_multisite() ? get_current_blog_id() : 0,
			]
        );

		if ( ! $ok ) {
			self::$streamOpenFailed = true;
			return false;
		}

		self::$requestStartedWritten = true;

		return true;
	}

	private static function writeLine( array $line ) {
		if ( ! is_resource( self::$fp ) ) {
			return false;
		}

		$line    = self::enrichLineWithTimings( $line );
		$encoded = self::encodeAndCap( $line );
		if ( ! is_string( $encoded ) ) {
			self::reportIoFailure( 'write' );
			return false;
		}

		$ok = FsJobLogStorage::writeEncodedLine( self::$fp, $encoded );
		if ( ! $ok ) {
			self::reportIoFailure( 'write' );
		}
		return $ok;
	}

	private static function encodeAndCap( array $line ) {
		$encoded = json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return null;
		}

		$originalBytes = strlen( $encoded );
		if ( $originalBytes <= self::MAX_LINE_BYTES ) {
			return $encoded;
		}

		if ( isset( $line['data'] ) ) {
			$line['data'] = [ '_truncated' => true ];
		}
		if ( isset( $line['requestParams'] ) ) {
			$line['requestParams'] = [ '_truncated' => true ];
		}
		$line['_line_oversized'] = [
			'original_bytes' => $originalBytes,
			'cap_bytes'      => self::MAX_LINE_BYTES,
		];

		$reencoded = json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $reencoded ) ? $reencoded : null;
	}

	private static function enrichLineWithTimings( array $line ) {
		$ts = isset( $line['ts'] ) ? (float) $line['ts'] : microtime( true );

		return $line + [
			'timestamp_utc' => self::formatUtcMs( $ts ),
			'elapsed_ms'    => self::elapsedMs( $ts ),
			'php_pid'       => self::pid(),
		];
	}

	private static function formatUtcMs( $microtimeFloat ) {
		$seconds = (int) $microtimeFloat;
		$ms      = (int) floor( ( $microtimeFloat - $seconds ) * 1000 );
		if ( $ms < 0 ) {
			$ms = 0;
		} elseif ( $ms > 999 ) {
			$ms = 999;
		}
		return gmdate( 'Y-m-d\TH:i:s', $seconds ) . sprintf( '.%03dZ', $ms );
	}

	private static function elapsedMs( $microtimeFloat ) {
		if ( self::$requestStartTime === null ) {
			return 0;
		}
		return (int) round( ( $microtimeFloat - self::$requestStartTime ) * 1000 );
	}

	private static function pid() {
		if ( self::$phpPid === null ) {
			self::$phpPid = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
		}
		return self::$phpPid;
	}

	private static function reportIoFailure( $stage ) {
		if ( self::$writeFailureReported ) {
			return;
		}
		self::$writeFailureReported = true;
		@error_log(
			'WPML JobLog: failed during ' . $stage . ' to ' . (string) self::$inProgressPath
			. ' (likely disk full or permission denied).'
		);
	}

	private static function enrichRequestParams( array $requestParams ) {
		global $wpdb;

		if ( isset( $requestParams['posts'] ) && is_array( $requestParams['posts'] ) ) {
			for ( $i = 0; $i < count( $requestParams['posts'] ); $i++ ) {
				$postId = $requestParams['posts'][ $i ];
				$post   = get_post( $postId );
				if ( is_object( $post ) ) {
					$requestParams['posts'][ $i ] = $requestParams['posts'][ $i ] . ' (post title=' . $post->post_name . ')';
				}
			}
		}

		if ( isset( $requestParams['strings'] ) && is_array( $requestParams['strings'] ) ) {
			$stringIds = array_map( 'intval', $requestParams['strings'] );
			if ( $stringIds ) {
				$placeholders = implode( ',', array_fill( 0, count( $stringIds ), '%d' ) );
				$sql          = "
					SELECT id, value, context, gettext_context
					FROM {$wpdb->prefix}icl_strings
					WHERE id IN ($placeholders)
				";

				$results = $wpdb->get_results( $wpdb->prepare( $sql, $stringIds ), OBJECT_K );

				foreach ( $requestParams['strings'] as $i => $stringId ) {
					$id = (int) $stringId;
					if ( isset( $results[ $id ] ) ) {
						$value   = $results[ $id ]->value;
						$domain  = $results[ $id ]->context;
						$context = $results[ $id ]->gettext_context;

						$requestParams['strings'][ $i ] = $id . ' (string value=`' . $value . '`, context=`' . $context . '`, domain=`' . $domain . '`)';
					}
				}
			}
		}

		return $requestParams;
	}

	private static function resolveStringIdsByBatchId( array $stringBatchIds ) {
		if ( count( $stringBatchIds ) === 0 ) {
			return [];
		}

		global $wpdb;
		$stringBatchIds = array_map( 'intval', $stringBatchIds );
		$placeholders   = implode( ',', array_fill( 0, count( $stringBatchIds ), '%d' ) );
		$sql            = "
			SELECT *
			FROM {$wpdb->prefix}icl_string_batches
			WHERE batch_id IN ($placeholders)
		";

		$results            = $wpdb->get_results( $wpdb->prepare( $sql, $stringBatchIds ), ARRAY_A );
		$stringIdsByBatchId = [];

		if ( ! is_array( $results ) ) {
			return $stringIdsByBatchId;
		}

		foreach ( $results as $result ) {
			$batchId  = (int) $result['batch_id'];
			$stringId = (int) $result['string_id'];

			if ( ! isset( $stringIdsByBatchId[ $batchId ] ) ) {
				$stringIdsByBatchId[ $batchId ] = [];
			}

			$stringIdsByBatchId[ $batchId ][] = $stringId;
		}

		return $stringIdsByBatchId;
	}

	private static function getObjectId( $object ) {
		return (string) spl_object_hash( $object );
	}

	private static function dataToArray( $data, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return '[DEPTH_LIMIT]';
		}

		if ( is_callable( $data ) ) {
			return 'callable';
		}
		if ( is_resource( $data ) ) {
			return 'resource';
		}
		if ( is_string( $data ) ) {
			$data = self::stripUrlSecrets( $data );
			if ( strlen( $data ) > self::MAX_STRING_LENGTH ) {
				return substr( $data, 0, self::MAX_STRING_LENGTH ) . '…[TRUNCATED]';
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			$id = self::getObjectId( $data );
			if ( in_array( $id, self::$alreadyAddedToLog ) ) {
				return '[RECURSION]';
			}
			self::$alreadyAddedToLog[] = $id;

			$data = get_object_vars( $data );
		}

		$count = 0;
		if ( is_array( $data ) ) {
			$result = [];

			foreach ( $data as $key => $value ) {
				if ( ++$count > self::MAX_ARRAY_ITEMS ) {
					$result[ $key ] = '[ARRAY_TRUNCATED]';
					break;
				}

				if ( self::isSensitiveKey( $key ) ) {
					$result[ $key ] = '[REDACTED]';
					continue;
				}

				$result[ $key ] = self::dataToArray( $value, $depth + 1 );
			}

			return $result;
		}

		return $data;
	}

	private static function isSensitiveKey( $key ) {
		if ( ! is_string( $key ) && ! is_numeric( $key ) ) {
			return false;
		}
		$normalised = str_replace( '-', '_', strtolower( (string) $key ) );
		foreach ( self::SECRET_KEY_NEEDLES as $needle ) {
			if ( strpos( $normalised, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function stripUrlSecrets( $value ) {
		if ( strpos( $value, '=' ) === false ) {
			return $value;
		}
		return preg_replace(
			'/(?:^|[?&])(signature|token|shared_key|api_key|access_token|refresh_token|password|secret|authorization|bearer)=([^&\s]+)/i',
			'$1=[REMOVED]',
			$value
		);
	}

	private static function getUrlParams() {
		$contentLength = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $contentLength > self::MAX_INPUT_BYTES ) {
			return [
				'_oversized_input' => [
					'content_length' => $contentLength,
					'cap_bytes'      => self::MAX_INPUT_BYTES,
				],
			];
		}

		$body = @file_get_contents( 'php://input', false, null, 0, self::MAX_INPUT_BYTES + 1 );

		if ( $body === false || $body === '' ) {
			return [];
		}

		if ( strlen( $body ) > self::MAX_INPUT_BYTES ) {
			return [
				'_oversized_input' => [
					'cap_bytes' => self::MAX_INPUT_BYTES,
				],
			];
		}

		$params = json_decode( $body, true );

		return is_array( $params ) ? $params : [];
	}

	private static function getTrace() {
		$debug_backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
		$traces          = [];

		foreach ( $debug_backtrace as $trace ) {
			$trace = [
				'file'     => $trace['file'] ?? '',
				'line'     => $trace['line'] ?? '',
				'class'    => $trace['class'] ?? '',
				'type'     => $trace['type'] ?? '',
				'function' => $trace['function'] ?? '',
			];

			$traces[] = $trace['file'] . ':' . $trace['line'] . ' ' . $trace['class'] . '/' . $trace['function'];
		}

		return $traces;
	}
}
