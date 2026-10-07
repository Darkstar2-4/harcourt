<?php

namespace WPML\Core\Component\ReportContentStats\Application\Service\ReportPreparer;

use WPML\Core\Component\ReportContentStats\Application\Service\EventReasonService;
use WPML\Core\Component\ReportContentStats\Domain\ContentStatsReport;
use WPML\Core\Component\ReportContentStats\Domain\Repository\PostTypesStatsRepositoryInterface;
use WPML\Core\Component\Translation\Application\Repository\SettingsRepository;
use WPML\Core\SharedKernel\Component\ATE\Application\Query\SiteIDQueryInterface;
use WPML\Core\SharedKernel\Component\ATE\Application\Query\SiteSharedKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Language\Application\Query\Dto\LanguageDto;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\Core\SharedKernel\Component\Setting\Domain\TranslationEditorSetting;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteUrlQueryInterface;

class ReportPreparerService {

  private $languagesQuery;

  private $siteKeyQuery;

  private $settingsRepository;

  private $siteUrlQuery;

  private $siteIdQuery;

  private $siteSharedKeyQuery;

  private $contentStatsRepository;

  private $eventReasonService;


  public function __construct(
    LanguagesQueryInterface $languagesQuery,
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    SettingsRepository $settingsRepository,
    SiteUrlQueryInterface $siteUrlQuery,
    SiteIDQueryInterface $siteIdQuery,
    SiteSharedKeyQueryInterface $siteSharedKeyQuery,
    PostTypesStatsRepositoryInterface $contentStatsRepository,
    EventReasonService $eventReasonService
  ) {
    $this->languagesQuery         = $languagesQuery;
    $this->siteKeyQuery           = $siteKeyQuery;
    $this->settingsRepository     = $settingsRepository;
    $this->siteUrlQuery           = $siteUrlQuery;
    $this->siteIdQuery            = $siteIdQuery;
    $this->siteSharedKeyQuery     = $siteSharedKeyQuery;
    $this->contentStatsRepository = $contentStatsRepository;
    $this->eventReasonService     = $eventReasonService;
  }


  public function prepare(): ContentStatsReport {
    $siteKey = $this->siteKeyQuery->get();

    $defaultLanguageCode = $this->prepareLanguage(
      $this->languagesQuery->getDefault()
    );

    $secondaryLanguages = array_map(
      function ( LanguageDto $language ) {
        return $this->prepareLanguage( $language );
      },
      $this->languagesQuery->getSecondary()
    );

    $currentTranslationEditor = $this->prepareCurrentTranslationEditor();

    $siteUrl       = $this->siteUrlQuery->get();
    $siteUUID      = $this->siteIdQuery->get();
    $siteSharedKey = $this->siteSharedKeyQuery->get();

    $postTypesStats = $this->preparePostTypesStats();

    $eventReason = $this->eventReasonService->getOrDetermine();

    return new ContentStatsReport(
      $siteKey,
      $siteUrl,
      $currentTranslationEditor,
      $defaultLanguageCode,
      $secondaryLanguages,
      $siteUUID,
      $siteSharedKey,
      $postTypesStats,
      $eventReason
    );

  }


  private function prepareLanguage( LanguageDto $languageData ) {
    return [
      'code'          => $languageData->getCode(),
      'defaultLocale' => $languageData->getDefaultLocale(),
      'nativeName'    => $languageData->getNativeName(),
      'englishName'   => $languageData->getEnglishName(),
      'displayName'   => $languageData->getDisplayName(),
    ];
  }


  private function prepareCurrentTranslationEditor(): string {
    $currentTranslationEditor = $this->settingsRepository
        ->getSettings()
        ->getTranslationEditor() ?: TranslationEditorSetting::createDefault();

    return $currentTranslationEditor->getValue();
  }


  private function preparePostTypesStats(): array {
    $stats = $this->contentStatsRepository->get();

    $preparedStats = [];

    foreach ( $stats as $stat ) {
      $preparedStats[ $stat->getPostTypeId() ] = [
        'postsCount'          => $stat->getPostsCount(),
        'charactersCount'     => $stat->getCharactersCount(),
        'translationCoverage' => $stat->getTranslationCoverage(),
      ];
    }

    return $preparedStats;
  }


}
