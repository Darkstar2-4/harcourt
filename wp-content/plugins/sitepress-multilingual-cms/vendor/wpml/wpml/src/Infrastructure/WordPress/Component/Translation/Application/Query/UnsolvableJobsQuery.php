<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Application\Query\UnsolvableJobsQueryInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class UnsolvableJobsQuery implements UnsolvableJobsQueryInterface {

  private $queryHandler;

  private $queryPrepare;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
  }


  public function getUnsolvableJobs(): array {
    $query = "
			SELECT 
				job.job_id AS jobId,
				error.ate_job_id AS ateJobId,
				status.status AS status,
				error.error_message AS message,
				error.error_type AS errorType,
				error.error_data AS errorData
			FROM {$this->queryPrepare->prefix()}icl_translate_unsolvable_jobs error
			INNER JOIN {$this->queryPrepare->prefix()}icl_translate_job job
				ON job.job_id = error.job_id
			INNER JOIN {$this->queryPrepare->prefix()}icl_translation_status status
				ON status.rid = job.rid
			INNER JOIN (
				SELECT rid, MAX(job_id) AS latest_job_id
				FROM {$this->queryPrepare->prefix()}icl_translate_job
				WHERE editor = 'ATE'
				GROUP BY rid
			) latest_jobs
				ON latest_jobs.rid = job.rid
				AND latest_jobs.latest_job_id = job.job_id
			WHERE (
				error.error_type = 'SyncError'
				OR (error.error_type = 'DownloadError' AND error.counter >= 3)
			)
			AND job.editor = 'ATE'
			AND status.status IN (1, 2)
		";

    try {
      $rows = $this->queryHandler->query( $query );

      return $this->mapResults( $rows->getResults() );
    } catch ( DatabaseErrorException $e ) {
      return [];
    }
  }


  private function mapResults( array $rows ): array {
    return array_map(
      function ( $row ) {
        $errorData = is_string( $row['errorData'] ) ? json_decode( $row['errorData'], true ) : [];
        $errorData = is_array( $errorData ) ? $errorData : [];

        $jobId     = isset( $row['jobId'] ) && is_numeric( $row['jobId'] ) ? (int) $row['jobId'] : 0;
        $ateJobId  = isset( $row['ateJobId'] ) && is_numeric( $row['ateJobId'] ) ? (int) $row['ateJobId'] : 0;
        $status    = isset( $row['status'] ) && is_numeric( $row['status'] ) ? (int) $row['status'] : 0;
        $ateStatus = isset( $errorData['ateStatus'] ) && is_numeric( $errorData['ateStatus'] )
          ? (int) $errorData['ateStatus']
          : 0;
        $message   = isset( $row['message'] ) && is_string( $row['message'] ) && $row['message'] !== ''
          ? $row['message']
          : 'Job marked as unsolvable by ATE';
        $errorType = isset( $row['errorType'] ) && is_string( $row['errorType'] )
          ? $row['errorType']
          : 'Unknown';

        return [
          'jobId'        => $jobId,
          'ateJobId'     => $ateJobId,
          'ateStatus'    => $ateStatus,
          'status'       => $status,
          'isUnsolvable' => true,
          'message'      => $message,
          'errorType'    => $errorType,
          'errorData'    => isset( $row['errorData'] ) && is_string( $row['errorData'] ) ? $row['errorData'] : null,
          'originalElementId' => $this->getErrorDataValue( $errorData, 'original_doc_id' ),
          'elementId' => $this->getErrorDataValue( $errorData, 'elementId' ),
        ];
      },
      $rows
    );
  }


  private function getErrorDataValue( array $errorData, string $key ) {
    if ( ! isset( $errorData['jobData'] ) || ! is_array( $errorData['jobData'] ) ) {
      return null;
    }
    return $errorData['jobData'][$key] ?? null;
  }


}
