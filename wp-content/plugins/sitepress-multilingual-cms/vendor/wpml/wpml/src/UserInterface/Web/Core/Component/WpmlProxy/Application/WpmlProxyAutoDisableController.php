<?php

namespace WPML\UserInterface\Web\Core\Component\WpmlProxy\Application;

use WPML\Core\Component\WpmlProxy\Application\Service\WpmlProxyService;
use WPML\Core\Port\PluginInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;


class WpmlProxyAutoDisableController implements ScriptDataProviderInterface,
  ScriptPrerequisitesInterface {

  private $checkUrl;

  private $proxyService;


  public function __construct(
    PluginInterface $plugin,
    WpmlProxyService $proxyService
  ) {
    $this->checkUrl     = $plugin->getAMSHost() . '/api/wpml';
    $this->proxyService = $proxyService;
  }


  public function jsWindowKey(): string {
    return 'wpmlProxyAutoDisableConfig';
  }


  public function initialScriptData(): array {
    return [
      'checkUrl' => $this->checkUrl
    ];
  }


  public function scriptPrerequisitesMet(): bool {

    return $this->proxyService->isEnabled();
  }


}
