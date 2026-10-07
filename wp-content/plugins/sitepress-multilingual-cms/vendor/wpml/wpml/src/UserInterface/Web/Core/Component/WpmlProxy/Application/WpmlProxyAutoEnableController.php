<?php

namespace WPML\UserInterface\Web\Core\Component\WpmlProxy\Application;

use WPML\Core\Component\WpmlProxy\Application\Query\ProxyRoutingRulesInterface;
use WPML\Core\Component\WpmlProxy\Application\Service\WpmlProxyService;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;


class WpmlProxyAutoEnableController implements ScriptDataProviderInterface, ScriptPrerequisitesInterface {

  private $allowedDomains;

  private $proxyService;


  public function __construct(WpmlProxyService $proxyService, ProxyRoutingRulesInterface $allowedHosts
  ) {
    $this->proxyService = $proxyService;
    $this->allowedDomains = $allowedHosts->getDomains();
  }


  public function jsWindowKey(): string {
    return 'wpmlProxyAutoEnableConfig';
  }


  public function initialScriptData(): array {
    return [ 'allowedDomains' => $this->allowedDomains ];
  }


  public function scriptPrerequisitesMet(): bool {
    return ! $this->proxyService->isEnabled();
  }


}
