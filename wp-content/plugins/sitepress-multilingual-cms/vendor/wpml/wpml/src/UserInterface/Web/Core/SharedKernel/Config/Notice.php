<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config;

use WPML\PHP\Exception\Exception;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;

class Notice {

  private $id;

  private $controllerClassName;

  private $controller;

  private $onPages = [];

  private $onPageActive;

  private $capability;

  private $scripts = [];

  private $styles = [];

  private $endpoints = [];


  public function __construct( string $id ) {
    $this->id = $id;
  }


  public function id(): string {
    return $this->id;
  }


  public function controllerClassName() {
    return $this->controllerClassName;
  }


  public function setControllerClassName( $controllerClassName ) {
    $this->controllerClassName = $controllerClassName;
    return $this;
  }


  public function setController( $controller ) {
    $this->controller = $controller;
    return $this;
  }


  public function onPages() {
    return $this->onPages;
  }


  public function addOnPage( $page ) {
    $this->onPages[] = $page;
    return $this;
  }


  public function onPageActive() {
    return $this->onPageActive;
  }


  public function setOnPageActive( ExistingPageInterface $page ) {
    $this->onPageActive = $page;
    return $this;
  }


  public function capability(): string {
    return $this->capability ?? WPML_CAP_MANAGE_TRANSLATIONS;
  }


  public function setCapability( string $capability ) {
    $this->capability = $capability;
    return $this;
  }


  public function render() {
    if ( $this->controller instanceof NoticeRenderInterface ) {
      $this->controller->render();
      return;
    }

    echo $this->getHtmlScriptRootContainers();
  }


  public function getHtmlScriptRootContainers(): string {
    $html = '';

    foreach ( $this->scripts as $rootId => $s ) {
      $html .= '<div id="' . $rootId . '"></div>';
    }

    return $html;
  }


  public function scripts(): array {
    return $this->scripts;
  }


  public function addScript( Script $script ) {
    if ( array_key_exists( $script->id(), $this->scripts ) ) {
      throw new Exception(
        'Script with id "' . $script->id() . '" already exists.'
      );
    }

    $this->scripts[$script->id()] = $script;
    return $this;
  }


  public function styles(): array {
    return $this->styles;
  }


  public function addStyle( Style $style ) {
    if ( array_key_exists( $style->id(), $this->styles ) ) {
      throw new Exception(
        'Style with id "' . $style->id() . '" already exists.'
      );
    }

    $this->styles[$style->id()] = $style;
    return $this;
  }


  public function endpoints() {
    return $this->endpoints;

  }


  public function addEndpoint( Endpoint $endpoint ) {
    $this->endpoints[] = $endpoint;
    return $this;
  }


  public function setEndpoints( $endpoints ) {
    $this->endpoints = $endpoints;
    return $this;
  }


}
