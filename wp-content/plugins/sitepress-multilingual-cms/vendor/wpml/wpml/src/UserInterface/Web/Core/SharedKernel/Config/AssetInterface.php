<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config;

interface AssetInterface {


  public function id(): string;


  public function src();


  public function dependencies(): array;


  public function supportsHMR(): bool;


}
