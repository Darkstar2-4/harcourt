<?php

namespace WPML\Core\Component\Translation\Application\Service\Dto;

use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class SendToTranslationDto implements ConstructableFromArrayInterface {

  private $batchName;

  private $sourceLanguageCode;

  private $targetLanguageMethods;

  private $posts = [];

  private $packages = [];

  private $strings = [];

  private $extraInformation;


  public function __construct(
    string $batchName,
    string $sourceLanguageCode,
    array $targetLanguageMethods,
    array $posts,
    array $packages,
    array $strings,
    ?SendToTranslationExtraInformationDto $extraInformation = null
  ) {
    $this->batchName             = $batchName;
    $this->sourceLanguageCode    = $sourceLanguageCode;
    $this->targetLanguageMethods = $targetLanguageMethods;
    $this->posts                 = $posts;
    $this->packages              = $packages;
    $this->strings               = $strings;
    $this->extraInformation      = $extraInformation;
  }


  public function getBatchName(): string {
    return $this->batchName;
  }


  public function getSourceLanguageCode(): string {
    return $this->sourceLanguageCode;
  }


  public function getTargetLanguageMethods(): array {
    return $this->targetLanguageMethods;
  }


  public function getExtraInformation() {
    return $this->extraInformation;
  }


  public function getPosts(): array {
    return $this->posts;
  }


  public function getPackages(): array {
    return $this->packages;
  }


  public function getStrings(): array {
    return $this->strings;
  }




  public static function fromArray( $array ): SendToTranslationDto {
    if ( ! isset( $array['targetLanguageMethods'] ) ) {
      throw new InvalidArgumentException( __( 'Language pairs cannot be empty', 'wpml' ) );
    }

    $targetLanguageMethods = [];
    foreach ( $array['targetLanguageMethods'] as $targetLanguageMethod ) {
      $targetLanguageMethods[] = TargetLanguageMethodDto::fromArray( $targetLanguageMethod );
    }

    if ( ! isset( $array['batchName'] ) ) {
      throw new InvalidArgumentException( __( 'Batch name cannot be empty', 'wpml' ) );
    }

    if ( ! isset( $array['sourceLanguageCode'] ) ) {
      throw new InvalidArgumentException( __( 'Source language code cannot be empty', 'wpml' ) );
    }

    if ( ! isset( $array['posts'] )
         && ! isset( $array['stringPackages'] )
         && ! isset( $array['strings'] ) ) {
      throw new InvalidArgumentException(
        __(
          'Posts, packages and strings cannot be empty.
          At least one of those elements must be specified.',
          'wpml'
        )
      );
    }

    $extraInformation = $array['extraInformation'] ?? [];
    $posts             = self::validateElementIds( $array['posts'] ?? [] );
    $stringPackages    = self::validateElementIds( $array['stringPackages'] ?? [] );
    $strings           = self::validateElementIds( $array['strings'] ?? [] );

    return new self(
      $array['batchName'],
      $array['sourceLanguageCode'],
      $targetLanguageMethods,
      $posts,
      $stringPackages,
      $strings,
      SendToTranslationExtraInformationDto::fromArray( $extraInformation )
    );
  }


  private static function validateElementIds( $elementIds ): array {
    if ( ! is_array( $elementIds ) ) {
      throw new InvalidArgumentException( __( 'Element IDs must be positive integers.', 'wpml' ) );
    }

    foreach ( $elementIds as $elementId ) {
      if ( ! is_int( $elementId ) || $elementId <= 0 ) {
        throw new InvalidArgumentException( __( 'Element IDs must be positive integers.', 'wpml' ) );
      }
    }

    return $elementIds;
  }



}
