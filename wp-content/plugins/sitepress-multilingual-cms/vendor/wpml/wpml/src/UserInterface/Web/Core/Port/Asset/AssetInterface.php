<?php

namespace WPML\UserInterface\Web\Core\Port\Asset;

use WPML\UserInterface\Web\Core\SharedKernel\Config\Script;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Style;

interface AssetInterface {


  public function enqueueScript( Script $script );


  public function enqueueStyle( Style $style );


}
