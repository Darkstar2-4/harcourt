<?php

namespace WPML\Core\SharedKernel\Component\WpmlOrgClient\Domain\Api;

class ApiUrl {

  const API_URL = 'https://api.wpml.org';


  public function get(): string {
    return defined( 'OTGS_INSTALLER_WPML_API_URL' ) ?
      constant( 'OTGS_INSTALLER_WPML_API_URL' ) :
      self::API_URL;
  }


}
