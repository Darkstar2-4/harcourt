<?php


namespace WPML\TM\Jobs\Log;

use WPML\TM\Jobs\FsJobLogStorage;
use WPML\TM\Jobs\JobLog;

class EntityTimelineFinder {

	const MAX_REQUESTS_SCANNED = 100;

	const MAX_EVENTS_RETURNED = 500;

	public static function build( $postId ) {
		$postId = (int) $postId;
		$post   = $postId > 0 ? get_post( $postId ) : null;
		if ( ! $post ) {
			return null;
		}

		$state = self::buildState( $post );
		$trid  = isset( $state['trid'] ) ? (int) $state['trid'] : 0;
		$rids  = self::collectRidsForTrid( $trid );

		$matched = self::matchRequests( $postId, $trid, $rids );

		$events = self::collectEvents( $matched, $postId, $trid, $rids );

		$eventTotal = count( $events );
		$truncated  = $eventTotal > self::MAX_EVENTS_RETURNED;
		if ( $truncated ) {
			$events = array_slice( $events, -self::MAX_EVENTS_RETURNED );
		}

		return [
			'state'         => $state,
			'events'        => $events,
			'event_total'   => $eventTotal,
			'truncated'     => $truncated,
			'request_count' => count( $matched ),
		];
	}


	private static function buildState( \WP_Post $post ) {
		global $wpdb;

		$elementType = 'post_' . $post->post_type;

		$row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT trid, language_code, source_language_code
			 FROM {$wpdb->prefix}icl_translations
			 WHERE element_id = %d AND element_type = %s",
                $post->ID,
                $elementType
            ),
            ARRAY_A
        );

		$trid       = isset( $row['trid'] ) ? (int) $row['trid'] : 0;
		$sourceLang = (string) ( $row['language_code'] ?? '' );

		$translations = [];
		$activeJobs   = [];

		if ( $trid ) {
			$translations = self::collectTranslations( $wpdb, $trid, $elementType, $sourceLang );
			$activeJobs   = self::collectActiveJobs( $wpdb, $trid );
		}

		return [
			'post_id'      => (int) $post->ID,
			'post_title'   => $post->post_title,
			'post_type'    => $post->post_type,
			'source_lang'  => $sourceLang,
			'modified_utc' => $post->post_modified_gmt,
			'trid'         => $trid,
			'translations' => $translations,
			'active_jobs'  => $activeJobs,
		];
	}

	private static function collectTranslations( $wpdb, $trid, $elementType, $sourceLang ) {
		$rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.language_code, t.element_id, s.status
			 FROM {$wpdb->prefix}icl_translations t
			 LEFT JOIN {$wpdb->prefix}icl_translation_status s ON s.translation_id = t.translation_id
			 WHERE t.trid = %d AND t.element_type = %s AND t.language_code != %s",
                $trid,
                $elementType,
                $sourceLang
            ),
            ARRAY_A
        );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'language_code'      => (string) ( $r['language_code'] ?? '' ),
				'translated_post_id' => isset( $r['element_id'] ) ? (int) $r['element_id'] : 0,
				'status'             => isset( $r['status'] ) ? (string) $r['status'] : '',
			];
		}
		return $out;
	}

	private static function collectActiveJobs( $wpdb, $trid ) {
		$rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.job_id, j.rid, s.status, t.language_code,
			        UNIX_TIMESTAMP() - UNIX_TIMESTAMP(s.timestamp) AS age_seconds
			 FROM {$wpdb->prefix}icl_translate_job j
			 INNER JOIN {$wpdb->prefix}icl_translation_status s ON s.rid = j.rid
			 INNER JOIN {$wpdb->prefix}icl_translations t       ON t.translation_id = s.translation_id
			 WHERE t.trid = %d AND j.translated = 0",
                $trid
            ),
            ARRAY_A
        );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'job_id'      => (int) ( $r['job_id'] ?? 0 ),
				'rid'         => (int) ( $r['rid'] ?? 0 ),
				'target_lang' => (string) ( $r['language_code'] ?? '' ),
				'status'      => (string) ( $r['status'] ?? '' ),
				'age_seconds' => isset( $r['age_seconds'] ) ? (int) $r['age_seconds'] : null,
			];
		}
		return $out;
	}

	private static function collectRidsForTrid( $trid ) {
		if ( ! $trid ) {
			return [];
		}
		global $wpdb;
		$rids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT s.rid
			 FROM {$wpdb->prefix}icl_translation_status s
			 INNER JOIN {$wpdb->prefix}icl_translations t ON t.translation_id = s.translation_id
			 WHERE t.trid = %d",
                (int) $trid
            )
        );
		return array_map( 'intval', (array) $rids );
	}


	private static function matchRequests( $postId, $trid, array $rids ) {
		$byLogUid = [];

		$collect = function ( array $summaries ) use ( &$byLogUid ) {
			foreach ( $summaries as $s ) {
				if ( isset( $s['logUid'] ) && ! isset( $byLogUid[ $s['logUid'] ] ) ) {
					$byLogUid[ $s['logUid'] ] = $s;
				}
			}
		};

		if ( $postId > 0 ) {
			$collect( FsJobLogStorage::findRequestsByEntity( 'post_id', $postId ) );
		}
		if ( $trid > 0 ) {
			$collect( FsJobLogStorage::findRequestsByEntity( 'trid', $trid ) );
		}
		foreach ( $rids as $rid ) {
			if ( $rid > 0 ) {
				$collect( FsJobLogStorage::findRequestsByEntity( 'rid', $rid ) );
			}
		}

		$summaries = array_values( $byLogUid );

		usort(
            $summaries,
            static function ( $a, $b ) {
				return ( (float) ( $b['startedAt'] ?? 0 ) ) <=> ( (float) ( $a['startedAt'] ?? 0 ) );
			}
        );

		return array_slice( $summaries, 0, self::MAX_REQUESTS_SCANNED );
	}

	private static function collectEvents( array $summaries, $postId, $trid, array $rids ) {
		$wantedRids = array_flip( array_map( 'intval', $rids ) );
		$out        = [];

		foreach ( $summaries as $summary ) {
			$logUid = (string) ( $summary['logUid'] ?? '' );
			if ( $logUid === '' ) {
				continue;
			}
			foreach ( JobLog::getEvents( $logUid ) as $event ) {
				if ( ! self::eventMatches( $event, $postId, $trid, $wantedRids ) ) {
					continue;
				}
				$event['__logUid'] = $logUid;
				$out[]             = $event;
			}
		}

		usort(
            $out,
            static function ( $a, $b ) {
				return ( (float) ( $a['ts'] ?? 0 ) ) <=> ( (float) ( $b['ts'] ?? 0 ) );
			}
        );

		return $out;
	}

	private static function eventMatches( array $event, $postId, $trid, array $wantedRids ) {
		$type = $event['type'] ?? '';
		if ( $type !== 'log' ) {
			return true;
		}

		if ( $postId > 0 && self::scalarEquals( $event, 'element_id', $postId ) ) {
			return true;
		}
		if ( $postId > 0 && self::scalarEquals( $event, 'post_id', $postId ) ) {
			return true;
		}
		if ( $trid > 0 && self::scalarEquals( $event, 'trid', $trid ) ) {
			return true;
		}

		if ( isset( $event['data'] ) && is_array( $event['data'] ) ) {
			if ( self::dataContainsEntity( $event['data'], $postId, $trid, $wantedRids ) ) {
				return true;
			}
		}

		return false;
	}

	private static function scalarEquals( array $event, $key, $value ) {
		return isset( $event[ $key ] ) && (int) $event[ $key ] === (int) $value;
	}

	private static function dataContainsEntity( array $data, $postId, $trid, array $wantedRids, $depth = 0 ) {
		if ( $depth > 8 ) {
			return false;
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				if ( self::dataContainsEntity( $v, $postId, $trid, $wantedRids, $depth + 1 ) ) {
					return true;
				}
				continue;
			}
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$nv = (int) $v;

			if ( $postId > 0 ) {
				if ( in_array( $k, [ 'post_id', 'new_post_id', 'source_post_id', 'original_doc_id', 'original_element_id', 'originalElementId', 'element_id', 'elementId' ], true )
					&& $nv === $postId ) {
					return true;
				}
			}
			if ( $trid > 0 ) {
				if ( $k === 'trid' && $nv === $trid ) {
					return true;
				}
			}
			if ( $wantedRids ) {
				if ( in_array( $k, [ 'rid', 'wpmlJobId' ], true ) && isset( $wantedRids[ $nv ] ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
