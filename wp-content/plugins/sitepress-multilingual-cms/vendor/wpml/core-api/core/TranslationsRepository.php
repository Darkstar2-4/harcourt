<?php

namespace WPML\Element\API;

use WPML\FP\Cast;
use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Obj;

class TranslationsRepository {
	private static $data = [];

	private static $tridLanguageIndex = [];

	private static $translationIdIndex = [];

	public static function preloadForPosts( $posts ) {
		global $wpdb;

		$sql = self::getSqlTemplate();

		$condition = Lst::join(
			' OR ',
			Fns::map( function ( $post ) use ( $wpdb ) {
				return $wpdb->prepare(
					'(element_id = %d AND element_type = %s)',
					Obj::prop( 'ID', $post ),
					'post_' . Obj::prop( 'post_type', $post )
				);
			}, $posts ) );

		$sql .= "
			WHERE translations.trid IN (
				SELECT trid FROM {$wpdb->prefix}icl_translations WHERE {$condition} 
			)
		";

		self::appendResult( $sql );
	}

	public static function reset() {
		self::$data               = [];
		self::$translationIdIndex = [];
		self::$tridLanguageIndex  = [];
	}

	public static function getByTridAndLanguage( $trid, $language ) {
        if ( null === $trid || null === $language ) {
            return null;
        }
		return isset( self::$tridLanguageIndex[ $trid ][ $language ] ) ? self::$tridLanguageIndex[ $trid ][ $language ] : null;
	}

	public static function getByTranslationId( $translationId ) {
		return isset( self::$translationIdIndex[ $translationId ] ) ? self::$translationIdIndex[ $translationId ] : null;
	}

	private static function getSqlTemplate() {
		global $wpdb;

		$sql = "
			SELECT translations.translation_id,
				   translations.element_type,
				   translations.element_id,
			       translations.trid,
			       translations.language_code,
			       translations.source_language_code,
			       (
			           SELECT element_id FROM {$wpdb->prefix}icl_translations as originalTranslation 
			           WHERE originalTranslation.trid = translations.trid and originalTranslation.source_language_code IS NULL
			       ) as original_doc_id,
			       NULLIF(translations.source_language_code, '') IS NULL AS original,
			       translation_status.rid,
			       translation_status.status,
			       translation_status.translator_id,
			       translation_status.needs_update,
			       translation_status.review_status,
			       translation_status.translation_service,
			       translation_status.batch_id,
			       translation_status.timestamp,
			       translation_status.tp_id,
			       translation_status.ate_comm_retry_count,
			       NULL as job_id,
			       NULL as translated,
			       NULL as editor,
			       NULL as editor_job_id,
			       NULL as automatic,
			       NULL as ate_sync_count
			FROM {$wpdb->prefix}icl_translations as translations
			LEFT JOIN {$wpdb->prefix}icl_translation_status translation_status ON translation_status.translation_id = translations.translation_id
		";

		return $sql;
	}

	private static function appendResult( $sql ) {
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare( $sql . ' AND 1 = %d', 1 ) );
		$results = is_array( $results ) ? $results : [];

		self::attachLatestJobsForRids( $results );

		self::$data = Lst::concat( self::$data, Fns::map( Obj::evolve( [
			'translation_id'       => Cast::toInt(),
			'element_id'           => Cast::toInt(),
			'trid'                 => Cast::toInt(),
			'original_doc_id'      => Cast::toInt(),
			'rid'                  => Cast::toInt(),
			'status'               => Cast::toInt(),
			'translator_id'        => Cast::toInt(),
			'batch_id'             => Cast::toInt(),
			'tp_id'                => Cast::toInt(),
			'ate_comm_retry_count' => Cast::toInt(),
			'job_id'               => Cast::toInt(),
			'translated'           => Cast::toBool(),
			'editor_job_id'        => Cast::toInt(),
			'ate_sync_count'       => Cast::toInt(),
			'automatic'            => Cast::toBool(),
		] ), $results ) );

		foreach ( $results as &$row ) {
			self::$tridLanguageIndex[ Obj::prop( 'trid', $row ) ][ Obj::prop( 'language_code', $row ) ] = &$row;
			self::$translationIdIndex[ Obj::prop( 'translation_id', $row ) ] = &$row;
		}
	}

	private static function attachLatestJobsForRids( array &$results ) {
		if ( ! $results ) {
			return;
		}

		$ridMap = [];

		foreach ( $results as &$row ) {
			$rid = (int) Obj::prop( 'rid', $row );
			if ( $rid > 0 ) {
				$ridMap[ $rid ] = true;
			}
		}
		unset( $row );

		if ( ! $ridMap ) {
			return;
		}

		$jobsByRid = self::getLatestJobsByRid( array_keys( $ridMap ) );

		foreach ( $results as &$row ) {
			$rid = (int) Obj::prop( 'rid', $row );

			if ( isset( $jobsByRid[ $rid ] ) ) {
				$job = $jobsByRid[ $rid ];

				foreach ( [ 'job_id', 'translated', 'editor', 'editor_job_id', 'automatic', 'ate_sync_count' ] as $prop ) {
					if ( self::rowHasProp( $job, $prop ) ) {
						self::setRowProp( $row, $prop, Obj::prop( $prop, $job ) );
					}
				}
			}
		}
		unset( $row );
	}

	private static function getLatestJobsByRid( array $rids ) {
		global $wpdb;

		if ( ! $rids ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $rids ), '%d' ) );

		$sql = "
			SELECT jobs.rid,
			       jobs.job_id,
			       jobs.translated,
			       jobs.editor,
			       jobs.editor_job_id,
			       jobs.automatic,
			       jobs.ate_sync_count
			FROM {$wpdb->prefix}icl_translate_job jobs
			INNER JOIN (
				SELECT rid, MAX(job_id) AS job_id
				FROM {$wpdb->prefix}icl_translate_job
				WHERE rid IN ({$placeholders})
				GROUP BY rid
			) latest_jobs
				ON latest_jobs.rid = jobs.rid
			   AND latest_jobs.job_id = jobs.job_id
		";

		$jobs = $wpdb->get_results( $wpdb->prepare( $sql, $rids ) );

		$indexed = [];
		foreach ( (array) $jobs as $job ) {
			$indexed[ (int) Obj::prop( 'rid', $job ) ] = $job;
		}

		return $indexed;
	}

	private static function setRowProp( &$row, $prop, $value ) {
		if ( is_array( $row ) ) {
			$row[ $prop ] = $value;
		} else {
			$row->$prop = $value;
		}
	}

	private static function rowHasProp( $row, $prop ) {
		return is_array( $row ) ? array_key_exists( $prop, $row ) : isset( $row->$prop );
	}
}
