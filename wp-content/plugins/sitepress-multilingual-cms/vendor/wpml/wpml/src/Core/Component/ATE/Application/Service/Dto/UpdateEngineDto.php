<?php

namespace WPML\Core\Component\ATE\Application\Service\Dto;

use WPML\Core\Component\ATE\Application\Service\Dto\UpdateEngine\FormalitySettingDto;

class UpdateEngineDto {

  private $engine;

  private $enabled;

  private $formalityAvailable;

  private $formalitySettings;


  public function __construct(
    string $engine,
    bool $enabled,
    bool $formalityAvailable,
    ?array $formalitySettings = null
  ) {
    $this->engine             = $engine;
    $this->enabled            = $enabled;
    $this->formalityAvailable = $formalityAvailable;
    $this->formalitySettings  = $formalitySettings;
  }


  public function getEngine(): string {
    return $this->engine;
  }


  public function isEnabled(): bool {
    return $this->enabled;
  }


  public function isFormalityAvailable(): bool {
    return $this->formalityAvailable;
  }


  public function getFormalitySettings() {
    return $this->formalitySettings;
  }


}
