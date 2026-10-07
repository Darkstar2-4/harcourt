<?php

namespace WPML\Core\Component\Translation\Domain\TranslationMethod;

use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationMethod\TargetLanguageMethodType;

class TranslationServiceMethod implements TranslationMethodInterface {

  private $serviceId;


  public function __construct( int $serviceId ) {
    $this->serviceId = $serviceId;
  }


  public function get() {
    return TargetLanguageMethodType::TRANSLATION_SERVICE;
  }


  public function getServiceId(): int {
    return $this->serviceId;
  }


}
