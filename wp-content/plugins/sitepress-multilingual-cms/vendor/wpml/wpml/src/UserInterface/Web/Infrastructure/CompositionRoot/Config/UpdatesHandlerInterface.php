<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config;

use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\Updates\Update;

interface UpdatesHandlerInterface {


  public function prepareUpdates( $allUpdates );


}
