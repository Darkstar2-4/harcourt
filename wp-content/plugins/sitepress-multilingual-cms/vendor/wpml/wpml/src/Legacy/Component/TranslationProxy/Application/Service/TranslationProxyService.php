<?php

namespace WPML\Legacy\Component\TranslationProxy\Application\Service;

use WPML\Core\Component\TranslationProxy\Application\Service\SendTranslationProxyCommitRequestException;
use WPML\Core\Component\TranslationProxy\Application\Service\TranslationProxyServiceInterface;

class TranslationProxyService implements TranslationProxyServiceInterface {

  private $legacyTranslationProxyProject;


  public function __construct() {

    $currentService = \TranslationProxy::get_current_service();

    if ( is_wp_error( $currentService ) || $currentService === false ) {
      $this->legacyTranslationProxyProject = false;
    } else {
      $this->legacyTranslationProxyProject = new \TranslationProxy_Project(
        $currentService,
        'xmlrpc',
        \TranslationProxy::get_tp_client()
      );
    }
  }


  public function sendCommitRequest() {

    if ( ! $this->legacyTranslationProxyProject ) {
      return false;
    }

    try {
      $result = $this->legacyTranslationProxyProject->commit_batch_job();
      if ( ! $result ) {
        return false;
      }

      $batchJobId = $this->legacyTranslationProxyProject->get_batch_job_id();

      if ( ! is_numeric( $batchJobId ) ) {
        return false;
      }

      $batchJobId = (int) $batchJobId;

      if ( ! $batchJobId ) {
        return false;
      }

      do_action( 'wpml_tm_jobs_notification' );

      \TranslationProxy_Basket::cleanBasket();

      return $batchJobId;
    } catch ( \Throwable $e ) {
      throw new SendTranslationProxyCommitRequestException( $e->getMessage() );
    }
  }


  public function getTPUrl(): string {
    return OTG_TRANSLATION_PROXY_URL;
  }


}
