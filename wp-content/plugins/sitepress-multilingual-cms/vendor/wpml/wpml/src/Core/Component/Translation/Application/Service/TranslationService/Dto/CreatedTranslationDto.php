<?php

namespace WPML\Core\Component\Translation\Application\Service\TranslationService\Dto;

class CreatedTranslationDto {

  private $id;

  private $type;

  private $status;

  private $originalElementId;

  private $sourceLanguageCode;

  private $targetLanguageCode;

  private $translatedElementId;

  private $translationMethod;

  private $jobId;


  public function __construct(
    int $id,
    string $type,
    int $status,
    int $originalElementId,
    string $sourceLanguageCode,
    string $targetLanguageCode,
    ?string $translationMethod = null,
    ?int $translatedElementId = null,
    ?int $jobId = null
  ) {
    $this->id                  = $id;
    $this->type                = $type;
    $this->status              = $status;
    $this->originalElementId   = $originalElementId;
    $this->sourceLanguageCode  = $sourceLanguageCode;
    $this->targetLanguageCode  = $targetLanguageCode;
    $this->translatedElementId = $translatedElementId;
    $this->translationMethod   = $translationMethod;
    $this->jobId               = $jobId;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getType(): string {
    return $this->type;
  }


  public function getStatus(): int {
    return $this->status;
  }


  public function getOriginalElementId(): int {
    return $this->originalElementId;
  }


  public function getSourceLanguageCode(): string {
    return $this->sourceLanguageCode;
  }


  public function getTargetLanguageCode(): string {
    return $this->targetLanguageCode;
  }


  public function getTranslatedElementId() {
    return $this->translatedElementId;
  }


  public function getTranslationMethod() {
    return $this->translationMethod;
  }


  public function getJobId() {
    return $this->jobId;
  }


  public function toArray(): array {
    return [
      'id'                  => $this->id,
      'type'                => $this->type,
      'status'              => $this->status,
      'originalElementId'   => $this->originalElementId,
      'sourceLanguageCode'  => $this->sourceLanguageCode,
      'targetLanguageCode'  => $this->targetLanguageCode,
      'translatedElementId' => $this->translatedElementId,
      'translationMethod'   => $this->translationMethod,
      'jobId'               => $this->jobId
    ];
  }


}
