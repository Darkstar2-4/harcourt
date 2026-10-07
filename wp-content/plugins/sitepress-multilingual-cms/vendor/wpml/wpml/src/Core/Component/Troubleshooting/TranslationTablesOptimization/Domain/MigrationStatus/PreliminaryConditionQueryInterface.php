<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus;

interface PreliminaryConditionQueryInterface {


  public function hasNonNullTranslationPackages(): bool;


}
