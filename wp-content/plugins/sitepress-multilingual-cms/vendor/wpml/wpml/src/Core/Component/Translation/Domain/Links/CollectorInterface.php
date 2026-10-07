<?php

namespace WPML\Core\Component\Translation\Domain\Links;

interface CollectorInterface {


  public function getItemsLinkedInContent( string $content );


  public function addItemByIdAndType( int $id, string $type );


}
