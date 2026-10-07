<?php

namespace WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\Core\Port\Persistence\ResultCollection;
use WPML\Core\Port\Persistence\ResultCollectionInterface;
use WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery;

class TranslationsQuery {

  private $queryPrepare;

  private $queryHandler;


  public function __construct(
    QueryPrepareInterface $queryPrepare,
    QueryHandlerInterface $queryHandler
  ) {
    $this->queryPrepare = $queryPrepare;
    $this->queryHandler = $queryHandler;
  }


  public function get(
    ResultCollectionInterface $posts,
    string $postType,
    string $sourceLanguageCode
  ): ResultCollectionInterface {
    $query = $this->buildQuery( $posts, $postType, $sourceLanguageCode );
    if ( ! $query ) {
      return new ResultCollection( [] );
    }

    return $this->queryHandler->query( $query );
  }


  private function buildQuery(
    ResultCollectionInterface $posts,
    string $postType,
    string $sourceLanguageCode
  ) {
    $postIds = [];

    foreach ( $posts->getResults() as $post ) {
      $postIds[] = (int) $post['ID'];
    }

    if ( empty( $postIds ) ) {
      return null;
    }

    $gluedPostIds = implode( ',', $postIds );

    $sql = "
      SELECT
        target_t.language_code,
        target_t.element_id,
        target_t.trid,
        source_t.element_id as original_element_id,
        ts.translation_id,
        ts.status,
        ts.review_status,
        ts.needs_update,
        tj.rid,
        tj.job_id,
        tj.translator_id,
        tj.automatic,
        ts.translation_service,
        tj.editor,
        tj.editor_job_id
      FROM {$this->queryPrepare->prefix()}icl_translations source_t

      INNER JOIN {$this->queryPrepare->prefix()}icl_translations target_t
        ON target_t.trid = source_t.trid

      LEFT JOIN {$this->queryPrepare->prefix()}icl_translation_status ts
            ON ts.translation_id = target_t.translation_id

      LEFT JOIN {$this->queryPrepare->prefix()}icl_translate_job tj
        ON tj.job_id = (
            SELECT MAX(job_id)
            FROM {$this->queryPrepare->prefix()}icl_translate_job
            WHERE rid = ts.rid
        )

      WHERE target_t.language_code != %s
        AND source_t.element_type = %s
        AND source_t.element_id IN ($gluedPostIds)
    ";

    return $this->queryPrepare->prepare( $sql, $sourceLanguageCode, 'post_' . $postType );
  }


}
