<?php


namespace WPML\TM\Jobs;

class FsJobLogStorage {

	const MAX_TOTAL_BYTES = 52428800;

	const MAX_STORED_REQUESTS_COUNT = 1500;

	const OPTION_MAX_MB    = 'wpml_tm_job_log_max_mb';
	const OPTION_MAX_FILES = 'wpml_tm_job_log_max_files';

	const MIN_CONFIGURABLE_MB    = 1;
	const MAX_CONFIGURABLE_MB    = 10000;
	const MIN_CONFIGURABLE_FILES = 50;
	const MAX_CONFIGURABLE_FILES = 100000;

	const PRUNE_RATIO = 0.25;

	const DEFAULT_STUCK_THRESHOLD_SECONDS = 300;

	const ABANDONED_IN_PROGRESS_SECONDS = 3600;

	const STATUS_COMPLETE    = 'complete';
	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_STUCK       = 'stuck';
	const STATUS_LEGACY      = 'legacy';
	const STATUS_ABORTED = 'aborted';

	private static $queueDirOverride = null;


	public static function openRequestStream( $logUid, $requestStartTime ) {
		self::ensureQueueDir();

		$filename = self::generateFilename( $logUid, $requestStartTime, 'in_progress' );
		$filepath = self::getFilepath( $filename );

		$fp = @fopen( $filepath, 'ab' );
		if ( ! is_resource( $fp ) ) {
			return [ null, $filepath ];
		}

		stream_set_write_buffer( $fp, 0 );

		$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		@chmod( $filepath, $chmod );

		return [ $fp, $filepath ];
	}

	public static function writeLine( $fp, array $line ) {
		if ( ! is_resource( $fp ) ) {
			return false;
		}

		$json = json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return false;
		}

		return self::writeEncodedLine( $fp, $json );
	}

	public static function writeEncodedLine( $fp, $json ) {
		if ( ! is_resource( $fp ) || ! is_string( $json ) ) {
			return false;
		}
		$payload = $json . "\n";
		$written = @fwrite( $fp, $payload );
		return is_int( $written ) && $written === strlen( $payload );
	}

	public static function finaliseStream( $fp, $inProgressPath ) {
		if ( is_resource( $fp ) ) {
			@fclose( $fp );
		}

		if ( ! is_string( $inProgressPath ) || $inProgressPath === '' ) {
			return false;
		}

		if ( ! file_exists( $inProgressPath ) ) {
			return false;
		}

		$completePath = self::deriveCompletePath( $inProgressPath );
		$renamed      = @rename( $inProgressPath, $completePath );

		self::pruneIfLimitsExceeded();

		return (bool) $renamed;
	}


	public static function getRequestSummaries() {
		$dir = self::getQueueDir();

		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = array_merge(
			glob( $dir . '*.complete.ndjson' ) ?: [],
			glob( $dir . '*.in_progress.ndjson' ) ?: [],
			self::globLegacyJsonFiles( $dir )
		);

		rsort( $files );

		$now       = time();
		$summaries = [];

		foreach ( $files as $file ) {
			$summary = self::isLegacyJsonFile( $file )
				? self::summariseLegacyJson( $file, $now )
				: self::summariseNdjson( $file, $now );

			if ( is_array( $summary ) ) {
				$summaries[] = $summary;
			}
		}

		return $summaries;
	}

	public static function readEvents( $logUid ) {
		$file = self::findFileByLogUid( $logUid );
		if ( $file === null ) {
			return;
		}

		if ( self::isLegacyJsonFile( $file ) ) {
			foreach ( self::synthesiseEventsFromLegacyJson( $file ) as $event ) {
				yield $event;
			}
			return;
		}

		$fp = @fopen( $file, 'rb' );
		if ( ! is_resource( $fp ) ) {
			return;
		}

		$traceMap = [];

		while ( ( $rawLine = fgets( $fp ) ) !== false ) {
			$line = trim( $rawLine );
			if ( $line === '' ) {
				continue;
			}
			$event = json_decode( $line, true );
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( isset( $event['traceHash'] ) ) {
				$hash = (string) $event['traceHash'];
				if ( isset( $event['trace'] ) && is_array( $event['trace'] ) ) {
					$traceMap[ $hash ] = $event['trace'];
				} elseif ( isset( $traceMap[ $hash ] ) ) {
					$event['trace'] = $traceMap[ $hash ];
				}
				unset( $event['traceHash'] );
			}

			yield $event;
		}

		@fclose( $fp );
	}

	public static function getStuckRequests( $thresholdSeconds = self::DEFAULT_STUCK_THRESHOLD_SECONDS ) {
		$dir = self::getQueueDir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = glob( $dir . '*.in_progress.ndjson' ) ?: [];
		$now   = time();

		$stuck = [];
		foreach ( $files as $file ) {
			$mtime = @filemtime( $file );
			if ( $mtime === false ) {
				continue;
			}
			if ( ( $now - $mtime ) < $thresholdSeconds ) {
				continue;
			}
			$summary = self::summariseNdjson( $file, $now );
			if ( is_array( $summary ) ) {
				$stuck[] = $summary;
			}
		}

		return $stuck;
	}

	public static function findRequestsByEntity( $idType, $id ) {
		$idType  = (string) $idType;
		$bucket  = substr( $idType, -1 ) === 's' ? $idType : $idType . 's';
		$idValue = (string) $id;
		$matches = [];

		foreach ( self::getRequestSummaries() as $summary ) {
			if ( isset( $summary['entityIds'] ) && is_array( $summary['entityIds'] ) ) {
				if ( self::indexedMatch( $summary['entityIds'], $bucket, $idValue ) ) {
					$matches[] = $summary;
				}
				continue;
			}

			foreach ( self::readEvents( $summary['logUid'] ) as $event ) {
				if ( self::eventMatchesEntity( $event, $idType, $idValue ) ) {
					$matches[] = $summary;
					break;
				}
			}
		}

		return $matches;
	}

	private static function indexedMatch( array $entityIds, $bucket, $idValue ) {
		if ( ! isset( $entityIds[ $bucket ] ) || ! is_array( $entityIds[ $bucket ] ) ) {
			return false;
		}
		foreach ( $entityIds[ $bucket ] as $candidate ) {
			if ( (string) $candidate === $idValue ) {
				return true;
			}
		}
		return false;
	}

	public static function getMaxTotalBytes() {
		global $sitepress;
		if ( ! $sitepress ) {
			return self::MAX_TOTAL_BYTES;
		}
		$mb = (int) $sitepress->get_setting( self::OPTION_MAX_MB, 0 );
		if ( $mb < self::MIN_CONFIGURABLE_MB || $mb > self::MAX_CONFIGURABLE_MB ) {
			return self::MAX_TOTAL_BYTES;
		}
		return $mb * 1024 * 1024;
	}

	public static function getMaxStoredRequestsCount() {
		global $sitepress;
		if ( ! $sitepress ) {
			return self::MAX_STORED_REQUESTS_COUNT;
		}
		$files = (int) $sitepress->get_setting( self::OPTION_MAX_FILES, 0 );
		if ( $files < self::MIN_CONFIGURABLE_FILES || $files > self::MAX_CONFIGURABLE_FILES ) {
			return self::MAX_STORED_REQUESTS_COUNT;
		}
		return $files;
	}

	public static function getTotalSize() {
		$dir = self::getQueueDir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$total = 0;
		$files = array_merge(
			glob( $dir . '*.complete.ndjson' ) ?: [],
			glob( $dir . '*.in_progress.ndjson' ) ?: [],
			self::globLegacyJsonFiles( $dir )
		);
		foreach ( $files as $f ) {
			$total += (int) ( @filesize( $f ) ?: 0 );
		}
		return $total;
	}

	public static function formatBytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1024 * 1024 ) {
			return number_format( $bytes / 1024, 1 ) . ' KB';
		}
		if ( $bytes < 1024 * 1024 * 1024 ) {
			return number_format( $bytes / 1024 / 1024, 1 ) . ' MB';
		}
		return number_format( $bytes / 1024 / 1024 / 1024, 2 ) . ' GB';
	}

	public static function clearAllLogs() {
		$dir = self::getQueueDir();

		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$files = array_merge(
			glob( $dir . '*.complete.ndjson' ) ?: [],
			glob( $dir . '*.in_progress.ndjson' ) ?: [],
			self::globLegacyJsonFiles( $dir )
		);

		foreach ( $files as $file ) {
			@unlink( $file );
		}

		return true;
	}

	public static function getLogsCount() {
		$dir = self::getQueueDir();

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = array_merge(
			glob( $dir . '*.complete.ndjson' ) ?: [],
			self::globLegacyJsonFiles( $dir )
		);

		return count( $files );
	}


	private static function summariseNdjson( $file, $now ) {
		$isInProgress = self::isInProgressFile( $file );

		$fp = @fopen( $file, 'rb' );
		if ( ! is_resource( $fp ) ) {
			return null;
		}

		$requestStarted  = null;
		$requestFinished = null;
		$hasErrorLogs    = false;

		while ( ( $rawLine = fgets( $fp ) ) !== false ) {
			$line = trim( $rawLine );
			if ( $line === '' ) {
				continue;
			}
			$event = json_decode( $line, true );
			if ( ! is_array( $event ) || ! isset( $event['type'] ) ) {
				continue;
			}

			switch ( $event['type'] ) {
				case 'request_started':
					$requestStarted = $event;
					break;
				case 'request_finished':
					$requestFinished = $event;
					break 2;
				case 'log':
					if ( ! $hasErrorLogs && isset( $event['logType'] ) && (int) $event['logType'] === 1 ) {
						$hasErrorLogs = true;
					}
					break;
			}
		}
		@fclose( $fp );

		if ( $requestStarted === null ) {
			return null;
		}

		$mtime   = @filemtime( $file );
		$fileAge = $mtime === false ? 0 : max( 0, $now - $mtime );

		if ( $isInProgress ) {
			$status = $fileAge >= self::DEFAULT_STUCK_THRESHOLD_SECONDS
				? self::STATUS_STUCK
				: self::STATUS_IN_PROGRESS;
		} else {
			$status = self::STATUS_COMPLETE;
		}

		$startedAt  = isset( $requestStarted['ts'] ) ? (float) $requestStarted['ts'] : null;
		$finishedAt = is_array( $requestFinished ) && isset( $requestFinished['ts'] )
			? (float) $requestFinished['ts']
			: null;
		$durationMs = null;
		if ( $startedAt !== null && $finishedAt !== null && $finishedAt >= $startedAt ) {
			$durationMs = (int) round( ( $finishedAt - $startedAt ) * 1000 );
		}

		if ( is_array( $requestFinished ) && isset( $requestFinished['hasErrorLogs'] ) ) {
			$hasErrorLogs = (bool) $requestFinished['hasErrorLogs'];
		}

		$entityIds = null;
		if (
			is_array( $requestFinished )
			&& isset( $requestFinished['ids'] )
			&& is_array( $requestFinished['ids'] )
		) {
			$entityIds = $requestFinished['ids'];
		}

		$aborted = is_array( $requestFinished ) && ! empty( $requestFinished['aborted'] );
		if ( $aborted ) {
			$status = self::STATUS_ABORTED;
		}

		return [
			'logUid'          => $requestStarted['logUid'] ?? '',
			'requestUrl'      => $requestStarted['requestUrl'] ?? '',
			'requestDateTime' => $requestStarted['requestDateTime'] ?? '',
			'startedAt'       => $startedAt,
			'finishedAt'      => $finishedAt,
			'durationMs'      => $durationMs,
			'hasErrorLogs'    => $hasErrorLogs,
			'status'          => $status,
			'php_pid'         => isset( $requestStarted['php_pid'] ) ? (int) $requestStarted['php_pid'] : null,
			'ageSeconds'      => $fileAge,
			'entityIds'       => $entityIds,
			'filePath'        => $file,
			'format'          => 'ndjson',
		];
	}

	private static function summariseLegacyJson( $file, $now ) {
		$content = @file_get_contents( $file );
		if ( ! is_string( $content ) ) {
			return null;
		}
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$mtime   = @filemtime( $file );
		$fileAge = $mtime === false ? 0 : max( 0, $now - $mtime );

		return [
			'logUid'          => $decoded['logUid'] ?? '',
			'requestUrl'      => $decoded['requestUrl'] ?? '',
			'requestDateTime' => $decoded['requestDateTime'] ?? '',
			'startedAt'       => null,
			'finishedAt'      => null,
			'durationMs'      => null,
			'hasErrorLogs'    => ! empty( $decoded['hasErrorLogs'] ),
			'status'          => self::STATUS_LEGACY,
			'php_pid'         => null,
			'ageSeconds'      => $fileAge,
			'entityIds'       => null,
			'filePath'        => $file,
			'format'          => 'legacy_json',
		];
	}

	private static function findFileByLogUid( $logUid ) {
		$dir = self::getQueueDir();
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$matches = glob( $dir . '*_' . $logUid . '.*.ndjson' ) ?: [];
		if ( ! empty( $matches ) ) {
			return $matches[0];
		}

		$matches = glob( $dir . '*_' . $logUid . '.json' ) ?: [];
		foreach ( $matches as $candidate ) {
			if ( ! self::isLegacyJsonFile( $candidate ) ) {
				continue;
			}
			return $candidate;
		}

		return null;
	}

	private static function synthesiseEventsFromLegacyJson( $file ) {
		$content = @file_get_contents( $file );
		if ( ! is_string( $content ) ) {
			return;
		}
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		yield [
			'type'            => 'request_started',
			'logUid'          => $decoded['logUid'] ?? '',
			'requestUrl'      => $decoded['requestUrl'] ?? '',
			'requestParams'   => $decoded['requestParams'] ?? [],
			'requestDateTime' => $decoded['requestDateTime'] ?? '',
		];

		$groups = isset( $decoded['logsByGroup'] ) && is_array( $decoded['logsByGroup'] )
			? $decoded['logsByGroup']
			: [];

		foreach ( $groups as $group ) {
			yield [
				'type'    => 'group_started',
				'groupId' => $group['groupId'] ?? null,
				'label'   => $group['label'] ?? '',
				'data'    => isset( $group['data'] ) && is_array( $group['data'] ) ? $group['data'] : [],
			];

			$logs = isset( $group['logs'] ) && is_array( $group['logs'] ) ? $group['logs'] : [];
			foreach ( $logs as $log ) {
				$event = [ 'type' => 'log' ];
				foreach ( $log as $k => $v ) {
					$event[ $k ] = $v;
				}
				if ( ! isset( $event['groupId'] ) ) {
					$event['groupId'] = $group['groupId'] ?? null;
				}
				yield $event;
			}

			yield [
				'type'    => 'group_finished',
				'groupId' => $group['groupId'] ?? null,
			];
		}

		yield [
			'type'         => 'request_finished',
			'hasErrorLogs' => ! empty( $decoded['hasErrorLogs'] ),
		];
	}

	private static function eventMatchesEntity( array $event, $idType, $idValue ) {
		if ( isset( $event[ $idType ] ) && (string) $event[ $idType ] === $idValue ) {
			return true;
		}

		if ( isset( $event['data'] ) && is_array( $event['data'] ) ) {
			return self::arrayContainsId( $event['data'], $idType, $idValue );
		}

		return false;
	}

	private static function arrayContainsId( array $data, $idType, $idValue ) {
		foreach ( $data as $k => $v ) {
			if ( $k === $idType && (string) $v === $idValue ) {
				return true;
			}
			if ( is_array( $v ) && self::arrayContainsId( $v, $idType, $idValue ) ) {
				return true;
			}
		}
		return false;
	}


	public static function pruneIfLimitsExceeded() {
		$dir = self::getQueueDir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$completedFiles  = array_merge(
			glob( $dir . '*.complete.ndjson' ) ?: [],
			self::globLegacyJsonFiles( $dir )
		);
		$inProgressFiles = glob( $dir . '*.in_progress.ndjson' ) ?: [];

		$fileCount = count( $completedFiles ) + count( $inProgressFiles );
		if ( $fileCount === 0 ) {
			return;
		}

		$maxFiles = self::getMaxStoredRequestsCount();
		$maxBytes = self::getMaxTotalBytes();

		$overCount = $fileCount > $maxFiles;

		$sizeCheckThreshold = (int) ceil( $maxFiles * 0.75 );
		$overSize           = false;
		if ( ! $overCount && $fileCount >= $sizeCheckThreshold ) {
			$overSize = self::getTotalSize() > $maxBytes;
		}

		if ( ! $overCount && ! $overSize ) {
			return;
		}

		$abandonedInProgress = self::filterAbandonedInProgress( $inProgressFiles );
		$eligible            = array_merge( $completedFiles, $abandonedInProgress );

		if ( empty( $eligible ) ) {
			return;
		}

		$toDeleteCount = (int) ceil( count( $eligible ) * self::PRUNE_RATIO );

		list( $lowPriority, $highPriority ) = self::partitionByPrunePriority( $eligible );

		sort( $lowPriority );
		sort( $highPriority );

		$victims = array_slice( $lowPriority, 0, $toDeleteCount );
		if ( count( $victims ) < $toDeleteCount ) {
			$stillNeed = $toDeleteCount - count( $victims );
			$victims   = array_merge( $victims, array_slice( $highPriority, 0, $stillNeed ) );
		}

		foreach ( $victims as $file ) {
			@unlink( $file );
		}
	}

	private static function partitionByPrunePriority( array $files ) {
		$low  = [];
		$high = [];
		foreach ( $files as $f ) {
			if ( self::isHighPriorityForPrune( $f ) ) {
				$high[] = $f;
			} else {
				$low[] = $f;
			}
		}
		return [ $low, $high ];
	}

	private static function isHighPriorityForPrune( $file ) {
		if ( self::isLegacyJsonFile( $file ) || self::isInProgressFile( $file ) ) {
			return false;
		}

		$finished = self::readRequestFinishedFromTail( $file );
		if ( ! is_array( $finished ) ) {
			return false;
		}
		if ( ! empty( $finished['aborted'] ) ) {
			return true;
		}
		if ( ! empty( $finished['hasErrorLogs'] ) ) {
			return true;
		}
		return false;
	}

	private static function readRequestFinishedFromTail( $file ) {
		$size = @filesize( $file );
		if ( ! $size || $size <= 0 ) {
			return null;
		}
		$chunkSize = (int) min( $size, 16384 );
		$offset    = $size - $chunkSize;
		$tail      = @file_get_contents( $file, false, null, $offset, $chunkSize );
		if ( ! is_string( $tail ) ) {
			return null;
		}
		$lines = preg_split( '/\n+/', trim( $tail ) );
		if ( empty( $lines ) ) {
			return null;
		}
		$last  = end( $lines );
		$event = json_decode( $last, true );
		if ( ! is_array( $event ) ) {
			return null;
		}
		if ( ( $event['type'] ?? '' ) !== 'request_finished' ) {
			return null;
		}
		return $event;
	}

	private static function filterAbandonedInProgress( array $files ) {
		if ( empty( $files ) ) {
			return [];
		}
		$cutoff = time() - self::ABANDONED_IN_PROGRESS_SECONDS;
		$out    = [];
		foreach ( $files as $f ) {
			$mtime = @filemtime( $f );
			if ( $mtime !== false && $mtime < $cutoff ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	private static function globLegacyJsonFiles( $dir ) {
		$files = glob( $dir . '*.json' ) ?: [];
		return array_values(
            array_filter(
                $files,
                static function ( $f ) {
					return substr( $f, -7 ) !== '.ndjson';
				}
            )
        );
	}

	private static function isInProgressFile( $file ) {
		return substr( $file, -19 ) === '.in_progress.ndjson';
	}

	private static function isLegacyJsonFile( $file ) {
		return substr( $file, -5 ) === '.json' && substr( $file, -7 ) !== '.ndjson';
	}

	private static function ensureQueueDir() {
		$wpmlDir = self::getWpmlDir();
		if ( ! file_exists( $wpmlDir ) ) {
			@mkdir( $wpmlDir, 0777, true );
		}

		$queueDir = self::getQueueDir();
		if ( ! file_exists( $queueDir ) ) {
			@mkdir( $queueDir, 0777, true );
		}

		self::writeAccessGuards( $wpmlDir );
		self::writeAccessGuards( $queueDir );
	}

	private static function writeAccessGuards( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$indexPath = rtrim( $dir, '/\\' ) . '/index.php';
		if ( ! file_exists( $indexPath ) ) {
			@file_put_contents( $indexPath, "<?php\n// Silence is golden.\n" );
		}

		$htaccessPath = rtrim( $dir, '/\\' ) . '/.htaccess';
		if ( ! file_exists( $htaccessPath ) ) {
			$body  = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
			$body .= "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
			@file_put_contents( $htaccessPath, $body );
		}
	}

	private static function getWpmlDir() {
		return WP_LANG_DIR . '/wpml/';
	}

	private static function getQueueDir() {
		if ( self::$queueDirOverride !== null ) {
			return rtrim( self::$queueDirOverride, '/\\' ) . '/';
		}

		$subdir = '';

		if ( is_multisite() ) {
			$subdir = get_current_blog_id() . '/';
		}

		return self::getWpmlDir() . 'joblog/' . $subdir;
	}

	public static function setQueueDirForTests( $dir ) {
		self::$queueDirOverride = $dir;
	}

	private static function getFilepath( $filename ) {
		return self::getQueueDir() . $filename;
	}

	private static function generateFilename( $logUid, $requestStartTime, $state ) {
		$ts   = (int) $requestStartTime;
		$date = gmdate( 'Ymd-His', $ts );
		$ms   = (int) ( fmod( $requestStartTime, 1 ) * 1000 );
		$pid  = function_exists( 'getmypid' ) ? getmypid() : 0;

		return 'request_' . $date . '-' . str_pad( (string) $ms, 3, '0', STR_PAD_LEFT )
			. '_' . $pid . '_' . $logUid
			. '.' . $state . '.ndjson';
	}

	private static function deriveCompletePath( $inProgressPath ) {
		return preg_replace( '/\.in_progress\.ndjson$/', '.complete.ndjson', $inProgressPath );
	}
}
