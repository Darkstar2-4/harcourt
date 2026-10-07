<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config;

use WPML\UserInterface\Web\Core\SharedKernel\Config\AssetInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Page as DomainPage;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Script;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Style;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ApiInterface;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\PageInterface;

class AdminPage implements PageInterface {

  private $api;


  public function __construct( ApiInterface $api ) {
    $this->api = $api;
  }


  public function register( DomainPage $page, $onLoadPageHandle ) {
    $loadPage =
      function( string $hook ) use ( $page, $onLoadPageHandle ) {
        add_action(
          $hook,
          function() use ( $page, $onLoadPageHandle ) {
            $onLoadPageHandle( $page );
          }
        );
      };

    if ( $useLegacy = $page->legacyExtension() ) {
      $loadPage( $useLegacy );
      return;
    }

    if ( $wpPageId = $this->loadPage( $page ) ) {
      $loadPage( 'load-' . str_replace( '.php', '', $wpPageId ) );
    }
  }


  public function loadStyle( Style $style ) {
      wp_enqueue_style(
        $style->id(),
        $this->assetUrl( $style ),
        $style->dependencies(),
        WPML_VERSION
      );
  }


  public function registerScript( Script $script ) {
      wp_register_script(
        $script->id(),
        $this->assetUrl( $script ),
        $script->dependencies(),
        WPML_VERSION,
        [
          'in_footer' => $script->inFooter(),
        ]
      );
  }


  public function loadScript( Script $script ) {
      wp_enqueue_script(
        $script->id(),
        $this->assetUrl( $script ),
        $script->dependencies(),
        WPML_VERSION,
        [
          'in_footer' => $script->inFooter(),
        ]
      );
    wp_set_script_translations(
      $script->id(),
      'wpml',
      WPML_ROOT_DIR . '/languages/'
    );
  }


  public function provideDataForScript(
    Script $script,
    string $jsWindowKey,
    $data
  ) {
    wp_add_inline_script(
      $script->id(),
      'var ' . $jsWindowKey . ' = ' . json_encode( $data ) . ';',
      'before'
    );
  }


  private function loadPage( DomainPage $page ) {
    if ( $page->legacyExtension() ) {
      return null;
    }

    if ( $page->parentId() ) {
      return $this->loadSubPage( $page );
    }

    if ( $page->legacyParentId() ) {
      return $this->legacyLoadPage( $page );
    }

    return add_menu_page(
      $page->title(),
      $page->menuTitle(),
      $page->capability(),
      $page->id(),
      [ $page, 'render' ],
      $page->icon(),
      $page->position()
    );
  }


  private function legacyLoadPage( DomainPage $page ): string {
    if ( ! $parentId = $page->legacyParentId() ) {
      return '';
    }

    add_action(
      'wpml_admin_menu_configure',
      function( $menuId ) use ( $page, $parentId )  {
        if ( $menuId !== $parentId ) {
          return;
        }

        $menu = [
          'order'      => $page->position(),
          'page_title' => $page->title(),
          'menu_title' => $page->menuTitle(),
          'capability' => $this->api->capabilityPlusAdmin( $page->capability() ),
          'menu_slug'  => $page->id(),
          'function'   => [ $page, 'render' ],
        ];

        do_action( 'wpml_admin_menu_register_item', $menu );
      }
    );

    $firstPart = $page->position() > 1 ? $parentId : 'toplevel';
    return strtolower( $firstPart . '_page_' . $page->id() );
  }


  private function loadSubPage( DomainPage $page ): string {
    return (string) add_submenu_page(
      $page->parentId() ?: '',
      $page->title(),
      $page->menuTitle(),
      $this->api->capabilityPlusAdmin( $page->capability() ),
      $page->id(),
      [ $page, 'render' ],
      $page->position()
    );
  }


  private function assetUrl( AssetInterface $asset ): string {
    $relativePath = $asset->src() ?: '';

    if ( defined( 'WPML_HMR_SERVER' ) && $asset->supportsHMR() ) {
      return WPML_HMR_SERVER . preg_replace( '#public/(js|css)/#', '', $relativePath );
    }

      return plugins_url( $relativePath, WPML_PUBLIC_DIR );
  }


}
