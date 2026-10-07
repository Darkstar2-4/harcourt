<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\PostHog;

use WPML\Core\Component\PostHog\Application\Repository\PostHogCacheStateRepositoryInterface;
use WPML\Core\Port\PluginInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ApiInterface;

class Controller implements ScriptPrerequisitesInterface, ScriptDataProviderInterface {

    const DEFAULT_RECHECK_TTL_SECONDS = 86400;

    private $endpoint;

    private $api;

    private $plugin;

    private $cacheStateRepository;


  public function __construct(
        ApiInterface $api,
        PluginInterface $plugin,
        PostHogCacheStateRepositoryInterface $cacheStateRepository
    ) {
      $this->api              = $api;
      $this->plugin           = $plugin;
      $this->cacheStateRepository = $cacheStateRepository;
  }


  public function scriptPrerequisitesMet(): bool {
      return $this->shouldMakeExternalRequest();
  }


  public function jsWindowKey(): string {
      return 'checkPostHogShouldRecord';
  }


  public function initialScriptData(): array {
      return [
          'route' => $this->api->getFullUrl( $this->getEndpoint() ),
          'nonce' => $this->api->nonce(),
      ];
  }


  private function shouldMakeExternalRequest(): bool {
      return $this->plugin->isSetupComplete() &&
             $this->cacheStateRepository->isStale( $this->getRecheckTtlSeconds() );
  }


  private function getRecheckTtlSeconds(): int {
    try {
        return defined( 'WPML_POSTHOG_RECHECK_TTL_SECONDS' )
            ? (int) constant( 'WPML_POSTHOG_RECHECK_TTL_SECONDS' )
            : self::DEFAULT_RECHECK_TTL_SECONDS;
    } catch ( \Error $e ) {
        return self::DEFAULT_RECHECK_TTL_SECONDS;
    }
  }


  private function getEndpoint(): Endpoint {
    if ( $this->endpoint === null ) {
        $this->endpoint = new Endpoint( EndpointDataProvider::ID, EndpointDataProvider::PATH );
        $this->endpoint->setMethod( EndpointDataProvider::METHOD );
    }

      return $this->endpoint;
  }


}
