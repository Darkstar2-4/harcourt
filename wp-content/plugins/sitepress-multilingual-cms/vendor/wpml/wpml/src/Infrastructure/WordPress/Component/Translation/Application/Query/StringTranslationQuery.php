<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\PHP\Exception\InvalidArgumentException;

class StringTranslationQuery {

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


  public function getStringTranslations( array $stringIds ): array {
    if ( empty( $stringIds ) ) {
      return [];
    }

    $ids = implode( ',', array_map( 'intval', $stringIds ) );
    $query = $this->getBasicQuery() . "WHERE s.id IN ($ids)";

    try {
      $rows = $this->queryHandler->query( $query );

      return $this->mapResult( $rows->getResults() );
    } catch ( DatabaseErrorException $e ) {
      return [];
    } catch ( InvalidArgumentException $e ) {
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
        `job`.`translated` AS `job_completed`,
        `st`.`status`,
        `status`.`batch_id`,
        `status`.`translation_service`,
        `status`.`translator_id`,
        `status`.needs_update,
        `st`.`id` AS `translation_id`,
        `s`.`language` AS `source_language_code`,
        `st`.`language` AS `language_code`,
        'st-batch' AS `element_type`,
        `st`.`id` AS `translated_element_id`,
        `s`.`id` AS `original_element_id`
      FROM `{$this->queryPrepare->prefix()}icl_string_translations` AS `st`
      INNER JOIN `{$this->queryPrepare->prefix()}icl_strings` AS `s` 
      ON `st`.`string_id` = `s`.`id`
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_string_batches` AS `batch`
      ON `batch`.`string_id` = `s`.`id`
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translations` AS `translation`
      ON `translation`.`element_id` = `batch`.`batch_id` AND `translation`.`element_type` = 'st-batch'
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translation_status` AS `status` 
      ON `status`.`translation_id` = `translation`.`translation_id`
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translate_job` AS `job` ON `job`.`rid` = `status`.`rid`
    ";
  }


}
