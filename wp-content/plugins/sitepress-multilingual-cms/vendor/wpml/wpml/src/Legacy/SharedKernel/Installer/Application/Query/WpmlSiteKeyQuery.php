<?php

namespace WPML\Legacy\SharedKernel\Installer\Application\Query;

use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;

class WpmlSiteKeyQuery implements WpmlSiteKeyQueryInterface {

  private $installer = null;


  public function __construct() {
    if ( class_exists( 'WP_Installer' ) ) {
      $this->installer = \WP_Installer::instance();
    }
  }


  public function get() {
    if ( ! $this->installer ) {
      return false;
    }

    if ( ! $this->installer->get_repositories() ) {
      $this->installer->load_repositories_list();
    }

    if ( ! $this->installer->get_settings() ) {
      $this->installer->save_settings();
    }

    return $this->installer->get_repository_site_key( 'wpml' );
  }


}
