<?php

namespace WPML\Core\SharedKernel\Component\Setting\Domain;

class TranslationEditorSetting {

  const ATE = 'ATE';
  const CLASSIC = 'CTE';
  const MANUAL = 'MANUAL';
  const PRO = 'PRO';

  private $value;

  private $useAteForOldTranslationsCreatedWithCte = false;

  private $useNativeEditorGlobally;

  private $useNativeEditorPerPostType;


  public function __construct(
    string $value,
    $useNativeEditorGlobally = false,
    $useNativeEditorPerPostType = []
  ) {
    $this->value = in_array( $value, $this->getAll() ) ? $value : self::CLASSIC;
    $this->useNativeEditorGlobally = $useNativeEditorGlobally === true;
    $this->useNativeEditorPerPostType = (array) $useNativeEditorPerPostType;
  }


  public function getAll(): array {
    return [
      self::ATE,
      self::CLASSIC,
      self::MANUAL,
      self::PRO,
    ];
  }


  public function getValue(): string {
    return $this->value;
  }


  public function useAteForOldTranslationsCreatedWithCte(): bool {
    return $this->useAteForOldTranslationsCreatedWithCte;
  }


  public function setUseAteForOldTranslationsCreatedWithCte( bool $useAteForOldTranslationsCreatedWithCte ): self {
    $this->useAteForOldTranslationsCreatedWithCte = $useAteForOldTranslationsCreatedWithCte;

    return $this;
  }


  public static function createDefault(): self {
    return new self( self::ATE );
  }


  public function useNativeEditorForAllPostTypes() : bool {
    return $this->useNativeEditorGlobally;
  }


  public function getPostTypesUsingNativeEditor() : array {
    $validatedData = [];
    foreach ( $this->useNativeEditorPerPostType as $postType => $useNativeEditor ) {
      $validatedData[ (string) $postType ] = (bool) $useNativeEditor;
    }

    return $validatedData;
  }


}
