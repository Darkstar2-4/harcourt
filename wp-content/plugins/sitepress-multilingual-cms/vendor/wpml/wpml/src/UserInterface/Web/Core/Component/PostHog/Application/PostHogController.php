<?php

namespace WPML\UserInterface\Web\Core\Component\PostHog\Application;

use WPML\Core\Component\PostHog\Application\Query\PageAllowedForRecordingQueryInterface;
use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\Translation\Application\Repository\SettingsRepository;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlActivePluginsQueryInterface;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Setting\Domain\TranslationEditorSetting;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteUrlQueryInterface;
use WPML\Core\SharedKernel\Component\User\Application\Query\UserQueryInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;

class PostHogController implements
  ScriptPrerequisitesInterface,
  ScriptDataProviderInterface {

  private $configService;

  private $posthogStateRepository;

  private $siteKeyQuery;

  private $userQuery;

  private $siteUrlQuery;

  private $pageAllowedForRecordingQuery;

  private $wpmlActivePluginsQuery;

  private $settingsRepository;


  const JS_WINDOW_KEY = 'wpmlPostHog';


  public function __construct(
    ConfigService $configService,
    PostHogStateRepositoryInterface $posthogStateRepository,
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    UserQueryInterface $userQuery,
    SiteUrlQueryInterface $siteUrlQuery,
    PageAllowedForRecordingQueryInterface $pageAllowedForRecordingQuery,
    WpmlActivePluginsQueryInterface $wpmlActivePluginsQuery,
    SettingsRepository $settingsRepository
  ) {
    $this->configService                = $configService;
    $this->posthogStateRepository       = $posthogStateRepository;
    $this->siteKeyQuery                 = $siteKeyQuery;
    $this->userQuery                    = $userQuery;
    $this->siteUrlQuery                 = $siteUrlQuery;
    $this->pageAllowedForRecordingQuery = $pageAllowedForRecordingQuery;
    $this->wpmlActivePluginsQuery       = $wpmlActivePluginsQuery;
    $this->settingsRepository           = $settingsRepository;
  }


  public function jsWindowKey(): string {
    return self::JS_WINDOW_KEY;
  }


  public function initialScriptData(): array {
    $config                   = $this->configService->create();
    $currentUser              = $this->userQuery->getCurrent();
    $currentTranslationEditor = $this->settingsRepository
        ->getSettings()
        ->getTranslationEditor() ?: TranslationEditorSetting::createDefault();

    return [
      'apiKey'                   => $config->getApiKey(),
      'host'                     => $config->getHost(),
      'personProfiles'           => $config->getPersonProfiles(),
      'disableSurveys'           => $config->getDisableSurveys(),
      'autoCapture'              => $config->getAutoCapture(),
      'capturePageView'          => $config->getCapturePageView(),
      'capturePageLeave'         => $config->getCapturePageLeave(),
      'disableSessionRecording'  => $config->getDisableSessionRecording(),
      'siteKey'                  => $this->siteKeyQuery->get() ?: '',
      'wpUserEmail'              => $currentUser ? $currentUser->getEmail() : null,
      'siteUrl'                  => $this->siteUrlQuery->get(),
      'wpmlActivePlugins'        => $this->wpmlActivePluginsQuery->getActivePlugins(),
      'currentTranslationEditor' => $currentTranslationEditor->getValue(),
      'trackingMode'             => $this->posthogStateRepository->getTrackingMode(),
    ];
  }


  public function scriptPrerequisitesMet(): bool {
    return $this->postHogAllowedForThisSite() &&
           $this->pageAllowedForRecordingQuery->isAllowed();
  }


  private function postHogAllowedForThisSite(): bool {
    return $this->posthogStateRepository->isEnabled();
  }


}
