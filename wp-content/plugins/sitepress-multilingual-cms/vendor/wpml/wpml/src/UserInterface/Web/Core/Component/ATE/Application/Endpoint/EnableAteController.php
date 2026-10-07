<?php

namespace WPML\UserInterface\Web\Core\Component\ATE\Application\Endpoint;

use WPML\Core\Component\Translation\Application\Service\SettingsService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class EnableAteController implements EndpointInterface {

  private $settingsService;


  public function __construct( SettingsService $settingsService ) {
    $this->settingsService = $settingsService;
  }


  public function handle( $requestData = null ): array {
    $this->settingsService->enableATE();

    return [ 'success' => true ];
  }


}
