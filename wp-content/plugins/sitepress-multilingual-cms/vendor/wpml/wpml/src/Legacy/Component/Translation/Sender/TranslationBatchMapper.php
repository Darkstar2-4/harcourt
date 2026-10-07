<?php

namespace WPML\Legacy\Component\Translation\Sender;

use WPML\Core\Component\Translation\Domain\TranslationBatch\TargetLanguage;
use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;
use WPML\Core\Component\Translation\Domain\TranslationMethod;
use WPML\Core\Component\Translation\Domain\TranslationMethod\AutomaticMethod;
use WPML\Core\Component\Translation\Domain\TranslationMethod\TranslationServiceMethod;
use WPML\Core\Component\Translation\Domain\TranslationType;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationMethod\TargetLanguageMethodType;
use function WPML\PHP\partition;

class TranslationBatchMapper {


  public function map( TranslationBatch $batch, $translationProxyBatchInfo = null ): array {
    list(
      $automaticBatch,
      $manualBatch
      ) = $this->divideBatchIntoAutomaticAndManual( $batch );

    $legacyBatches = [];

    if ( $automaticBatch ) {
      $automaticBatch = $this->buildBatch( $automaticBatch );
      $automaticBatch->setTranslationMode( 'auto' );

      $legacyBatches[] = $automaticBatch;
    }

    if ( $manualBatch ) {
      $manualBatch = $this->buildBatch( $manualBatch, $translationProxyBatchInfo );
      $manualBatch->setTranslationMode( 'manual' );
      $legacyBatches[] = $manualBatch;
    }

    return $legacyBatches;
  }


  private function divideBatchIntoAutomaticAndManual( TranslationBatch $batch ): array {
    list( $automatic, $manual ) = partition(
      $batch->getTargetLanguages(),
      function ( TargetLanguage $targetLanguage ) {
        return $targetLanguage->getMethod() instanceof AutomaticMethod;
      }
    );

    $automaticBatch = null;
    $manualBatch    = null;

    if ( count( $automatic ) ) {
      $automaticBatch = $batch->copyWithNewTargetLanguages( $automatic );
    }
    if ( count( $manual ) ) {
      $manualBatch = $batch->copyWithNewTargetLanguages( $manual );
    }

    return [ $automaticBatch, $manualBatch ];
  }


  private function buildBatch(
    TranslationBatch $batch,
    $translationProxyBatchInfo = null
  ): \WPML_TM_Translation_Batch {
    $elements    = $this->buildElements( $batch );
    $translators = $this->buildTranslators( $batch );
    $deadline    = $batch->getDeadline();

    $legacyBatch = new \WPML_TM_Translation_Batch(
      $elements,
      $batch->getBatchName(),
      $translators,
      $deadline,
      $translationProxyBatchInfo
    );

    $legacyBatch->setHowToHandleExisting( $batch->getHowToHandleExisting() );

    return $legacyBatch;
  }


  private function buildElements( TranslationBatch $batch ): array {
    $sourceLanguageCode = $batch->getSourceLanguageCode();

    $elements = [];

    $elementsGroupedByTypeAndId = [];
    foreach ( $batch->getTargetLanguages() as $targetLanguage ) {
      foreach ( $targetLanguage->getElements() as $element ) {
        $t                                         = $element->getType()->get();
        $id                                        = $element->getElementId();
        $elementsGroupedByTypeAndId[ $t ][ $id ][] = $targetLanguage->getLanguageCode();
      }
    }

    foreach ( $elementsGroupedByTypeAndId as $type => $idAndTargetLanguages ) {
      foreach ( $idAndTargetLanguages as $elementId => $targetLanguages ) {
        $mediaToTranslations = [];

        $targetLanguages = array_combine(
          $targetLanguages,
          array_fill( 0, count( $targetLanguages ), 1 )
        );

        $elements[] = new \WPML_TM_Translation_Batch_Element(
          $elementId,
          $type === TranslationType::STRING_BATCH ? 'st-batch' : $type,
          $sourceLanguageCode,
          $targetLanguages,
          $mediaToTranslations
        );
      }
    }

    return $elements;
  }


  private function buildTranslators( TranslationBatch $batch ): array {
    $translators = [];

    foreach ( $batch->getTargetLanguages() as $targetLanguage ) {
      $translationMethod = $targetLanguage->getMethod();

      if ( $translationMethod instanceof TranslationServiceMethod ) {
        $translators[ $targetLanguage->getLanguageCode() ]
          = 'ts-' . $translationMethod->getServiceId();
      } else if ( $translationMethod instanceof TranslationMethod\LocalTranslatorMethod ) {
        $translators[ $targetLanguage->getLanguageCode() ]
          = $translationMethod->getTranslatorId();
      } else if ( $translationMethod->get() === TargetLanguageMethodType::AUTOMATIC ) {
        $translators[ $targetLanguage->getLanguageCode() ] = 0;
      }
    }

    return $translators;
  }


}
