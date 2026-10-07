<?php

namespace WPML\Legacy\Component\Translation\Application\Repository;

use WPML\Core\Component\Translation\Application\Repository\TranslationNotFoundException;
use WPML\Core\Component\Translation\Application\Repository\TranslationRepositoryInterface;
use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationType;
use WPML\Core\Port\Persistence\DatabaseWriteInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\PHP\Exception\InvalidArgumentException;

class TranslationRepository implements TranslationRepositoryInterface {

  private $sitepress;

  private $queryHandler;

  private $queryPrepare;

  private $resultMapper;

  private $dbWriter;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare,
    TranslationResultMapper $resultMapper,
    DatabaseWriteInterface $dbWriter,
    $sitepress
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
    $this->resultMapper = $resultMapper;
    $this->dbWriter     = $dbWriter;
    $this->sitepress    = $sitepress;
  }


  public function get( TranslationType $itemType, string $elementType, int $elementId ): Translation {
    $sql = $this->getQuery();

    try {
      $sql = $this->queryPrepare->prepare( $sql, $elementId, $itemType->get() . '_' . $elementType );

      $row = $this->queryHandler->queryOne( $sql );

      if ( ! is_array( $row ) ) {
        throw new TranslationNotFoundException( $itemType, $elementType, $elementId );
      }

      return $this->resultMapper->mapRow( $row );

    } catch ( DatabaseErrorException $e ) {
      throw new TranslationNotFoundException( $itemType, $elementType, $elementId );
    } catch ( InvalidArgumentException $e ) {
      throw new TranslationNotFoundException( $itemType, $elementType, $elementId );
    }
  }


  public function saveElementLanguage(
    TranslationType $itemType,
    string $elementType,
    int $elementId,
    string $languageCode,
    ?string $sourceLanguageCode = null,
    ?int $trid = null
  ) {
    $this->sitepress->set_element_language_details(
      $elementId,
      $itemType->get() . '_' . $elementType,
      $trid,
      $languageCode,
      $sourceLanguageCode,
      true
    );
  }


  private function getQuery(): string {
    return "
      SELECT
        `job`.`job_id`,
        `job`.`automatic`,
        `job`.`editor`,
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
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translations` AS `original` 
      ON `original`.`trid` = `translation`.`trid` AND `original`.`source_language_code` IS NULL
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translation_status` AS `status` 
      ON `status`.`translation_id` = `translation`.`translation_id`
      LEFT JOIN `{$this->queryPrepare->prefix()}icl_translate_job` AS `job` ON `job`.`job_id` = (
        SELECT MAX(`job_id`) 
        FROM `{$this->queryPrepare->prefix()}icl_translate_job` 
        WHERE `rid` = `status`.`rid`
      )
      
      WHERE `translation`.`element_id` = %d 
        AND `translation`.`element_id` != `original`.`element_id` 
        AND `translation`.`element_type` = %s
    ";
  }


  public function setCancelledStatus( int $translationId ): int {
    try {
      return $this->dbWriter->update(
        'icl_translation_status',
        [ 'status' => 0 ],
        [ 'translation_id' => $translationId ]
      );
    } catch ( DatabaseErrorException $e ) {
      return 0;
    }
  }


}
