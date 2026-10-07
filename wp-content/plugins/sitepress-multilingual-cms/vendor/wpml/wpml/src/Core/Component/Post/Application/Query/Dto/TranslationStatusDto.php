<?php

namespace WPML\Core\Component\Post\Application\Query\Dto;

use WPML\Core\SharedKernel\Component\Translation\Domain\ReviewStatus;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationEditorType;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationMethod\TargetLanguageMethodType;
use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\ConstructableFromArrayTrait;

final class TranslationStatusDto implements ConstructableFromArrayInterface {
  use ConstructableFromArrayTrait;

  private $status;

  private $reviewStatus;

  private $jobId;

  private $method;

  private $editor;

  private $isTranslated;

  private $translatorId;

  private $ateJobId;


  public function __construct(
    int $status,
    $reviewStatus = null,
    ?int $jobId = null,
    $method = null,
    $editor = TranslationEditorType::NONE,
    bool $isTranslated = false,
    ?int $translatorId = null,
    ?int $ateJobId = null
  ) {
    $this->status       = $status;
    $this->reviewStatus = $reviewStatus;
    $this->jobId        = $jobId;
    $this->method       = $method;
    $this->editor       = $editor;
    $this->isTranslated = $isTranslated;
    $this->translatorId = $translatorId;
    $this->ateJobId     = $ateJobId;
  }


  public function getStatus(): int {
    return $this->status;
  }


  public function getReviewStatus() {
    return $this->reviewStatus;
  }


  public function getJobId() {
    return $this->jobId;
  }


  public function getMethod() {
    return $this->method;
  }


  public function getEditor() {
    return $this->editor;
  }


  public function getAteJobId() {
    return $this->ateJobId;
  }


  public function toArray(): array {
    return [
      'status'       => $this->status,
      'reviewStatus' => $this->reviewStatus,
      'jobId'        => $this->jobId,
      'method'       => $this->method,
      'editor'       => $this->editor,
      'isTranslated' => $this->isTranslated,
      'translatorId' => $this->translatorId,
      'ateJobId'     => $this->ateJobId
    ];
  }


}
