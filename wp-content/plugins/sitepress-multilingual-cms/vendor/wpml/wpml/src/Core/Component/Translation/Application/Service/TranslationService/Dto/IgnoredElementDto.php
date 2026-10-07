<?php

namespace WPML\Core\Component\Translation\Application\Service\TranslationService\Dto;

class IgnoredElementDto {

  private $elementId;

  private $elementType;

  private $targetLanguageCode;

  private $reason;


  public function __construct(
    int $elementId,
    string $elementType,
    string $targetLanguageCode,
    string $reason
  ) {
    $this->elementId          = $elementId;
    $this->elementType        = $elementType;
    $this->targetLanguageCode = $targetLanguageCode;
    $this->reason             = $reason;
  }


  public function getElementId(): int {
    return $this->elementId;
  }


  public function getElementType(): string {
    return $this->elementType;
  }


  public function getTargetLanguageCode(): string {
    return $this->targetLanguageCode;
  }


  public function getReason(): string {
    return $this->reason;
  }


  public function toArray(): array {
    return [
      'elementId'          => $this->elementId,
      'elementType'        => $this->elementType,
      'targetLanguageCode' => $this->targetLanguageCode,
      'reason'             => $this->reason,
    ];
  }


}
