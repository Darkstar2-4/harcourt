<?php

namespace WPML\Legacy\Component\ATE\Application\Query;

use WPML\Core\SharedKernel\Component\ATE\Application\Query\SiteIDQueryInterface;

class SiteIDQuery implements SiteIDQueryInterface {

  private $wpmlSiteId;

  private $wpmlTmAte;


  public function __construct( \WPML_Site_ID $wpmlSiteId, \WPML_TM_ATE $wpmlTmAte ) {
    $this->wpmlSiteId = $wpmlSiteId;
    $this->wpmlTmAte  = $wpmlTmAte;
  }


  public function get() {
    $optionKey = $this->wpmlSiteId::SITE_ID_KEY;
    $scope     = $this->wpmlTmAte::SITE_ID_SCOPE;

    $result = \get_option( $optionKey . ':' . $scope, null );
    return $result;
  }


}
