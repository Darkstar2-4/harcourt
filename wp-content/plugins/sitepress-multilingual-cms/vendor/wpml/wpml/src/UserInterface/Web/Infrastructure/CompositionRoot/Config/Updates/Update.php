<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\Updates;

use WPML\Core\Port\Update\UpdateInterface;
use WPML\PHP\Exception\RuntimeException;


class Update {

  private $id;

  private $handlerClassName;

  private $createHandler;

  private $handler;

  private $tryOnlyOnce = false;

  private $lazyLoad = false;


  public function __construct( $id, $handlerClassName ) {
    $this->id = $id;
    $this->handlerClassName = $handlerClassName;
  }


  public function id(): int {
    return $this->id;
  }


  public function handlerClassName() {
    return $this->handlerClassName;
  }


  public function setCreateHandler( $createHandler ) {
    $this->createHandler = $createHandler;
  }


  public function handler() {
    if ( ! $this->handler ) {
      if ( ! $this->createHandler ) {
        throw new RuntimeException( 'No handler factory defined for update.' );
      }
      $create = $this->createHandler;
      $this->handler = $create();
    }

    return $this->handler;
  }


  public function tryOnlyOnce(): bool {
    return $this->tryOnlyOnce;
  }


  public function setTryOnlyOnce( bool $tryOnlyOnce ) {
    $this->tryOnlyOnce = $tryOnlyOnce;
  }


  public function lazyLoad(): bool {
    return $this->lazyLoad;
  }


  public function setLazyLoad( bool $lazyLoad ) {
    $this->lazyLoad = $lazyLoad;
  }


}
