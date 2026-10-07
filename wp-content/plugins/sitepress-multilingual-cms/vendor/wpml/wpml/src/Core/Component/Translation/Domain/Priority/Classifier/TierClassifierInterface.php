<?php

namespace WPML\Core\Component\Translation\Domain\Priority\Classifier;

use WPML\Core\Component\Translation\Domain\Priority\JobPriority;
use WPML\Core\Component\Translation\Domain\Priority\PrioritizableItem;

interface TierClassifierInterface {


  public function canClassify( PrioritizableItem $item, ClassificationContext $context ): bool;


  public function classify( PrioritizableItem $item, ClassificationContext $context ): JobPriority;


}
