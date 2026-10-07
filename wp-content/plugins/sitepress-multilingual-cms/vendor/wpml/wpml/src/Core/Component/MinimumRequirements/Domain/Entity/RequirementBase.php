<?php

namespace WPML\Core\Component\MinimumRequirements\Domain\Entity;

use Throwable;

abstract class RequirementBase {


  abstract public function getId();


  abstract protected function getTitle(): string;


  abstract protected function getRequirementType(): string;


  abstract public function getMessages(): array;


  public function isValid(): bool {
    $forceInvalidationValue = $this->getForceInvalidationValue();
    if ( $forceInvalidationValue !== null ) {
      return ! $forceInvalidationValue;
    }

    return $this->doIsValid();
  }


  abstract protected function doIsValid(): bool;




  public function toArray(): array {
    return [
      'id'       => $this->getId(),
      'isValid'  => $this->isValid(),
      'title'    => $this->getTitle(),
      'messages' => $this->getMessages(),
    ];
  }


  protected function isConstantTrue( string $constantName ): bool {
    try {
      return defined( $constantName ) && constant( $constantName ) === true;
    } catch ( Throwable $e ) {
      return false;
    }
  }


  protected function getForceInvalidationValue() {
    $requirementType = $this->getRequirementType();
    $constantName    = "WPML_FORCE_{$requirementType}_TO_BE_INVALID";

    try {
      if ( defined( $constantName ) ) {
        return (bool) constant( $constantName );
      }
    } catch ( Throwable $e ) {
      return null;
    }

    return null;
  }


}
