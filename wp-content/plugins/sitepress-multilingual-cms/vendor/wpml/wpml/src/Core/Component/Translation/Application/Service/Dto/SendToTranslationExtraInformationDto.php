<?php

namespace WPML\Core\Component\Translation\Application\Service\Dto;

use WPML\PHP\ConstructableFromArrayInterface;

class SendToTranslationExtraInformationDto implements ConstructableFromArrayInterface {

  private $deadline = null;

  private $howToHandleExistingTranslations;

  private $translationServiceExtraFields;


  public function __construct(
    string $howToHandleExistingTranslations,
    ?string $deadline = null,
    $translationServiceExtraFields = null
  ) {
    $this->deadline                        = $deadline;
    $this->howToHandleExistingTranslations = $howToHandleExistingTranslations;
    $this->translationServiceExtraFields   = $translationServiceExtraFields;
  }


  public function getDeadline() {
    return $this->deadline;
  }


  public function getHowToHandleExistingTranslations(): string {
    return $this->howToHandleExistingTranslations;
  }


  public function getTranslationServiceExtraFields() {
    return $this->translationServiceExtraFields;
  }


  public static function fromArray( $array ): SendToTranslationExtraInformationDto {
    $deadline = ! isset( $array[ 'deadline' ] )
      ? null
      : $array[ 'deadline' ];

    $howToHandleExistingTranslations = ! isset( $array[ 'howToHandleExistingTranslations' ] )
      ? ''
      : $array[ 'howToHandleExistingTranslations' ];

    $translationServiceExtraFields = ! isset( $array[ 'translationServiceExtraFields' ] )
      ? null
      : $array[ 'translationServiceExtraFields' ];

    return new self( $howToHandleExistingTranslations, $deadline, $translationServiceExtraFields );
  }


}
