<?php

namespace WPML\Legacy\SharedKernel\Installer\Application\Query;

use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlActivePluginsQueryInterface;

class WpmlActivePluginsQuery implements WpmlActivePluginsQueryInterface {

  private $installer;

  private $installerFactory = null;


  public function __construct() {
    $this->installer = class_exists( 'WP_Installer' ) ?
      \WP_Installer::instance() :
      null;

    if ( $this->installer && class_exists( 'OTGS_Installer_Factory' ) ) {
      $this->installerFactory = new \OTGS_Installer_Factory( $this->installer );
    }
  }


  public function getActivePlugins(): array {
    if ( ! $this->installerFactory ) {
      return [];
    }

    $plugins = $this
        ->installerFactory
        ->get_plugin_finder()
        ->getOTGSInstalledPluginsByRepository( true, true );

    $wpmlPlugins = $plugins['wpml'] ?? [];

    return array_filter(
      $wpmlPlugins,
      function ( $wpmlPlugin ) {
        return $wpmlPlugin['active'];
      }
    );
  }


}
