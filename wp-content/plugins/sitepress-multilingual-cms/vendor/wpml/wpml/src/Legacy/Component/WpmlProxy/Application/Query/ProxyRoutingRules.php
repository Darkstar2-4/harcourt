<?php

namespace WPML\Legacy\Component\WpmlProxy\Application\Query;

use WPML\Core\Component\WpmlProxy\Application\Query\ProxyRoutingRulesInterface;

class ProxyRoutingRules implements ProxyRoutingRulesInterface {

    private $loader;


  public function __construct() {
    if ( class_exists( 'WPML\ATE\Proxies\ProxyRoutingRules' ) ) {
        $this->loader = new \WPML\ATE\Proxies\ProxyRoutingRules();
    }
  }


  public function getDomains(): array {
    if ( $this->loader === null ) {
        return [ 'https://ams.wpml.org', 'https://ate.wpml.org' ];
    }

      return $this->loader->getAllowedDomains();
  }


}
