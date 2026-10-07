<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config;

use WPML\PHP\Exception\Exception;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;

class Page {

  private $id;

  private $controllerClassName;

  private $controller;

  private $parentId;

  private $legacyParentId;

  private $legacyExtension;

  private $title;

  private $menuTitle;

  private $capability;

  private $icon;

  private $position;

  private $scripts = [];

  private $styles = [];

  private $endpoints = [];

  private $requirementsClassName;

  private $requiresWPMLSetupToBeCompleted = true;


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
    if ( $controller instanceof PageConfigUserInterface ) {
      $controller->setPageConfig( $this );
    }
    $this->controller = $controller;
    return $this;
  }


  public function parentId() {
    return $this->parentId;
  }


  public function setParentId( string $parentId ) {
    $this->parentId = $parentId;
    return $this;
  }


  public function legacyParentId() {
    return $this->legacyParentId;
  }


  public function setLegacyParentId( string $legacyParentId ) {
    $this->legacyParentId = $legacyParentId;
    return $this;
  }


  public function legacyExtension() {
    return $this->legacyExtension;
  }


  public function setLegacyExtension( string $legacyExtension ) {
    $this->legacyExtension = $legacyExtension;
    return $this;
  }


  public function title(): string {
    return $this->title ?? '';
  }


  public function setTitle( string $title ) {
    $this->title = $title;
    return $this;
  }


  public function menuTitle(): string {
    return $this->menuTitle ?? $this->title ?? '';
  }


  public function setMenuTitle( string $menuTitle ) {
    $this->menuTitle = $menuTitle;
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
    if ( $this->controller instanceof PageRenderInterface ) {
      $this->controller->render();
      return;
    }

    echo '<div class="wrap">';
    echo $this->title ? '<h1>'.$this->title.'</h1>' : '';
    echo $this->getHtmlScriptRootContainers();
    echo '</div>';
  }


  public function getHtmlScriptRootContainers(): string {
    $html = '';
    $this->scripts = array_filter(
      $this->scripts,
      function ( $script ) {
        return $script->id() !== 'wpml-notice-glossary';
      }
    );

    foreach ( $this->scripts as $rootId => $s ) {
      $html .= '<div id="' . $rootId . '"></div>';
    }

    return $html;
  }


  public function icon() {
    return $this->icon ?? '';
  }


  public function setIcon( string $icon ) {
    $this->icon = $icon;
    return $this;
  }


  public function position() {
    return $this->position;
  }


  public function setPosition( int $position ) {
    $this->position = $position;
    return $this;
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


  public function requiresWPMLSetupToBeCompleted(): bool {
    return $this->requiresWPMLSetupToBeCompleted;
  }




  public function setRequiresWPMLSetupToBeCompleted( bool $value ) {
    $this->requiresWPMLSetupToBeCompleted = $value;
  }


  public function requirementsClassName() {
    return $this->requirementsClassName;
  }


  public function setRequirementsClassName( $requirementsClassName ) {
    $this->requirementsClassName = $requirementsClassName;
    return $this;
  }


}
