<?php

namespace WPML\UserInterface\Web\Core\Component\Preferences\Application;

use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\PageRequirementsInterface;

class AutomaticTranslationsSectionController implements
  ScriptPrerequisitesInterface,
  PageRequirementsInterface,
  ScriptDataProviderInterface {

  private $atePreferencesLoader;

  private $languagePreferencesLoader;


  public function __construct(
    AtePreferencesLoader $atePreferencesLoader,
    LanguagePreferencesLoader $languagePreferencesLoader
  ) {
    $this->atePreferencesLoader      = $atePreferencesLoader;
    $this->languagePreferencesLoader = $languagePreferencesLoader;
  }


  public static function render() {
    echo '<div id="automatic-translations-section"></div>';
  }


  public function scriptPrerequisitesMet(): bool {
    return $this->isOnMainSettingsTab();
  }


  public function requirementsMet(): bool {
    return $this->isOnMainSettingsTab();
  }


  public function jsWindowKey(): string {
    return 'wpmlScriptData';
  }


  public function initialScriptData(): array {
    $atePreferencesData      = $this->atePreferencesLoader->get();
    $languagePreferencesData = $this->languagePreferencesLoader->get();
    $otherData               = [];

    return array_merge(
      $atePreferencesData,
      $languagePreferencesData,
      $otherData
    );
  }


  private function isOnMainSettingsTab(): bool {
    return ! array_key_exists( 'sm', $_GET ) || $_GET['sm'] === 'mcsetup';
  }


}
