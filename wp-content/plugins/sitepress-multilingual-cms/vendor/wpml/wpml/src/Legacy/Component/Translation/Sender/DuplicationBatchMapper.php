<?php

namespace WPML\Legacy\Component\Translation\Sender;

use WPML\Core\Component\Translation\Domain\TranslationBatch\DuplicationBatch;

class DuplicationBatchMapper {


  public function map( DuplicationBatch $batch ): \WPML_TM_Translation_Batch {
    $elements    = $this->buildElements( $batch );
    $translators = $this->buildTranslators( $batch );

    return new \WPML_TM_Translation_Batch(
      $elements,
      $batch->getBatchName(),
      $translators
    );
  }


  private function buildElements( DuplicationBatch $batch ): array {
    $targetLanguagesCodes = $batch->getTargetLanguages();

    $targetLanguages = array_combine(
      $targetLanguagesCodes,
      array_fill( 0, count( $targetLanguagesCodes ), 2 )
    );

    $elements = [];
    foreach ( $batch->getPostIds() as $postId ) {
      $mediaTranslations = [];

      $elements[] = new \WPML_TM_Translation_Batch_Element(
        $postId,
        'post',
        $batch->getSourceLanguageCode(),
        $targetLanguages,
        $mediaTranslations
      );
    }

    return $elements;
  }


  private function buildTranslators( DuplicationBatch $batch ): array {
    $targetLanguagesCodes = $batch->getTargetLanguages();

    $result = array_combine(
      $targetLanguagesCodes,
      array_fill( 0, count( $targetLanguagesCodes ), 0 )
    );

    return $result;
  }


}
