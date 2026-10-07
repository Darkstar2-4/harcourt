<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Domain;

class Script {

  private $id;

  private $src;

  private $dependencies;


  public function __construct(
        string $id,
        string $src,
        array $dependencies = []
    ) {
    $this->id = $id;
    $this->src = $src;
    $this->dependencies = $dependencies;
  }


  public function id(): string {
    return $this->id;
  }


  public function src(): string {
    return $this->src;
  }


  public function dependencies(): array {
    return $this->dependencies;
  }


  public function setDependencies( array $dependencies ) {
    $this->dependencies = $dependencies;
    return $this;
  }


}
