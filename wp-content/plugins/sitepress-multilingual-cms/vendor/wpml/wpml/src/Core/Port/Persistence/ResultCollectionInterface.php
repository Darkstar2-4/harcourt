<?php

namespace WPML\Core\Port\Persistence;

use Countable;
use WPML\PHP\ConstructableFromArrayInterface;

interface ResultCollectionInterface extends Countable {


  public function getSingleResult();


  public function getResults();


  public function count(): int;


  public function hydrateResultItemsAs( string $classname ): self;


  public function hydrateSingleResultAs( string $classname );


}
