<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\Core\SharedKernel\Component\Post\Application\Query\TranslatableTypesQueryInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;
use WPML\PHP\Value\Validate;

final class SearchCriteriaBuilder {

  private $languagesBuilder;

  private $translatableTypesQuery;


  public function __construct(
    LanguagesQueryInterface $languagesQuery,
    TranslatableTypesQueryInterface $translatableTypesQuery
  ) {
    $this->languagesBuilder       = new SourceAndTargetLanguagesBuilder( $languagesQuery );
    $this->translatableTypesQuery = $translatableTypesQuery;
  }


  public function build( array $array ): SearchCriteria {
    $type = Validate::nonEmptyString( [ $array, 'type' ] );

    $translatableTypeIds = array_map(
      function ( $postTypeDto ) {
        return $postTypeDto->getId();
      },
      $this->translatableTypesQuery->getTranslatable()
    );

    if ( ! in_array( $type, $translatableTypeIds, true ) ) {
      throw new InvalidArgumentException( 'Invalid post type.' );
    }

    return new SearchCriteria(
      $type,
      $this->buildLanguages( $array ),
      Validate::nonEmptyString( [ $array, 'title' ], null ),
      Validate::nonEmptyString( [ $array, 'publicationStatus' ], null ),
      Validate::arrayOfSameType(
        [ $array, 'translationStatuses' ],
        [ Validate::class, 'int' ],
        []
      ),
      Validate::int( [ $array, 'parentId' ], null ),
      Validate::nonEmptyString( [ $array, 'taxonomyId' ], null ),
      Validate::int( [ $array, 'termId' ], null ),
      Validate::int( [ $array, 'limit' ], 10 ),
      Validate::int( [ $array, 'offset' ], 0 ),
      Validate::array(
        [ $array, 'sorting' ],
        [
          'by' => [ Validate::class, 'nonEmptyString' ],
          'order' => [ Validate::class, 'nonEmptyString' ]
        ],
        null
      )
    );
  }


  private function buildLanguages( array $array ): SourceAndTargetLanguages {
    $targetLang = Validate::nonEmptyString( [ $array, 'targetLanguageCode' ], null );
    $languages = $this->languagesBuilder->build(
      Validate::nonEmptyString( [ $array, 'sourceLanguageCode' ], null ),
      $targetLang ? [ $targetLang ] : []
    );

    return $languages;
  }


}
