<?php

namespace WPML\Legacy\Component\Translation\Domain\TranslationBatch\Validator;

use WPML\Core\Component\Translation\Domain\TranslationBatch\Element;
use WPML\Core\Component\Translation\Domain\TranslationBatch\TargetLanguage;
use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;
use WPML\Core\Component\Translation\Domain\TranslationBatch\Validator\IgnoredElement;
use WPML\Core\Component\Translation\Domain\TranslationBatch\Validator\ValidatorInterface;
use WPML\Core\Component\Translation\Domain\TranslationType;
use WPML\TM\TranslationDashboard\EncodedFieldsValidation\Validator;
use function WPML\Container\make;

class Base64Validator implements ValidatorInterface {

  const IGNORED_ELEMENT_REASON = 'base64_encoded';

  private $base64Validator;

  private $alreadyIgnoredElementIds = [
    TranslationType::POST    => [],
    TranslationType::PACKAGE => [],
  ];


  public function __construct() {
    $this->base64Validator = make( Validator::class );
  }


  public function validate( TranslationBatch $translationBatch ): array {
    $ignoredElements = [];
    $targetLanguages = [];

    list( $postIds, $packageIds ) = $this->extractPostAndPackageIds( $translationBatch );

    $invalidPostAndPackageIds = $this->base64Validator->getInvalidPostAndPackageIds(
      $postIds,
      $packageIds
    );

    if ( ! is_array( $invalidPostAndPackageIds ) || count( $invalidPostAndPackageIds ) !== 2 ) {
      return [ $translationBatch, $ignoredElements ];
    }

    list( $invalidPostIds, $invalidPackageIds ) = $invalidPostAndPackageIds;
    if ( empty( $invalidPostIds ) && empty( $invalidPackageIds ) ) {
      return [ $translationBatch, $ignoredElements ];
    }

    foreach ( $translationBatch->getTargetLanguages() as $targetLanguage ) {
      list( $postElements, $packageElements, $otherElements ) = $this->separateElements( $targetLanguage );

      $validElements = $otherElements;

      if ( empty( $invalidPostIds ) ) {
        $validElements = array_merge( $validElements, $postElements );
      } else {
        list( $ignoredPosts, $validPosts ) = $this->validateElementsOfType(
          TranslationType::POST,
          $invalidPostIds,
          $postElements,
          $targetLanguage
        );

        $ignoredElements = array_merge( $ignoredElements, $ignoredPosts );
        $validElements   = array_merge( $validElements, $validPosts );
      }

      if ( empty( $invalidPackageIds ) ) {
        $validElements = array_merge( $validElements, $packageElements );
      } else {
        list( $ignoredPackages, $validPackages ) = $this->validateElementsOfType(
          TranslationType::PACKAGE,
          $invalidPackageIds,
          $packageElements,
          $targetLanguage
        );

        $ignoredElements = array_merge( $ignoredElements, $ignoredPackages );
        $validElements   = array_merge( $validElements, $validPackages );
      }

      if ( ! empty( $validElements ) ) {
        $targetLanguages[] = new TargetLanguage(
          $targetLanguage->getLanguageCode(),
          $targetLanguage->getMethod(),
          $validElements
        );
      }
    }

    $translationBatch = $translationBatch->copyWithNewTargetLanguages( $targetLanguages );

    return [ $translationBatch, $ignoredElements ];
  }


  private function validateElementsOfType(
    string $type,
    array $invalidIds,
    array $elements,
    TargetLanguage $targetLanguage
  ): array {
    $ignoredElements = [];
    $validElements   = [];

    foreach ( $elements as $element ) {
      $elementType = $element->getType();
      $elementId   = $element->getElementId();

      $isInvalid = $elementType->get() === $type && in_array( $elementId, $invalidIds );

      if ( $isInvalid ) {
        if ( ! in_array( $elementId, $this->alreadyIgnoredElementIds[ $elementType->get() ] ) ) {
          $ignoredElements[] = new IgnoredElement(
            $elementType,
            $elementId,
            $targetLanguage->getLanguageCode(),
            $targetLanguage->getMethod(),
            self::IGNORED_ELEMENT_REASON
          );

          $this->alreadyIgnoredElementIds[ $elementType->get() ][] = $elementId;
        }
      } else {
        $validElements[] = $element;
      }
    }

    return [ $ignoredElements, $validElements ];
  }


  private function separateElements( TargetLanguage $targetLanguage ): array {
    $postElements    = [];
    $packageElements = [];
    $otherElements   = [];

    foreach ( $targetLanguage->getElements() as $element ) {
      switch ( $element->getType()->get() ) {
        case TranslationType::POST:
          $postElements[] = $element;
          break;
        case TranslationType::PACKAGE:
          $packageElements[] = $element;
          break;
        default:
          $otherElements[] = $element;
          break;
      }
    }

    return [
      $postElements,
      $packageElements,
      $otherElements
    ];
  }


  private function extractPostAndPackageIds( TranslationBatch $translationBatch ): array {
    $ids = [
      TranslationType::POST    => [],
      TranslationType::PACKAGE => []
    ];

    foreach ( $translationBatch->getTargetLanguages() as $targetLanguage ) {
      foreach ( $targetLanguage->getElements() as $element ) {
        $elementType = $element->getType()->get();
        if ( $elementType === TranslationType::POST || $elementType === TranslationType::PACKAGE ) {
          $ids[ $elementType ][] = $element->getElementId();
        }
      }
    }

    return [
      $ids[ TranslationType::POST ],
      $ids[ TranslationType::PACKAGE ]
    ];
  }


}
