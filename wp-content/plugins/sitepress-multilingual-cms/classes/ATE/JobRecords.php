<?php

namespace WPML\TM\ATE;

use Exception;
use WPML\Collect\Support\Collection;
use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML_TM_ATE_API_Error;
use WPML_TM_Editors;

class JobRecords {

	const FIELD_ATE_JOB_ID = 'ate_job_id';
	const FIELD_IS_EDITING = 'is_editing';

	private $wpdb;

	private $jobs;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		$this->jobs = wpml_collect( [] );
	}

	public function get_data_from_ate_job_id( $ateJobId ) {
		$ateJobId = (int) $ateJobId;

		$this->warmCache( [], [ $ateJobId ] );

		$job = $this->jobs->first(
			function( JobRecord $job ) use ( $ateJobId ) {
				return $job->ateJobId === $ateJobId;
			}
		);

		if ( $job ) {
			return [
				'wpml_job_id'  => $job->wpmlJobId,
				'ate_job_data' => [
					'ate_job_id' => $job->ateJobId,
				],
			];
		}

		return null;
	}

	public function store( $wpmlJobId, array $ateJobData ) {
		$ateJobData['job_id'] = (int) $wpmlJobId;

		$this->warmCache( [ $wpmlJobId ] );
		$job = $this->jobs->get( $wpmlJobId );

		if ( ! $job ) {
			$job            = new JobRecord();
			$job->wpmlJobId = (int) $wpmlJobId;
		}

		if ( isset( $ateJobData[ self::FIELD_ATE_JOB_ID ] ) ) {
			$job->ateJobId = $ateJobData[ self::FIELD_ATE_JOB_ID ];
		}

		$this->persist( $job );
	}

	public function persist( JobRecord $job ) {
		$this->jobs->put( $job->wpmlJobId, $job );

		$this->wpdb->update(
			$this->wpdb->prefix . 'icl_translate_job',
			[ 'editor_job_id' => $job->ateJobId ],
			[ 'job_id' => $job->wpmlJobId ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	public function warmCache( array $wpmlJobIds, array $ateJobIds = [] ) {
		$wpmlJobIds = wpml_collect( $wpmlJobIds )->reject( $this->isAlreadyLoaded( 'wpmlJobId' ) )->toArray();
		$ateJobIds  = wpml_collect( $ateJobIds )->reject( $this->isAlreadyLoaded( 'ateJobId' ) )->toArray();

		$where = [];

		if ( $wpmlJobIds ) {
			$where[] = 'job_id IN(' . wpml_prepare_in( $wpmlJobIds, '%d' ) . ')';
		}

		if ( $ateJobIds ) {
			$where[] = 'editor_job_id IN(' . wpml_prepare_in( $ateJobIds, '%d' ) . ')';
		}

		if ( ! $where ) {
			return;
		}

		$whereHasJobIds = implode( ' OR ', $where );

		$rows = $this->wpdb->get_results(
			"
			SELECT job_id, editor_job_id
			FROM {$this->wpdb->prefix}icl_translate_job
			WHERE editor = '" . WPML_TM_Editors::ATE . "' AND ({$whereHasJobIds})
		"
		);

		foreach ( $rows as $row ) {
			$job = new JobRecord( $row );
			$this->jobs->put( $job->wpmlJobId, $job );
		}
	}

	private function isAlreadyLoaded( $idPropertyName ) {
		$loadedIds = $this->jobs->pluck( $idPropertyName )->values()->toArray();

		return Lst::includes( Fns::__, $loadedIds );
	}

	public function get_ate_job_id( $wpmlJobId ) {
		return $this->get( $wpmlJobId )->ateJobId;
	}

	public function is_editing_job( $wpmlJobId ) {
		return $this->get( $wpmlJobId )->isEditing();
	}

	public function get( $wpmlJobId ) {
		if ( ! $this->jobs->has( $wpmlJobId ) ) {
			$this->warmCache( [ (int) $wpmlJobId ] );
		}

		$job = $this->jobs->get( $wpmlJobId );

		if ( ! $job || ! $job->ateJobId ) {
			$this->restoreJobDataFromATE( $wpmlJobId );
			$job = $this->jobs->get( $wpmlJobId, new JobRecord() );
		}

		return $job;
	}

	private function restoreJobDataFromATE( $wpmlJobId ) {
		$data = apply_filters( 'wpml_tm_ate_job_data_fallback', [], $wpmlJobId );

		if ( $data ) {
			try {
				$this->store( $wpmlJobId, $data );
			} catch ( Exception $e ) {
				$error_log = new WPML_TM_ATE_API_Error();
				$error_log->log( $e->getMessage() );
			}
		}
	}
}
