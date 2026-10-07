<?php

namespace WPML\Core\Component\Translation\Domain\PreviousState;

use WPML\PHP\Exception\InvalidItemIdException;

interface PreviousStateRepositoryInterface {


  public function update( int $translationId, ?PreviousState $previousState = null );


  public function resetStatus( int $translationId );


  public function restoreState( int $translationId, PreviousState $previousState );


}
