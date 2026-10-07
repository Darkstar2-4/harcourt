<?php

namespace WPML\UserInterface\Web\Core\Component\Troubleshooting\Application;

use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\Translation\Application\Repository\SettingsRepository;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlActivePluginsQueryInterface;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Setting\Domain\TranslationEditorSetting;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteUrlQueryInterface;
use WPML\Core\SharedKernel\Component\User\Application\Query\UserQueryInterface;
use WPML\TM\ATE\ClonedSites\Lock;
use WPML\TM\ATE\ClonedSites\SecondaryDomains;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\PageRequirementsInterface;

class TroubleshootingController implements
  ScriptDataProviderInterface,
  PageRequirementsInterface,
  ScriptPrerequisitesInterface {

  private $configService;

  private $posthogStateRepository;

  private $siteKeyQuery;

  private $userQuery;

  private $siteUrlQuery;

  private $wpmlActivePluginsQuery;

  private $settingsRepository;

  private $secondaryDomains;


  public function __construct(
    ConfigService $configService,
    PostHogStateRepositoryInterface $posthogStateRepository,
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    UserQueryInterface $userQuery,
    SiteUrlQueryInterface $siteUrlQuery,
    WpmlActivePluginsQueryInterface $wpmlActivePluginsQuery,
    SettingsRepository $settingsRepository,
    SecondaryDomains $secondaryDomains
  ) {
    $this->configService          = $configService;
    $this->posthogStateRepository = $posthogStateRepository;
    $this->siteKeyQuery           = $siteKeyQuery;
    $this->userQuery              = $userQuery;
    $this->siteUrlQuery           = $siteUrlQuery;
    $this->wpmlActivePluginsQuery = $wpmlActivePluginsQuery;
    $this->settingsRepository     = $settingsRepository;
    $this->secondaryDomains       = $secondaryDomains;
  }


  public static function render() {
    echo '<div id="wpml-troubleshooting-container-new"></div>';
  }


  public function jsWindowKey(): string {
    return 'troubleShootingScriptData';
  }


  public function initialScriptData(): array {
    return [
      'postHog'         => $this->getPostHogScriptData(),
      'aliasDomain' => [
        'isLocked'       => Lock::isLocked(),
        'aliasDomains'   => $this->secondaryDomains->getInfo(),
        'currentSiteUrl' => $this->siteUrlQuery->get(),
      ],
    ];
  }


  public function requirementsMet(): bool {
    return $this->isOnTroubleShootingPage();
  }


  public function scriptPrerequisitesMet(): bool {
    return $this->isOnTroubleShootingPage();
  }


  private function isOnTroubleShootingPage(): bool {
    return array_key_exists( 'page', $_GET ) &&
           $_GET['page'] === 'sitepress-multilingual-cms/menu/troubleshooting.php';
  }


  private function getPostHogScriptData(): array {
    $currentUser              = $this->userQuery->getCurrent();
    $currentTranslationEditor = $this->settingsRepository
        ->getSettings()
        ->getTranslationEditor() ?: TranslationEditorSetting::createDefault();
    $data                     = [];

    $data['postHogRecordingEnabled']  = $this->posthogStateRepository->isEnabled();
    $data['siteKey']                  = $this->siteKeyQuery->get() ?: '';
    $data['wpUserEmail']              = $currentUser ? $currentUser->getEmail() : null;
    $data['siteUrl']                  = $this->siteUrlQuery->get();
    $data['wpmlActivePlugins']        = $this->wpmlActivePluginsQuery->getActivePlugins();
    $data['currentTranslationEditor'] = $currentTranslationEditor->getValue();

    $config                                 = $this->configService->create();
    $data['postHogApiKey']                  = $config->getApiKey();
    $data['postHogHost']                    = $config->getHost();
    $data['postHogPersonProfiles']          = $config->getPersonProfiles();
    $data['postHogDisableSurveys']          = $config->getDisableSurveys();
    $data['postHogAutoCapture']             = $config->getAutoCapture();
    $data['postHogCapturePageView']         = $config->getCapturePageView();
    $data['postHogCapturePageLeave']        = $config->getCapturePageLeave();
    $data['postHogDisableSessionRecording'] = $config->getDisableSessionRecording();

    return $data;
  }


}
