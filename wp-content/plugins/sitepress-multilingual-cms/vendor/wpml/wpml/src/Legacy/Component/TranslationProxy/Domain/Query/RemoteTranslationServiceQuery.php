<?php

namespace WPML\Legacy\Component\TranslationProxy\Domain\Query;

use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\FetchRemoteTranslationServiceException;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\RemoteTranslationServiceQueryInterface;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\RemoteTranslationServiceDomain;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\RemoteTranslationServiceExtraField;

class RemoteTranslationServiceQuery implements RemoteTranslationServiceQueryInterface {

  private $translationProxy;

  private $translationProxyBasket;


  public function __construct(
    \TranslationProxy $translationProxy,
    \TranslationProxy_Basket $translationProxyBasket
  ) {
    $this->translationProxy       = $translationProxy;
    $this->translationProxyBasket = $translationProxyBasket;
  }


  private function getCurrentLegacy() {
    return $this->translationProxy::get_current_service();
  }


  public function getCurrent( bool $forceRefreshExtraFields = false ) {
    $currentTranslationService = $this->getCurrentLegacy();

    if ( is_wp_error( $currentTranslationService ) ) {
      throw new FetchRemoteTranslationServiceException(
        $currentTranslationService->get_error_message()
      );
    }

    if ( ! $currentTranslationService ) {
      return null;
    }

    $serviceRequiresAuthentication
      = $this->translationProxy::service_requires_authentication( $currentTranslationService );

    $maximumJobsPerBatch = isset( $currentTranslationService->maximumJobsPerBatch )
                           && $currentTranslationService->maximumJobsPerBatch > 0
      ? $currentTranslationService->maximumJobsPerBatch
      : null;

    $autoRefreshProjectOptions = isset( $currentTranslationService->auto_refresh_project_options )
                                 && (bool) $currentTranslationService->auto_refresh_project_options;

    $translationServiceDomain = new RemoteTranslationServiceDomain(
      $currentTranslationService->id,
      $currentTranslationService->name,
      $serviceRequiresAuthentication,
      $currentTranslationService->description,
      $currentTranslationService->url,
      $currentTranslationService->logo_url,
      (array) $currentTranslationService->custom_fields,
      (array) $currentTranslationService->custom_fields_data,
      [],
      $maximumJobsPerBatch,
      $autoRefreshProjectOptions
    );

    if ( $translationServiceDomain->isAuthenticated() ) {
      $translationServiceDomain->setExtraFields( $this->getExtraFields( $forceRefreshExtraFields ) );
    }

    return $translationServiceDomain;
  }


  public function getExtraFields( bool $forceRefreshExtraFields = false ): array {
    $localExtraFields = $this->translationProxy::get_extra_fields_local();

    $forceRefresh = false;

    if ( ! $localExtraFields || $forceRefreshExtraFields ) {
      $forceRefresh = true;
    }

    try {
      $extraFields = $this->translationProxyBasket::get_basket_extra_fields_array( $forceRefresh );
      if ( ! is_array( $extraFields ) ) {
        return [];
      }

      return array_map(
        function ( \WPML_TP_Extra_Field $field ) {
          return new RemoteTranslationServiceExtraField(
            $field->type,
            $field->label,
            $field->name,
            $field->items
          );
        },
        array_filter(
          $extraFields,
          function ( $field ) {
            return $field instanceof \WPML_TP_Extra_Field;
          }
        )
      );

    } catch ( \Throwable $e ) {
      return [];
    }
  }


}
