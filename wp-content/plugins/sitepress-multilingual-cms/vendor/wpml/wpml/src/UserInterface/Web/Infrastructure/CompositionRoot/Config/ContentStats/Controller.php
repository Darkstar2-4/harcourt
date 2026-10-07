<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ContentStats;

use WPML\Core\Component\ReportContentStats\Application\Service\ContentStatsService;
use WPML\Core\Component\ReportContentStats\Application\Service\ProcessingLockService;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteMigrationLockQueryInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ApiInterface;

class Controller implements
  ScriptPrerequisitesInterface,
  ScriptDataProviderInterface {

  private $endpoint;

  private $api;

  private $contentStatsService;

  private $siteMigrationLockQuery;

  private $siteKeyQuery;

  private $processingLockService;


  public function __construct(
    ApiInterface $api,
    ContentStatsService $contentStatsService,
    SiteMigrationLockQueryInterface $siteMigrationLockQuery,
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    ProcessingLockService $processingLockService
  ) {
    $this->api                    = $api;
    $this->contentStatsService    = $contentStatsService;
    $this->siteMigrationLockQuery = $siteMigrationLockQuery;
    $this->siteKeyQuery           = $siteKeyQuery;
    $this->processingLockService  = $processingLockService;
  }


  public function scriptPrerequisitesMet(): bool {
    return ! $this->siteMigrationLockQuery->isLocked() &&
           $this->siteKeyQuery->get() &&
           $this->contentStatsService->canProcess() &&
           ! $this->processingLockService->isLocked();
  }


  public function jsWindowKey(): string {
    return 'wpmlContentStats';
  }


  public function initialScriptData(): array {
    return [
      'route' => $this->api->getFullUrl( $this->getEndpoint() ),
      'nonce' => $this->api->nonce(),
    ];
  }


  private function getEndpoint(): Endpoint {
    if ( $this->endpoint === null ) {
      $this->endpoint = new Endpoint( EndpointDataProvider::ID, EndpointDataProvider::PATH );
      $this->endpoint->setMethod( EndpointDataProvider::METHOD );
    }

    return $this->endpoint;
  }


}
