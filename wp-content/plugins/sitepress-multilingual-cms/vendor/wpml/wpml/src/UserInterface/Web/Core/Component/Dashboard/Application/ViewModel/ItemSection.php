<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\ViewModel;

use WPML\Core\SharedKernel\Component\Post\Application\Query\Dto\PostTypeDto;
use WPML\PHP\ConstructableFromArrayTrait;

final class ItemSection {
  use ConstructableFromArrayTrait;

  private $id;

  private $title;

  private $singular;

  private $plural;

  private $isDisplayAsTranslated = false;

  private $kindId;

  private $kindHierarchical;

  private $kindType;

  private $defaultFilters;


  public function __construct(
    string $id,
    string $title,
    string $singular,
    string $plural,
    string $kindId,
    bool $kindHierarchical,
    string $kindType,
    array $defaultFilters = []
  ) {
    $this->id               = $id;
    $this->title            = $title;
    $this->singular         = $singular;
    $this->plural           = $plural;
    $this->kindId           = $kindId;
    $this->kindHierarchical = $kindHierarchical;
    $this->kindType         = $kindType;
    $this->defaultFilters   = $defaultFilters;
  }


  public function getId() {
    return $this->id;
  }


  public function isDisplayAsTranslated(): bool {
    return $this->isDisplayAsTranslated;
  }


  public function setIsDisplayAsTranslated( bool $isDisplayAsTranslated ) {
    $this->isDisplayAsTranslated = $isDisplayAsTranslated;
  }


  public function toArray() {
    $kind = [ 'id' => $this->kindId ];

    if ( $this->kindHierarchical !== null ) {
      $kind['hierarchical'] = $this->kindHierarchical;
    }

    if ( $this->kindType !== null ) {
      $kind['type'] = $this->kindType;
    }

    $kind['defaultFilters'] = $this->defaultFilters;

    return [
      'id'                    => $this->id,
      'title'                 => $this->title,
      'singular'              => $this->singular,
      'plural'                => $this->plural,
      'kind'                  => $kind,
      'isDisplayAsTranslated' => $this->isDisplayAsTranslated,
    ];
  }


  public static function createFromPostType( PostTypeDto $postTypeDto ) {
    $kindId = 'post';

    $result = new self(
      sprintf( '%s/%s', $kindId, $postTypeDto->getId() ),
      $postTypeDto->getTitle(),
      $postTypeDto->getSingular(),
      $postTypeDto->getPlural(),
      $kindId,
      $postTypeDto->isHierarchical(),
      $postTypeDto->getId()
    );

    $result->setIsDisplayAsTranslated( $postTypeDto->isDisplayAsTranslated() );

    return $result;
  }


}
