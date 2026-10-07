<?php

namespace WPML\Legacy\Component\Translation\Sender;

use WPML\Core\Component\Translation\Application\Query\TranslationQueryInterface;
use WPML\Core\Component\Translation\Domain\Sender\DuplicationSenderInterface;
use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationBatch\DuplicationBatch;

class DuplicationSender implements DuplicationSenderInterface {

  const SEND_VIA_DASHBOARD = 6;

  private $legacyTranslationManagement;

  private $duplicationBatchMapper;

  private $translationQuery;


  public function __construct(
    DuplicationBatchMapper $duplicationBatchMapper,
    TranslationQueryInterface $translationQuery
  ) {
    $this->legacyTranslationManagement = \wpml_load_core_tm();
    $this->duplicationBatchMapper      = $duplicationBatchMapper;
    $this->translationQuery            = $translationQuery;
  }


  public function send( DuplicationBatch $batch ): array {
    $legacyBatch = $this->duplicationBatchMapper->map( $batch );

    do_action(
      'wpml_tm_send_post_jobs',
      $legacyBatch,
      'post',
      self::SEND_VIA_DASHBOARD
    );

    $translatedPostIds = $this->legacyTranslationManagement->get_sent_job_ids();
    if ( ! is_array( $translatedPostIds ) ) {
      return [];
    }

    if ( $translatedPostIds ) {
      return $this->translationQuery->getManyByTranslatedElementIds( $translatedPostIds );
    }

    return [];
  }


}
