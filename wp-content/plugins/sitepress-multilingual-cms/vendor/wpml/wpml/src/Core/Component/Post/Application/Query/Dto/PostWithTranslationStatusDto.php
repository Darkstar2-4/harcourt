<?php

namespace WPML\Core\Component\Post\Application\Query\Dto;

use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\ConstructableFromArrayTrait;
use WPML\PHP\Exception\InvalidArgumentException;

final class PostWithTranslationStatusDto
  implements ConstructableFromArrayInterface {
  use ConstructableFromArrayTrait;

  private $id;

  private $title;

  private $status;

  private $createdAt;

  private $postType;

  private $translationStatuses;

  private $wordCount;

  private $translatorNote;

  private $usingNativeEditor;


  public function __construct(
    int $id,
    string $title,
    string $status,
    string $createdAt,
    string $postType,
    array $translationStatuses,
    ?int $wordCount = null,
    ?string $translatorNote = null,
    string $usingNativeEditor = ''
  ) {
    $translationStatuses = array_map(
      function ( $translationStatus ) {
        try {
          return is_array( $translationStatus ) ?
            TranslationStatusDto::fromArray( $translationStatus ) :
            $translationStatus;
        } catch ( InvalidArgumentException $e ) {
          return null;
        }
      },
      $translationStatuses
    );

    $translationStatuses = array_filter(
      $translationStatuses,
      function ( $translationStatus ) {
        return $translationStatus instanceof TranslationStatusDto;
      }
    );

    $this->id                  = $id;
    $this->title               = $title;
    $this->status              = $status;
    $this->createdAt           = $createdAt;
    $this->postType            = $postType;
    $this->translationStatuses = $translationStatuses;
    $this->wordCount           = $wordCount;
    $this->translatorNote      = $translatorNote;
    $this->usingNativeEditor   = $this->mapNativeEditorValue( $usingNativeEditor );
  }


  public function getId(): int {
    return $this->id;
  }


  public function getTitle(): string {
    return $this->title;
  }


  public function getStatus(): string {
    return $this->status;
  }


  public function getCreatedAt(): string {
    return $this->createdAt;
  }


  public function getPostType(): string {
    return $this->postType;
  }


  public function getTranslationStatuses(): array {
    return $this->translationStatuses;
  }


  public function getWordCount() {
    return $this->wordCount;
  }


  public function getTranslatorNote() {
    return $this->translatorNote;
  }


  public function getUsingNativeEditor() {
    return $this->usingNativeEditor;
  }


  private function mapNativeEditorValue( string $dbValue ) {
    if ( empty( $dbValue ) ) {
      return null;
    }

    return $dbValue === 'yes';
  }


}
