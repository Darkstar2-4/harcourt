<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Domain\Repository;

use WPML\Core\Component\Translation\Domain\Entity\JobError;
use WPML\Core\Component\Translation\Domain\Repository\JobErrorRepositoryInterface;
use WPML\Core\Port\Persistence\DatabaseWriteInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class JobErrorRepository implements JobErrorRepositoryInterface {

  private $queryHandler;

  private $queryPrepare;

  private $dbWriter;

  const TABLE_NAME = 'icl_translate_unsolvable_jobs';


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare,
    DatabaseWriteInterface $dbWriter
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
    $this->dbWriter = $dbWriter;
  }


  public function findByJobId( int $jobId ) {
    $tableName = $this->queryPrepare->prefix() . self::TABLE_NAME;
    $sql = $this->queryPrepare->prepare(
      "SELECT *
        FROM {$tableName}
        WHERE job_id = %d
        LIMIT 1",
      $jobId
    );

    $row = $this->queryHandler->queryOne( $sql );

    if ( ! $row ) {
      return null;
    }

    return $this->mapRowToEntity( $row );
  }


  public function insert( JobError $jobError ) {
    $errorDataJson = wp_json_encode( $jobError->getErrorData() );
    $data = [
      'job_id' => $jobError->getJobId(),
      'ate_job_id' => $jobError->getAteJobId(),
      'error_type' => $jobError->getErrorType(),
      'error_message' => $jobError->getErrorMessage(),
      'error_data' => $errorDataJson !== false ? $errorDataJson : '',
      'counter' => $jobError->getCounter(),
      'created_at' => current_time( 'mysql' ),
      'last_update' => current_time( 'mysql' ),
    ];

    $this->dbWriter->insert( self::TABLE_NAME, $data );
  }


  public function incrementCounter( int $jobId ) {
    $existing = $this->findByJobId( $jobId );

    if ( ! $existing ) {
      return;
    }

    $this->dbWriter->update(
      self::TABLE_NAME,
      [
        'counter' => $existing->getCounter() + 1,
        'last_update' => current_time( 'mysql' ),
      ],
      [ 'job_id' => $jobId ]
    );
  }


  public function delete( int $jobId ) {
    $this->dbWriter->delete( self::TABLE_NAME, [ 'job_id' => $jobId ] );
  }


  public function count(): int {
    $tableName = $this->queryPrepare->prefix() . self::TABLE_NAME;
    $jobsTable = $this->queryPrepare->prefix() . 'icl_translate_job';
    $statusTable = $this->queryPrepare->prefix() . 'icl_translation_status';

    $sql = "SELECT COUNT(*) as count
            FROM {$tableName} errors
            INNER JOIN (
                SELECT rid, MAX(job_id) AS latest_job_id
                FROM {$jobsTable}
                WHERE editor = 'ATE'
                GROUP BY rid
            ) latest_jobs ON latest_jobs.latest_job_id = errors.job_id
            INNER JOIN {$statusTable} status ON status.rid = latest_jobs.rid
            WHERE (
                errors.error_type = 'SyncError'
                OR (errors.error_type = 'DownloadError' AND errors.counter >= 3)
            )
            AND status.status IN (1, 2)";

    $count = $this->queryHandler->querySingle( $sql );

    return (int) $count;
  }


  private function mapRowToEntity( array $row ): JobError {
    $errorData = [];
    if ( isset( $row['error_data'] ) && is_string( $row['error_data'] ) ) {
      $decoded = json_decode( $row['error_data'], true );
      if ( is_array( $decoded ) ) {
        $errorData = $decoded;
      }
    }

    $jobId = isset( $row['job_id'] ) && is_numeric( $row['job_id'] ) ? (int) $row['job_id'] : 0;
    $ateJobId = isset( $row['ate_job_id'] ) && is_numeric( $row['ate_job_id'] ) ? (int) $row['ate_job_id'] : 0;
    $errorType = isset( $row['error_type'] ) && is_string( $row['error_type'] ) ? $row['error_type'] : '';
    $errorMessage = isset( $row['error_message'] ) && is_string( $row['error_message'] ) ? $row['error_message'] : '';
    $counter = isset( $row['counter'] ) && is_numeric( $row['counter'] ) ? (int) $row['counter'] : 1;

    return new JobError(
      $jobId,
      $ateJobId,
      $errorType,
      $errorMessage,
      $errorData,
      $counter
    );
  }


}
