<?php

namespace WPML\Legacy\Port;

use SitePress;
use WPML\Core\Port\PluginInterface;
use WPML_TM_ATE_AMS_Endpoints;

class Plugin implements PluginInterface {

  private $sitepress;


  public function __construct( SitePress $sitepress ) {
    $this->sitepress = $sitepress;
  }


  public function getVersion() {
    return defined( 'ICL_SITEPRESS_VERSION' )
      ? ICL_SITEPRESS_VERSION
      : WPML_VERSION;
  }


  public function getVersionWithoutSuffix() {
    return $this->versionWithoutSuffix( $this->getVersion() );
  }


  public function getVersionWhenSetupRan() {
    $version = get_option( 'wpml_start_version' );

    return is_string( $version ) ? $version : '0.0.0';
  }


  public function getVersionWhenSetupRanWithoutSuffix() {
    return $this->versionWithoutSuffix( $this->getVersionWhenSetupRan() );
  }


  public function isSetupComplete() {
    return $this->sitepress->is_setup_complete();
  }


  private function versionWithoutSuffix( $version ) {
    $versionWithoutSuffix = preg_replace( '/[-+].*$/', '', $version );

    return $versionWithoutSuffix ?? '';
  }


  public function getLanguageHomeUrl( string $languageCode ): string {
    $languageUrl = $this->sitepress->language_url( $languageCode );

    return is_string( $languageUrl ) ? $languageUrl : '';
  }


  public function getATEHost(): string {
    if ( ! class_exists( 'WPML_TM_ATE_AMS_Endpoints' ) ) {
      return 'https://ate.wpml.org';
    }
    $endpoints = new WPML_TM_ATE_AMS_Endpoints();
    if ( ! method_exists( $endpoints, 'get_ATE_base_url' ) ) {
      return 'https://ate.wpml.org';
    }

    return $endpoints->get_ATE_base_url();
  }


  public function getAMSHost(): string {
    if ( ! class_exists( 'WPML_TM_ATE_AMS_Endpoints' ) ) {
      return 'https://ams.wpml.org';
    }
    $endpoints = new WPML_TM_ATE_AMS_Endpoints();
    if ( ! method_exists( $endpoints, 'get_AMS_base_url' ) ) {
      return 'https://ams.wpml.org';
    }

    return $endpoints->get_AMS_base_url();
  }


}
