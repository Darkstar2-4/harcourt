<?php

namespace WPML\Core\Component\ATE\Application\Service\Dto;

use WPML\Core\Component\ATE\Application\Service\Dto\Engine\FormalitySettingDto;

class EngineDto {

  private $codeName;

  private $formalName;

  private $cost;

  private $enabled;

  private $formalityAvailable;

  private $formalitySettings;


  public function __construct(
    string $codeName,
    string $formalName,
    int $cost,
    bool $enabled,
    bool $formalityAvailable
  ) {
    $this->codeName           = $codeName;
    $this->formalName         = $formalName;
    $this->cost               = $cost;
    $this->enabled            = $enabled;
    $this->formalityAvailable = $formalityAvailable;
  }


  public function getCodeName(): string {
    return $this->codeName;
  }


  public function getFormalName(): string {
    return $this->formalName;
  }


  public function getCost(): int {
    return $this->cost;
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


  public function setFormalitySettings( array $formalitySettings ) {
    $this->formalitySettings = $formalitySettings;
  }


  public function toArray(): array {
    return [
      'engine'             => $this->codeName,
      'formalName'         => $this->formalName,
      'cost'               => $this->cost,
      'enabled'            => $this->enabled,
      'formalityAvailable' => $this->formalityAvailable,
      'formalitySettings'  => $this->formalitySettingsToArray(),
    ];
  }


  private function formalitySettingsToArray() {
    if ( ! $this->formalitySettings ) {
      return null;
    }

    $formalitySettingsArray = [];
    foreach ( $this->formalitySettings as $formalitySettings ) {
      $formalitySettingsArray[] = $formalitySettings->toArray();
    }

    return $formalitySettingsArray;
  }


}
