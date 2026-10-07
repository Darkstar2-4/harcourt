<?php

namespace WPML\Infrastructure\WordPress\SharedKernel\Post\Domain;

use WPML\Core\SharedKernel\Component\Post\Domain\PublicationStatusDefinitionsInterface;

class PublicationStatusDefinitions implements PublicationStatusDefinitionsInterface {
  const PUBLISH = 'publish';
  const FUTURE = 'future';
  const DRAFT = 'draft';
  const PENDING = 'pending';
  const PRIVATE = 'private';
  const TRASH = 'trash';
  const AUTO_DRAFT = 'auto-draft';
  const INHERIT = 'inherit';


  public function isPublished( string $status ): bool {
    return $status === self::PUBLISH;
  }


  public function isPublishable( string $status ): bool {
    return $status !== self::AUTO_DRAFT && $status !== self::INHERIT;
  }


  public function gotPublished( string $status, $statusBefore ): bool {
    return $status === self::PUBLISH && $statusBefore !== self::PUBLISH;
  }


}
