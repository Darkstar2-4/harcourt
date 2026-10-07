<?php

namespace WPML;

use WPML\Legacy\Component\WordsToTranslate\Domain\Post\JobQuery;
use WPML\Legacy\Component\WordsToTranslate\Domain\StringPackage\JobQuery as StringPackageJobQuery;

class CompositionRoot {

  private $dic;

  private $config;

  private $configEvents;


  public function __construct(
    DicInterface $dic,
    ConfigInterface $config,
    ConfigEventsInterface $configEvents
  ) {
    $this->dic          = $dic;
    $this->config       = $config;
    $this->configEvents = $configEvents;

    $this->defineShares();
    $this->defineAliases();
    $this->defineClasses();
  }


  public function loadRESTEndpoints() {
    $this->config->loadRESTEndpoints();
  }


  public function loadAjaxEndpoints() {
    $this->config->loadAjaxEndpoints();
  }


  public function registerAdminPages() {
    $this->config->registerAdminPages();
  }


  public function loadAdminNotices() {
    $this->config->loadAdminNotices();
  }


  public function loadEventListeners() {
    $this->configEvents->loadEvents();
  }


  public function loadAdminScripts() {
    $this->config->loadAdminScripts();
  }


  public function prepareUpdates() {
    $this->config->prepareUpdates();
  }


  public function loadContentStatsScripts() {
    $this->config->loadContentStatsScripts();
  }


  public function loadCheckPosthogShouldRecordScript() {
    $this->config->loadCheckPosthogShouldRecordScript();
  }


  private function defineAliases() {
    foreach ( $this->config->getInterfaceMappings() as $interface => $class ) {
      $this->dic->alias( $interface, $class );
    }
  }


  private function defineClasses() {
    foreach ( $this->config->getClassDefinitions() as $class => $args ) {
      if ( is_callable( $args ) ) {
        $wrappedFactory = function () use ( $args ) {
          return call_user_func( $args, $this->dic );
        };

        $this->dic->delegate( $class, $wrappedFactory );
      } else {
        $this->dic->define( $class, $args );
      }
    }
  }


  private function defineShares() {
    global $wpdb, $sitepress;
    $this->dic->share( $wpdb );
    $this->dic->share( $sitepress );
    $this->dic->share( JobQuery::class );
    $this->dic->share( StringPackageJobQuery::class );
    $this->dic->defineParam( 'wpdb', $wpdb );
    $this->dic->defineParam( 'sitepress', $sitepress );
  }


}
