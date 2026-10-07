<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config;

use WPML\PHP\Exception\InvalidArgumentException;

class Script implements AssetInterface {
  const USED_ON_ADMIN = 'admin';
  const USED_ON_FRONT = 'front';
  const USED_ON_BOTH = 'both';

  private $id;

  private $src;

  private $dependencies = [];

  private $dataProvider;

  private $prerequisites;

  private $onlyRegister = false;

  private $usedOn = self::USED_ON_ADMIN;

  private $scriptVarName;

  private $scriptData = [];

  private $inFooter = true;

  private $supportsHMR = false;


  public function __construct( string $id ) {
    $this->id = $id;
  }


  public function id(): string {
    return $this->id;
  }


  public function src() {
    return $this->src;
  }


  public function setSrc( string $src ) {
    $this->src = $src;
    return $this;
  }


  public function dependencies(): array {
    return $this->dependencies;
  }


  public function setDependencies( $dependencies ) {
    $this->dependencies = $dependencies;
    return $this;
  }


  public function dataProvider() {
    return $this->dataProvider ?? null;
  }


  public function setDataProvider( $dataProvider ) {
    $this->dataProvider = $dataProvider;
    return $this;
  }


  public function prerequisites() {
    return $this->prerequisites ?? null;
  }


  public function setPrerequisites( $prerequisites ) {
    $this->prerequisites = $prerequisites;
    return $this;
  }


  public function onlyRegister(): bool {
    return $this->onlyRegister;
  }


  public function setOnlyRegister( bool $onlyRegister ) {
    $this->onlyRegister = $onlyRegister;
    return $this;
  }


  public function usedOnAdmin(): bool {
    return $this->usedOn === self::USED_ON_ADMIN
      || $this->usedOn === self::USED_ON_BOTH;
  }


  public function usedOnFront(): bool {
    return $this->usedOn === self::USED_ON_FRONT
      || $this->usedOn === self::USED_ON_BOTH;
  }


  public function setUsedOn( string $usedOn ) {
    $valid = [ self::USED_ON_ADMIN, self::USED_ON_FRONT, self::USED_ON_BOTH ];
    if ( ! in_array( $usedOn, $valid ) ) {
      throw new InvalidArgumentException(
        'Invalid value "'. $usedOn .'" for usedOn. ' .
        'Valid options: '. implode( ' | ', $valid ) . '.'
      );
    }
    $this->usedOn = $usedOn;
    return $this;
  }


  public function scriptVarName() {
    return $this->scriptVarName ?? $this->id;
  }


  public function setScriptVarName( string $scriptVarName ): self {
    $this->scriptVarName = $scriptVarName;
    return $this;
  }


  public function scriptData(): array {
    return $this->scriptData;
  }


  public function setScriptData( array $scriptData ): self {
    $this->scriptData = $scriptData;
    return $this;
  }


  public function inFooter(): bool {
    return $this->inFooter;
  }


  public function setInFooter( bool $inFooter ): self {
    $this->inFooter = $inFooter;

    return $this;
  }


  public function supportsHMR(): bool {
    return $this->supportsHMR;
  }


  public function setSupportsHMR( bool $supportsHMR ): self {
    $this->supportsHMR = $supportsHMR;

    return $this;
  }


}
