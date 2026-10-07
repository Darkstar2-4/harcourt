<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Application\Query\TranslationQueryInterface;
use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationType;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\PHP\Exception\InvalidArgumentException;

class TranslationQuery implements TranslationQueryInterface {

  private $queryHandler;

  private $queryPrepare;

  private $resultMapper;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare,
    TranslationResultMapper $resultMapper
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
    $this->resultMapper = $resultMapper;
  }


  public function getManyByJobIds( array $jobIds ): array {
    if ( empty( $jobIds ) ) {
      return [];
    }

    $ids   = $this->queryPrepare->prepareIn( $jobIds, '%d' );
    $query = $this->getBasicQueryForJobIds() . " AND job.job_id IN ($ids) LIMIT " . count( $jobIds );

    try {
      $rows = $this->queryHandler->query( $query );

      return $this->mapResult( $rows->getResults() );
    } catch ( DatabaseErrorException $e ) {
      return [];
    } catch ( InvalidArgumentException $e ) {
      return [];
    }
  }


  public function getOneByJobId( int $jobId ) {
    $query = $this->getBasicQueryForJobIds() . " AND job.job_id = %d LIMIT 1";

    try {
      $row = $this->queryHandler->queryOne(
        $this->queryPrepare->prepare( $query, $jobId )
      );
      if ( ! is_array( $row ) ) {
        return null;
      }

      return $this->resultMapper->mapRow( $row );
    } catch ( DatabaseErrorException $e ) {
      return null;
    } catch ( InvalidArgumentException $e ) {
      return null;
    }
  }


  public function getManyByTranslatedElementIds( array $translatedElementIds ): array {
    if ( empty( $translatedElementIds ) ) {
      return [];
    }

    $ids   = $this->queryPrepare->prepareIn( $translatedElementIds, '%d' );
    $query = $this->getBasicQueryWithMaxJobId()
             . " WHERE translation.element_id IN ($ids) LIMIT "
             . count( $translatedElementIds );

    try {
      $rows = $this->queryHandler->query( $query );

      return $this->mapResult( $rows->getResults() );
    } catch ( DatabaseErrorException $e ) {
      return [];
    } catch ( InvalidArgumentException $e ) {
      return [];
    }
  }


  public function getManyByElementIds(
    TranslationType $translationType,
    array $elementIds
  ): array {
    if ( empty( $elementIds ) ) {
      return [];
    }

    foreach ( $elementIds as $elementId ) {
      if ( ! is_int( $elementId ) || $elementId <= 0 ) {
        throw new InvalidArgumentException( 'Element IDs must be positive integers.' );
      }
    }

    $ids  = $this->queryPrepare->prepareIn( $elementIds, '%d' );
    $type = $this->queryPrepare->prepare(
      " AND translation.element_type LIKE %s",
      $translationType->get() . '_%'
    );

    $tridIn = " WHERE original.trid IN (
    SELECT trid from `{$this->queryPrepare->prefix()}icl_translations`
    WHERE element_id IN ($ids)
    )";

    $sourceLanguageNotNull = " AND translation.source_language_code IS NOT NULL";

    $query = $this->getBasicQueryWithMaxJobId() . $tridIn . $type . $sourceLanguageNotNull;

    try {
      $rows = $this->queryHandler->query( $query );

      return $this->mapResult( $rows->getResults() );
    } catch ( DatabaseErrorException $e ) {
      return [];
    } catch ( InvalidArgumentException $e ) {
      return [];
    }
  }


  public function getJobIdsByBatchId( int $batchId ): array {
    $query = $this->queryPrepare->prepare(
      "SELECT job.job_id
       FROM `{$this->queryPrepare->prefix()}icl_translate_job` AS job
       INNER JOIN `{$this->queryPrepare->prefix()}icl_translation_status` AS status
         ON status.rid = job.rid
       WHERE status.batch_id = %d AND job.revision IS NULL",
      $batchId
    );

    try {
      $rows = $this->queryHandler->query( $query );

      return array_map( 'intval', array_column( $rows->getResults(), 'job_id' ) );
    } catch ( DatabaseErrorException $e ) {
      return [];
    }
  }


  private function mapResult( array $rowset ): array {
    return array_map(
      [
        $this->resultMapper,
        'mapRow'
      ],
      $rowset
    );
  }


  private function getBasicQuery(): string {
    return "
      SELECT
        `job`.`job_id`,
        `job`.`automatic`,
        `job`.`editor`,
        `job`.`editor_job_id`,
        `job`.`translated` AS `job_completed`,
        `status`.`status`,
        `status`.`batch_id`,
        `status`.`translation_service`,
        `status`.`translator_id`,
        `status`.`review_status`,
        `status`.`needs_update`,
        `translation`.`translation_id`,
        `translation`.`source_language_code`,
        `translation`.`language_code`,
        `translation`.`element_type`,
        `translation`.`element_id` AS `translated_element_id`,
        `original`.`element_id` AS `original_element_id`
        FROM `{$this->queryPrepare->prefix()}icl_translations` AS `translation`
      INNER JOIN `{$this->queryPrepare->prefix()}icl_translations` AS `original`
      ON `original`.`trid` = `translation`.`trid` AND `original`.`source_language_code` IS NULL
      INNER JOIN `{$this->queryPrepare->prefix()}icl_translation_status` AS `status`
      ON `status`.`translation_id` = `translation`.`translation_id`";
  }


  private function innerJoinJobsTable(): string {
    return "
      INNER JOIN `{$this->queryPrepare->prefix()}icl_translate_job` AS `job` 
        ON `job`.`rid` = `status`.`rid`";
  }


  private function leftJoinWithMaxJobId(): string {
    return "
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translate_job` AS `job` ON `job`.`job_id` = (
        SELECT MAX(`job_id`) 
        FROM `{$this->queryPrepare->prefix()}icl_translate_job` 
        WHERE `rid` = `status`.`rid`
      )";
  }


  private function getBasicQueryForJobIds(): string {
    return $this->getBasicQuery() . $this->innerJoinJobsTable();
  }


  private function getBasicQueryWithMaxJobId(): string {
    return $this->getBasicQuery() . $this->leftJoinWithMaxJobId();
  }


}
