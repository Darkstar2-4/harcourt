<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config;

use WPML\ConfigInterface;
use WPML\Core\Component\Communication\Application\Query\DismissedNoticesQuery;
use WPML\Core\Port\PluginInterface;
use WPML\DicInterface;
use WPML\PHP\Exception\Exception;
use WPML\UserInterface\Web\Core\Port\Script\ScriptDataProviderInterface;
use WPML\UserInterface\Web\Core\Port\Script\ScriptPrerequisitesInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;
use WPML\UserInterface\Web\Core\SharedKernel\Config\ExistingPageInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Notice;
use WPML\UserInterface\Web\Core\SharedKernel\Config\NoticeRequirementsInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Page;
use WPML\UserInterface\Web\Core\SharedKernel\Config\PageRequirementsInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Script;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Style;

class Config implements ConfigInterface {

  private $parser;

  private $dic;

  private $api;

  private $page;

  private $updatesHandler;

  private $noticesQuery;

  private $_noticesDismissed;

  private $registerNotices;

  private $plugin;


  public function __construct(
    Parser $config,
    DicInterface $dic,
    ApiInterface $api,
    PageInterface $page,
    UpdatesHandlerInterface $update,
    DismissedNoticesQuery $dismissedNoticesQuery,
    RegisterNoticesInterface $registerNotices,
    PluginInterface $plugin
  ) {
    $this->parser = $config;
    $this->dic = $dic;
    $this->api = $api;
    $this->page = $page;
    $this->updatesHandler = $update;
    $this->noticesQuery = $dismissedNoticesQuery;
    $this->registerNotices = $registerNotices;
    $this->plugin = $plugin;
  }


  public function loadRESTEndpoints() {
    $config = $this->parser->parseAllRESTEndpoints();

    foreach ( $config->endpoints() as $endpoint ) {
      new EndpointLoader( $endpoint, $this->dic, $this->api );
    }
  }


  public function loadAjaxEndpoints() {
    $config = $this->parser->parseAllAjaxEndpoints();

    foreach ( $config->endpoints() as $endpoint ) {
      new EndpointLoader( $endpoint, $this->dic, $this->api );
    }
  }


  public function getInterfaceMappings() {
    return $this->parser->parseInterfaceMappings();
  }


  public function getClassDefinitions() {
    return $this->parser->parseClassDefinitions();
  }


  public function registerAdminPages() {
    $config = $this->parser->parseAdminPages();
    foreach ( $config->adminPages() as $adminPage ) {
      if ( $this->pageRequirementsMet( $adminPage ) ) {
        $this->page->register( $adminPage, [ $this, 'onLoadPage' ] );
      } else {
        $this->parser->removeAdminPageConfig( $adminPage->id() );
      }
    }
  }


  public function loadAdminNotices() {
    $config = $this->parser->parseAdminNotices();
    foreach ( $config->adminNotices() as $adminNotice ) {
      if ( $this->noticeRequirementsMet( $adminNotice ) && ! $this->isNoticeDismissed( $adminNotice ) ) {
        $this->loadNotice( $adminNotice );
      } else {
        $this->parser->removeAdminNoticeConfig( $adminNotice->id() );
      }
    }
  }


  public function loadAdminScripts() {
    $config = $this->parser->parseScripts();
    foreach ( $config->scripts() as $script ) {
      $scriptPreRequisitesClass = $this->loadScriptPrerequisitesClass( $script );

      if (
        $scriptPreRequisitesClass &&
        ! $scriptPreRequisitesClass->scriptPrerequisitesMet()
      ) {
        continue;
      }

      if ( ! $script->usedOnAdmin() ) {
        continue;
      }

      $script->onlyRegister()
        ? $this->page->registerScript( $script )
        : $this->page->loadScript( $script );

      if ( $dataProviderClass = $script->dataProvider() ) {
        $dataProvider = $this->dic->make( $dataProviderClass );

        if ( ! $dataProvider instanceof ScriptDataProviderInterface ) {
          throw new \InvalidArgumentException(
            'Invalid data provider. It must implement ' .
            ScriptDataProviderInterface::class
          );
        }

        $this->page->provideDataForScript(
          $script,
          $dataProvider->jsWindowKey(),
          $dataProvider->initialScriptData()
        );
      }
    }
  }


  public function prepareUpdates() {
    $updates = $this->parser->parseUpdates();
    $dic = $this->dic;
    foreach ( $updates as $update ) {
      $update->setCreateHandler(
        function () use ( $update, $dic ) {
          return $dic->make( $update->handlerClassName() );
        }
      );
    }
    $this->updatesHandler->prepareUpdates( $updates );
  }


  public function loadContentStatsScripts() {
    $scripts = $this->parser->parseContentStatsScripts();
    $this->loadScripts( $scripts );
  }


  public function loadCheckPosthogShouldRecordScript() {
    $scripts = $this->parser->parseCheckPosthogShouldRecordScript();
    $this->loadScripts( $scripts );
  }


  public function onLoadPage( Page $page ) {
    $this->initPage( $page );
    $this->loadScripts( $page->scripts() );
    $this->provideEndpoints(
      $page->endpoints(),
      $this->firstScriptOrNull( $page->scripts() )
    );
    $this->loadStyles( $page->styles() );
  }


  private function initPage( Page $page ) {
    $controllerClassName = $page->controllerClassName();
    if ( ! $controllerClassName ) {
      return null;
    }

    $controller = $this->dic->make( $controllerClassName );
    $page->setController( $controller );

    return $controller;
  }


  public function loadNotice( Notice $notice ) {
    $this->initAdminNotice( $notice );

    $this->loadScripts( $notice->scripts() );
    $this->provideEndpoints(
      $notice->endpoints(),
      $this->firstScriptOrNull( $notice->scripts() )
    );
    $this->loadStyles( $notice->styles() );

    if ( $pageToRenderNotice = $notice->onPageActive() ) {
      $this->registerNotices->register( [$pageToRenderNotice, 'renderNotice'], [$notice] );
    } else {
      $this->registerNotices->register( [$notice, 'render'] );
    }
  }


  private function initAdminNotice( Notice $notice ) {
    $controllerClassName = $notice->controllerClassName();

    if ( ! $controllerClassName ) {
      return null;
    }

    $controller = $this->dic->make( $controllerClassName );
    $notice->setController( $controller );

    return $controller;
  }


  private function loadStyles( $styles ) {
    foreach ( $styles as $style ) {
      $this->page->loadStyle( $style );
    }
  }


  private function loadScriptPrerequisitesClass( Script $script ) {
    if ( $scriptPrerequisitesClass = $script->prerequisites() ) {
      $scriptPrerequisitesClass = $this->dic->make( $scriptPrerequisitesClass );
      if ( ! $scriptPrerequisitesClass instanceof ScriptPrerequisitesInterface ) {
        throw new \InvalidArgumentException(
          'Invalid script prerequisites. It must implement ' .
          ScriptPrerequisitesInterface::class
        );
      }
    }

    return $scriptPrerequisitesClass;
  }


  private function loadScripts( $scripts ) {
    foreach ( $scripts as $script ) {
      $scriptPrerequisitesClass = $this->loadScriptPrerequisitesClass( $script );

      if (
        $scriptPrerequisitesClass &&
        ! $scriptPrerequisitesClass->scriptPrerequisitesMet()
      ) {
        continue;
      }

      $this->page->loadScript( $script );

      if ( $dataProviderClass = $script->dataProvider() ) {
        $dataProvider = $this->dic->make( $dataProviderClass );

        if ( ! $dataProvider instanceof ScriptDataProviderInterface ) {
          throw new \InvalidArgumentException(
            'Invalid data provider. It must implement ' .
                    ScriptDataProviderInterface::class
          );
        }

        $this->page->provideDataForScript(
          $script,
          $dataProvider->jsWindowKey(),
          $dataProvider->initialScriptData()
        );
      }
    }
  }


  private function provideEndpoints( $endpoints, $scriptForData = null ) {
    $wpmlEndpoints = [
      'route' => [],
      'nonce' => $this->api->nonce()
    ];

    $config = $this->parser->parseGeneralEndpoints();
    $endpoints = array_merge(
      $config->endpoints(),
      $endpoints
    );

    foreach ( $endpoints as $endpoint ) {
      $endpointToArray = [];
      $endpointToArray['url'] = $this->api->getFullUrl( $endpoint );
      $wpmlEndpoints['route'][ $endpoint->id() ] = $endpointToArray;
    }

    if ( count( $wpmlEndpoints['route'] ) === 0 ) {
      return;
    }

    if ( $scriptForData === null ) {
      error_log(
        'WordPress Limitiation: There must be at least one script attached ' .
        'to the page to use provideDataForScript().'
      );

      return;
    }

    $this->page->provideDataForScript(
      $scriptForData,
      'wpmlEndpoints',
      $wpmlEndpoints
    );
  }


  private function pageRequirementsMet( Page $page ): bool {
    if ( $page->requiresWPMLSetupToBeCompleted()
         && ! $this->plugin->isSetupComplete()
    ) {
      return false;
    }

    if ( $requirementsClassName = $page->requirementsClassName() ) {
      $requirements = $this->dic->make( $requirementsClassName );

      if ( ! $requirements instanceof PageRequirementsInterface ) {
        throw new \InvalidArgumentException(
          'Invalid Page Requirements Class. It must implements ' . PageRequirementsInterface::class
        );
      }

      return $requirements->requirementsMet();
    }

    if ( $controllerClassName = $page->controllerClassName() ) {
      $controller = $this->dic->make( $controllerClassName );

      if ( $controller instanceof PageRequirementsInterface ) {
        return $controller->requirementsMet();
      }
    }

    return true;
  }


  private function noticeRequirementsMet( Notice $notice ): bool {
    if ( $controllerClassName = $notice->controllerClassName() ) {
      $controller = $this->dic->make( $controllerClassName );

      if (
        $controller instanceof NoticeRequirementsInterface
        && ! $controller->requirementsMet()
      ) {
        return false;
      }
    }

    $existingPages = $notice->onPages();
    if ( empty( $existingPages ) ) {
      return true;
    }

    foreach ( $existingPages as $existingPageClassName ) {
      $existingPage = $this->dic->make( $existingPageClassName );
      if (
        $existingPage instanceof ExistingPageInterface
        && $existingPage->isActive()
      ) {
        $notice->setOnPageActive( $existingPage );
        return true;
      }
    }

    return false;
  }


  private function firstScriptOrNull( $scripts ) {
    return count( $scripts ) > 0 ? array_values( $scripts )[0] : null;
  }


  private function isNoticeDismissed( Notice $notice ) {
    if ( $this->_noticesDismissed === null ) {
      $this->_noticesDismissed = $this->noticesQuery->getDismissed();
    }

    return in_array( $notice->id(), $this->_noticesDismissed, true );
  }


}
