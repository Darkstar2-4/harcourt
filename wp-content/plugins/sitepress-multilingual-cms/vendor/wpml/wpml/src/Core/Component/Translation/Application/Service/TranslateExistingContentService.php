<?php

namespace WPML\Core\Component\Translation\Application\Service;

use WPML\Core\Component\Translation\Application\Repository\SettingsRepository;

class TranslateExistingContentService {

  private $settingsRepository;


  public function __construct( SettingsRepository $settingsRepository ) {
    $this->settingsRepository = $settingsRepository;
  }


  public function handle( array $postTypes, array $packageTypes ) {
    $settings = $this->settingsRepository->getSettings();

    if ( count( $postTypes ) ) {
      $settings->getTranslateEverything()->removeCompletedPosts( $postTypes );
    }

    if ( count( $packageTypes ) ) {
      $settings->getTranslateEverything()->removeCompletedPackages( $packageTypes );
    }

    $this->settingsRepository->saveSettings( $settings );
  }


}
