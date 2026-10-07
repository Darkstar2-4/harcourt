<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config;

use WPML\ConfigEventsInterface;
use WPML\DicInterface;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\SiteLock\SiteLockDetectedEvent;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\Item\WordCount\Events as WordCountEvents;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\UrlHandling\SlugPercentEncodedFixEvent;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\WpmlPosthog\PostHogShouldRecordEvent;


class ConfigEvents implements ConfigEventsInterface {

  private $dic;


  public function __construct( DicInterface $dic ) {
    $this->dic = $dic;
  }


  public function loadEvents() {
    new Event\Translation\Links\ItemUpdateEvent( $this->dic );
    new Event\Translation\Posts\PostInsertedEvent( $this->dic );
    new Event\Translation\Posts\PageBuilderEditWarningEvent( $this->dic );
    new Event\Translation\StartUsingDashboardBanner\Events( $this->dic );
    new WordCountEvents( $this->dic );
    new PostHogShouldRecordEvent( $this->dic );
    new SiteLockDetectedEvent( $this->dic );
    new Event\ReportContentStats\LanguageChangeEvent( $this->dic );
    new Event\ReportContentStats\TranslationCompletedEvent( $this->dic );
    new Event\ReportContentStats\ContentChangeEvent( $this->dic );
    new Event\UrlHandling\SlugPercentEncodedFixEvent( $this->dic );
  }


}
