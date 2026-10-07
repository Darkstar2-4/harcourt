<?php

namespace WPML\Core\Component\Translation\Domain;

use WPML\Core\Component\Translation\Domain\TranslationEditor\EditorInterface;
use WPML\Core\Component\Translation\Domain\TranslationMethod\TranslationMethodInterface;

class Job {

  private $id;

  private $batchId;

  private $translationMethod;

  private $translatorId;

  private $editor;

  private $isCompleted;


  public function __construct(
    int $id,
    int $batchId,
    TranslationMethodInterface $translationMethod,
    EditorInterface $editor,
    bool $isCompleted,
    ?int $translatorId = null
  ) {
    $this->id                = $id;
    $this->batchId           = $batchId;
    $this->translationMethod = $translationMethod;
    $this->translatorId      = $translatorId ?: null;
    $this->editor            = $editor;
    $this->isCompleted       = $isCompleted;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getBatchId(): int {
    return $this->batchId;
  }


  public function getTranslationMethod(): TranslationMethodInterface {
    return $this->translationMethod;
  }


  public function getTranslatorId() {
    return $this->translatorId;
  }


  public function getEditor(): EditorInterface {
    return $this->editor;
  }


  public function isCompleted(): bool {
    return $this->isCompleted;
  }


}
