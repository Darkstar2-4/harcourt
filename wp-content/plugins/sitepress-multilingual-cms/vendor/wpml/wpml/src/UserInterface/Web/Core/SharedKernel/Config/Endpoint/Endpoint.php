<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint;

class Endpoint {
  const NAMESPACE = 'wpml';

  private $id;

  private $path;

  private $method;

  private $handler;

  private $capability;

  private $version = 1;

  private $isAjax;


  public function __construct( string $id, string $path, bool $isAjax = false ) {
    $this->id         = $id;
    $this->path       = $path;
    $this->method     = MethodType::GET;
    $this->isAjax     = $isAjax;
  }


  public function id(): string {
    return $this->id;
  }


  public function path(): string {
    return $this->path;
  }


  public function isAjax(): bool {
    return $this->isAjax;
  }


  public function namespaceWithVersion(): string {
    return self::NAMESPACE . '/v' . $this->version();
  }


  public function route(): string {
    return $this->namespaceWithVersion() . $this->path();
  }


  public function method() {
    return $this->method;
  }


  public function setMethod( $method ) {
    $this->method = $method;

    return $this;
  }


  public function handler() {
    return $this->handler;
  }


  public function setHandler( $handler ) {
    $this->handler = $handler;

    return $this;
  }


  public function capability(): string {
    return $this->capability ?? WPML_CAP_MANAGE_TRANSLATIONS;
  }


  public function setCapability( string $capability ) {
    $this->capability = $capability;

    return $this;
  }


  public function version(): int {
    return $this->version;
  }


  public function setVersion( int $version ) {
    $this->version = $version;

    return $this;
  }


}
