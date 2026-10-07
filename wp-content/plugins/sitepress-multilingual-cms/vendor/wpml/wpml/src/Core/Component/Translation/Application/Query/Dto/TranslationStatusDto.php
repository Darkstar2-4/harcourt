<?php

namespace WPML\Core\Component\Translation\Application\Query\Dto;

class TranslationStatusDto {

  private $itemId;

  private $type;

  private $targetLanguage;

  private $status;

  private $reviewStatus;


  public function __construct(
    int $itemId,
    string $type,
    string $targetLanguage,
    int $status,
    ?string $reviewStatus = null
  ) {
    $this->itemId         = $itemId;
    $this->type           = $type;
    $this->targetLanguage = $targetLanguage;
    $this->status         = $status;
    $this->reviewStatus   = $reviewStatus;

  }


  public function getItemId(): int {
    return $this->itemId;
  }


  public function getType(): string {
    return $this->type;
  }


  public function getTargetLanguage(): string {
    return $this->targetLanguage;
  }


  public function getStatus(): int {
    return $this->status;
  }


  public function getReviewStatus() {
    return $this->reviewStatus;
  }


  public function toArray(): array {
    return [
      'itemId'         => $this->itemId,
      'type'           => $this->type,
      'targetLanguage' => $this->targetLanguage,
      'status'         => $this->status,
      'reviewStatus'   => $this->reviewStatus,
    ];
  }


}
