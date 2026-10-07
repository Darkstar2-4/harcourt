<?php

namespace WPML\Core\Component\ATE\Application\Service\Dto\Engine;

class FormalitySettingDto {

  private $languageCode;

  private $currentLevel;

  private $enabled;

  private $availableLevels;


  public function __construct(
    string $languageCode,
    FormalityLevelDto $currentLevel,
    bool $enabled = true,
    array $availableLevels = []
  ) {
    $this->languageCode    = $languageCode;
    $this->currentLevel    = $currentLevel;
    $this->enabled         = $enabled;
    $this->availableLevels = $availableLevels;
  }


  public function getLanguageCode(): string {
    return $this->languageCode;
  }


  public function getCurrentLevel(): FormalityLevelDto {
    return $this->currentLevel;
  }


  public function isEnabled(): bool {
    return $this->enabled;
  }


  public function getAvailableLevels(): array {
    return $this->availableLevels;
  }


  public function toArray(): array {
    $availableLevels = array_map(
      function ( FormalityLevelDto $level ) {
        return $level->getValue();
      },
      $this->availableLevels
    );

    return [
      'languageCode'    => $this->languageCode,
      'currentLevel'    => $this->currentLevel->getValue(),
      'enabled'         => $this->enabled,
      'availableLevels' => $availableLevels,
    ];
  }


}
