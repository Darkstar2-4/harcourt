<?php

namespace WPML\Core\Component\Translation\Domain\Links;

interface AdjustLinksInterface {


  public function adjust( Item $item, ?Item $triggerItem = null );


}
