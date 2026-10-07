<?php

namespace WPML\Core\Component\ReportContentStats\Application\Service;

use WPML\Core\Component\ReportContentStats\Application\Query\CanCollectStatsQueryInterface;
use WPML\Core\Component\ReportContentStats\Application\Query\ContentStatsTranslatableTypesQueryInterface;
use WPML\Core\Component\ReportContentStats\Domain\ContentStatsCalculator;
use WPML\Core\Component\ReportContentStats\Domain\Repository\PostTypesStatsRepositoryInterface;
use WPML\Core\Component\ReportContentStats\Domain\Repository\PostTypesToCalculateRepositoryInterface;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\Core\SharedKernel\Component\Post\Application\Query\Dto\PostTypeDto;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteMigrationLockQueryInterface;

class ContentStatsService {

  private $siteMigrationLockQuery;

  private $siteKeyQuery;

  private $canCollectStatsQuery;

  private $lastSentService;

  private $retryService;

  private $postTypesToCalculateRepository;

  private $postTypesStatsRepository;

  private $translatableTypesQuery;

  private $languagesQuery;

  private $contentStatsCalculator;


  public function __construct(
    SiteMigrationLockQueryInterface $siteMigrationLockQuery,
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    CanCollectStatsQueryInterface $canCollectStatsQuery,
    LastSentService $lastSentService,
    RetryService $retryService,
    PostTypesToCalculateRepositoryInterface $postTypesToCalculateRepository,
    PostTypesStatsRepositoryInterface $postTypesStatsRepository,
    ContentStatsTranslatableTypesQueryInterface $translatableTypesQuery,
    LanguagesQueryInterface $languagesQuery,
    ContentStatsCalculator $contentStatsCalculator
  ) {
    $this->siteMigrationLockQuery         = $siteMigrationLockQuery;
    $this->siteKeyQuery                   = $siteKeyQuery;
    $this->canCollectStatsQuery           = $canCollectStatsQuery;
    $this->lastSentService                = $lastSentService;
    $this->retryService                   = $retryService;
    $this->postTypesToCalculateRepository = $postTypesToCalculateRepository;
    $this->postTypesStatsRepository       = $postTypesStatsRepository;
    $this->translatableTypesQuery         = $translatableTypesQuery;
    $this->languagesQuery                 = $languagesQuery;
    $this->contentStatsCalculator         = $contentStatsCalculator;
  }


  public function processPostTypes() {
    if ( ! $this->canProcess() ) {
      throw new ContentStatsServiceException(
        'Stats collection is disabled'
      );
    }

    if ( $this->siteMigrationLockQuery->isLocked() ) {
      throw new SiteLockedException(
        'Site is locked for migration, 
        stats collection will start when unlocked.'
      );
    }

    if ( ! $this->siteKeyQuery->get() ) {
      throw new MissingSiteKeyException(
        'Site key is missing'
      );
    }

    $postTypesToCalculate = $this->getOrInitPostTypesToCalculate();
    $defaultLanguageCode  = $this->languagesQuery->getDefaultCode();

    if ( empty( $postTypesToCalculate ) ) {
      return false;
    }

    return $this->processUntilTimeout( $postTypesToCalculate, $defaultLanguageCode );
  }


  public function canProcess(): bool {
    if ( ! $this->canCollectStatsQuery->get() ) {
      return false;
    }

    if ( $this->retryService->isInRetryMode() ) {
      return $this->retryService->shouldRetry();
    }

    return $this->lastSentService->neverSentOrSent30DaysAgo();
  }


  private function processUntilTimeout(
    array $postTypesToCalculate,
    string $defaultLanguageCode,
    int $timeout = 1
  ): array {
    $processingTime     = 0;
    $processedPostTypes = [];

    foreach ( $postTypesToCalculate as $postType ) {
      if ( $processingTime >= $timeout ) {
        break;
      }

      $startTime = microtime( true );
      $this->processPostType( $postType, $defaultLanguageCode );
      $endTime = microtime( true );

      $processingTime += $endTime - $startTime;

      $processedPostTypes[] = $postType;
    }

    return $processedPostTypes;
  }


  private function processPostType( string $postType, string $defaultLanguageCode ) {
    $postTypeStats = $this->contentStatsCalculator->calculateForPostType(
      $defaultLanguageCode,
      $postType
    );

    if ( $postTypeStats ) {
      $this->postTypesStatsRepository->update( $postTypeStats );
    }

    $this->postTypesToCalculateRepository->removePostType( $postType );
  }


  public function resetPostTypesStatsData() {
    $this->postTypesToCalculateRepository->delete();
    $this->postTypesStatsRepository->delete();
  }


  private function getOrInitPostTypesToCalculate(): array {
    $postTypesToCalculate = $this->postTypesToCalculateRepository->get();

    if ( $postTypesToCalculate === null ) {
      $postTypesToCalculate = array_map(
        function ( PostTypeDto $postType ) {
          return $postType->getId();
        },
        $this->translatableTypesQuery->getTranslatable()
      );

      $this->postTypesToCalculateRepository->init( $postTypesToCalculate );
    }

    return $postTypesToCalculate;
  }


}
