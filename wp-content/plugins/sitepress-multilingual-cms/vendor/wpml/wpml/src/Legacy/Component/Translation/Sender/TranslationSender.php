<?php

namespace WPML\Legacy\Component\Translation\Sender;

use WPML\Core\Component\Translation\Application\Query\TranslationQueryInterface;
use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationExtraInformationDto;
use WPML\Core\Component\Translation\Domain\Sender\SendBatchException;
use WPML\Core\Component\Translation\Domain\Sender\TranslationSenderInterface;
use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;
use WPML\Core\Component\Translation\Domain\TranslationMethod\TranslationServiceMethod;
use WPML\Legacy\Component\Translation\Sender\ErrorMapper\ErrorMapper;

class TranslationSender implements TranslationSenderInterface {

  const SEND_VIA_DASHBOARD = 6;

  private $legacyTranslationManagement;

  private $translationBatchMapper;

  private $translationQuery;

  private $errorMapper;


  public function __construct(
    TranslationBatchMapper $translationBatchMapper,
    TranslationQueryInterface $translationQuery,
    ErrorMapper $errorMapper
  ) {
    $this->legacyTranslationManagement = \wpml_load_core_tm();
    $this->translationBatchMapper      = $translationBatchMapper;
    $this->translationQuery            = $translationQuery;
    $this->errorMapper                 = $errorMapper;
  }


  public function send( TranslationBatch $batch ): array {

    $this->setTargetLanguagesInTranslationProxy( $batch );

    $translationProxyBatchInfo = null;

    $batchHasJobsForTranslationProxy = $this->getTargetLanguagesForTranslationProxy( $batch );

    if ( $batchHasJobsForTranslationProxy ) {
      $translationProxyBatchInfo = [
        'batchName'   => $batch->getBatchName(),
        'deadline'    => $batch->getDeadline(),
        'extraFields' => $batch->getTranslationServiceExtraFields()
      ];
    }

    $legacyBatches = $this->translationBatchMapper->map( $batch, $translationProxyBatchInfo );

    $jobIds = [];

    foreach ( $legacyBatches as $legacyBatch ) {
      foreach ( $this->getElementTypes() as $type ) {
        do_action(
          'wpml_tm_send_' . $type . '_jobs',
          $legacyBatch,
          $type,
          self::SEND_VIA_DASHBOARD
        );
      }

      $errors = $this->legacyTranslationManagement->messages_by_type( 'error' );
      if ( is_array( $errors ) ) {
        $errorMessage = $this->errorMapper->map( $errors );
        throw new SendBatchException( $errorMessage );
      }

      do_action( 'wpml_tm_jobs_notification' );

      $jobIdsOfLegacy = $this->legacyTranslationManagement->get_sent_job_ids();
      if ( is_array( $jobIdsOfLegacy ) ) {
        $jobIds = array_merge( $jobIds, $jobIdsOfLegacy );
      }
    }

    if ( $jobIds ) {
      return $this->translationQuery->getManyByJobIds( $jobIds );
    }

    return [];
  }


  private function getTargetLanguagesForTranslationProxy( TranslationBatch $batch ): array {
    $targetLanguages = [];

    foreach ( $batch->getTargetLanguages() as $targetLanguage ) {
      if ( $targetLanguage->getMethod() instanceof TranslationServiceMethod ) {
        $targetLanguages[] = $targetLanguage->getLanguageCode();
      }
    }

    return array_unique( $targetLanguages );
  }


  private function setTargetLanguagesInTranslationProxy( TranslationBatch $batch ) {
    $targetLanguages = $this->getTargetLanguagesForTranslationProxy( $batch );
    if ( $targetLanguages ) {
      \TranslationProxy_Basket::set_remote_target_languages( $targetLanguages );
    }

  }


  private function getElementTypes(): array {
    $types = \apply_filters(
      'wpml_tm_basket_items_types',
      [
        'st-batch' => 'core',
        'post'     => 'core',
        'package'  => 'custom',
      ]
    );

    return array_keys( $types );
  }


  public function rollback( TranslationBatch $batch ) {
    \WPML\TM\API\Batch::rollback( $batch->getBatchName() );
  }


}
