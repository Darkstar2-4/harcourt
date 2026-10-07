<?php

namespace WPML\Core\SharedKernel\Component\String\Domain;

class StringTranslation {

  private $id;

  private $stringId;

  private $value;

  private $moString;

  private $language;


  public function __construct( int $id, int $stringId, $value, $moString, string $language ) {
    $this->id       = $id;
    $this->stringId = $stringId;
    $this->value    = $value;
    $this->moString = $moString;
    $this->language = $language;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getStringId(): int {
    return $this->stringId;
  }


  public function getValue() {
    return $this->value;
  }


  public function getMoString() {
    return $this->moString;
  }


  public function getLanguage(): string {
    return $this->language;
  }


  public function isUntranslated(): bool {
    return empty( $this->value ) && empty( $this->moString );
  }


}
