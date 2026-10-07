<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Hook;

use WPML\UserInterface\Web\Core\Component\Dashboard\Application\ViewModel\ItemSection;

interface DashboardItemSectionsFilterInterface {


  public function filter( array $itemSections );


  public function addNoteToSections( array $itemSections ): array;


}
