<?php

namespace WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs\DtoCollectionBuilder;

use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationDto;
use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationExtraInformationDto;
use WPML\Core\Component\Translation\Application\Service\Dto\TargetLanguageMethodDto;
use WPML\Core\Component\Translation\Domain\HowToHandleExistingTranslationType;
use WPML\Core\Component\Translation\Domain\Translation;

class SendToTranslationDtoBuilder {


  public function build(
    string $batchName,
    string $sourceLanguage,
    array $translations,
    array $batchIdToStringIdsMap
  ): SendToTranslationDto {
    $elementsByType  = $this->categorizeElementsByType( $translations, $batchIdToStringIdsMap );
    $targetLanguages = $this->extractTargetLanguages( $translations );

    $targetLanguageMethods = $this->buildTargetLanguageMethods( $targetLanguages );
    $extraInformation      = $this->buildExtraInformation();

    return new SendToTranslationDto(
      $batchName,
      $sourceLanguage,
      $targetLanguageMethods,
      $elementsByType['posts'],
      $elementsByType['packages'],
      $elementsByType['strings'],
      $extraInformation
    );
  }


  private function categorizeElementsByType( array $translations, array $batchIdToStringIdsMap ): array {
    $posts    = [];
    $strings  = [];
    $packages = [];

    foreach ( $translations as $translation ) {
      $type      = $translation->getType()->get();
      $elementId = $translation->getOriginalElementId();

      switch ( $type ) {
        case 'string-batch':
        case 'string':
          if ( isset( $batchIdToStringIdsMap[ $elementId ] ) ) {
            $strings = array_merge( $strings, $batchIdToStringIdsMap[ $elementId ] );
          }
          break;
        case 'package':
          $packages[] = $elementId;
          break;
        default:
          $posts[] = $elementId;
          break;
      }
    }

    return [
      'posts'    => array_values( array_unique( $posts ) ),
      'strings'  => array_values( array_unique( $strings ) ),
      'packages' => array_values( array_unique( $packages ) ),
    ];
  }


  private function extractTargetLanguages( array $translations ): array {
    $targetLanguages = [];

    foreach ( $translations as $translation ) {
      $targetLanguages[ $translation->getTargetLanguageCode() ] = true;
    }

    return array_keys( $targetLanguages );
  }


  private function buildTargetLanguageMethods( array $targetLanguages ): array {
    $targetLanguageMethods = [];

    foreach ( $targetLanguages as $targetLanguage ) {
      $targetLanguageMethods[] = TargetLanguageMethodDto::fromArray(
        [
          'targetLanguageCode' => $targetLanguage,
          'translationMethod'  => 'automatic',
          'translatorId'       => null,
        ]
      );
    }

    return $targetLanguageMethods;
  }


  private function buildExtraInformation(): SendToTranslationExtraInformationDto {
    return SendToTranslationExtraInformationDto::fromArray(
      [
        'deadline'                        => '',
        'howToHandleExistingTranslations' => HowToHandleExistingTranslationType::HANDLE_EXISTING_OVERRIDE,
      ]
    );
  }


}
