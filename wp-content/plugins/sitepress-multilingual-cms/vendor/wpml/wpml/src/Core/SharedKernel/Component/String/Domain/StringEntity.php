<?php

namespace WPML\Core\SharedKernel\Component\String\Domain;

class StringEntity {

  private $id;

  private $language;

  private $context;

  private $name;

  private $value;

  private $status;

  private $wordCount;


  public function __construct(
    int $id,
    string $language,
    string $context,
    string $name,
    string $value,
    int $status,
    int $wordCount
  ) {
    $this->id        = $id;
    $this->language  = $language;
    $this->context   = $context;
    $this->name      = $name;
    $this->value     = $value;
    $this->status    = $status;
    $this->wordCount = $wordCount;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getLanguage(): string {
    return $this->language;
  }


  public function getContext(): string {
    return $this->context;
  }


  public function getName(): string {
    return $this->name;
  }


  public function getValue(): string {
    return $this->value;
  }


  public function getStatus(): int {
    return $this->status;
  }


  public function getWordCount(): int {
    return $this->wordCount;
  }


}
