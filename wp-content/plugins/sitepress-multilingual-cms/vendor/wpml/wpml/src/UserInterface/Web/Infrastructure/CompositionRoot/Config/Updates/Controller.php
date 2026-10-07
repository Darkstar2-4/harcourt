<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\Updates;

use WPML\Core\Port\PluginInterface;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ApiInterface;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\UpdatesHandlerInterface;

class Controller implements UpdatesHandlerInterface {

  private $api;

  private $repository;

  private $scriptLoader;

  private $updateHandler;

  private $plugin;


  public function __construct(
    ApiInterface $api,
    Repository $repository,
    ScriptLoader $scriptLoader,
    UpdateHandler $updateHandler,
    PluginInterface $plugin
  ) {
    $this->api = $api;
    $this->repository = $repository;
    $this->scriptLoader = $scriptLoader;
    $this->updateHandler = $updateHandler;
    $this->plugin = $plugin;
  }


  public function prepareUpdates( $allUpdates ) {
    if ( ! $this->plugin->isSetupComplete() ) {
      return;
    }

    if ( $this->api->isRestRequest() ) {
      $this->onRest( $allUpdates );
      return;
    }

    $this->initUpdates( $allUpdates );
  }


  private function onRest( $allUpdates ) {
    $updatesToPerform = $this->repository->getUpdatesToPerform( $allUpdates );
    $this->updateHandler->registerRoute( $updatesToPerform );
  }


  private function initUpdates( $allUpdates ) {
    $updatesToPerform = $this->repository->getUpdatesToPerform( $allUpdates );

    $lazyLoadedUpdates = [];

    foreach ( $updatesToPerform as $update ) {
      if ( $update->lazyLoad() ) {
        $lazyLoadedUpdates[ $update->id() ] = $update;
        continue;
      }

      $this->updateHandler->doUpdate( $update );
    }

    if ( $lazyLoadedUpdates ) {
      $this->scriptLoader->loadScript(
        array_keys( $updatesToPerform ),
        $this->updateHandler->endpoint()
      );
    }
  }


}
