<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\PHP\Exception\InvalidArgumentException;

final class SearchPopulatedTypesCriteriaBuilder {

  private $languagesBuilder;


  public function __construct( LanguagesQueryInterface $languagesQuery ) {
    $this->languagesBuilder = new SourceAndTargetLanguagesBuilder( $languagesQuery );
  }


  public function build( array $array ): SearchPopulatedTypesCriteria {
    $languages = $this->languagesBuilder->build(
      $array['sourceLanguageCode'] ?? null,
      isset( $array['targetLanguageCode'] ) ? [ $array['targetLanguageCode'] ] : null
    );

    return new SearchPopulatedTypesCriteria(
      $languages,
      $array['itemSectionIds'] ?? [],
      $array['publicationStatus'] ?? null,
      $array['translationStatuses'] ?? []
    );
  }


}
