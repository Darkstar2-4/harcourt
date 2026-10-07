<?php

namespace WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs\DtoCollectionBuilder;

use WPML\Core\Component\Translation\Application\String\Query\StringsFromBatchQueryInterface;
use WPML\Core\Component\Translation\Domain\Translation;

class BatchStringIdExtractor {

  private $stringsFromBatchQuery;


  public function __construct( StringsFromBatchQueryInterface $stringsFromBatchQuery ) {
    $this->stringsFromBatchQuery = $stringsFromBatchQuery;
  }


  public function extract( array $translations ): array {
    $batchIdToStringIdsMap = [];

    foreach ( $translations as $translation ) {
      $type      = $translation->getType()->get();
      $elementId = $translation->getOriginalElementId();

      if ( $type === 'string-batch' || $type === 'string' ) {
        if ( ! isset( $batchIdToStringIdsMap[ $elementId ] ) ) {
          $batchIdToStringIdsMap[ $elementId ] = $this->stringsFromBatchQuery->get( $elementId );
        }
      }
    }

    return $batchIdToStringIdsMap;
  }


}
