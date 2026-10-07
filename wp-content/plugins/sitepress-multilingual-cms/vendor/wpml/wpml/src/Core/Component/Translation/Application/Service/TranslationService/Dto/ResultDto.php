<?php

namespace WPML\Core\Component\Translation\Application\Service\TranslationService\Dto;

class ResultDto {

  private $createdTranslations;

  private $ignoredElements;


  public function __construct( array $createdTranslations, array $ignoredElements ) {
    $this->createdTranslations = $createdTranslations;
    $this->ignoredElements     = $ignoredElements;
  }


  public function getCreatedTranslations(): array {
    return $this->createdTranslations;
  }


  public function getIgnoredElements(): array {
    return $this->ignoredElements;
  }


  public function toArray(): array {
    return [
      'createdTranslations' => array_map(
        function ( CreatedTranslationDto $createdTranslationDto ) {
          return $createdTranslationDto->toArray();
        },
        $this->createdTranslations
      ),
      'ignoredElements'     => array_map(
        function ( IgnoredElementDto $ignoredElementDto ) {
          return $ignoredElementDto->toArray();
        },
        $this->ignoredElements
      ),
    ];
  }


}
