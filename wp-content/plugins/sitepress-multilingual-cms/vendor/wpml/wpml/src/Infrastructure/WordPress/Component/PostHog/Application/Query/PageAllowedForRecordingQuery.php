<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Application\Query;

use WPML\Core\Component\PostHog\Application\Query\PageAllowedForRecordingQueryInterface;
use WPML\Core\SharedKernel\Component\PostHog\Application\Hook\FilterAllowedPagesInterface;

class PageAllowedForRecordingQuery implements PageAllowedForRecordingQueryInterface {

  const ALLOWED_PAGES = [
    'sitepress-multilingual-cms/menu/setup.php',
    'tm/menu/main.php',
    'sitepress-multilingual-cms/menu/languages.php',
    'sitepress-multilingual-cms/menu/theme-localization.php',
    'tm/menu/translations-queue.php',
    'tm/menu/settings',
    'sitepress-multilingual-cms/menu/menu-sync/menus-sync.php',
    'wpml-string-translation/menu/string-translation.php',
    'sitepress-multilingual-cms/menu/taxonomy-translation.php',
    'sitepress-multilingual-cms/menu/troubleshooting.php',
    'sitepress-multilingual-cms/menu/support.php',
    'wpml-media',
    'wpml-package-management',
    'sitepress-multilingual-cms/menu/debug-information.php',
    'wpml-tm-ate-log',
    'otgs-installer-support',
  ];

  private $filterAllowedPages;


  public function __construct( FilterAllowedPagesInterface $filterAllowedPages ) {
    $this->filterAllowedPages = $filterAllowedPages;
  }


  public function isAllowed(): bool {
    return $this->isWPMLAdminPage() || $this->isPluginsCommercialPage();
  }


  private function isWPMLAdminPage(): bool {
    return array_key_exists( 'page', $_GET ) &&
           in_array( $_GET['page'], $this->filterAllowedPages->filter( self::ALLOWED_PAGES ) );
  }


  private function isPluginsCommercialPage(): bool {
    return is_admin() &&
           isset( $GLOBALS['pagenow'] ) &&
           $GLOBALS['pagenow'] === 'plugin-install.php' &&
           array_key_exists( 'tab', $_GET ) &&
           $_GET['tab'] === 'commercial';
  }


}
