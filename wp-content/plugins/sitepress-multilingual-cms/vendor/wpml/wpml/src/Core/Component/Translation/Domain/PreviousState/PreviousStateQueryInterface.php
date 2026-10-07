<?php

namespace WPML\Core\Component\Translation\Domain\PreviousState;

interface PreviousStateQueryInterface {


  public function getByJobId( int $jobId );


  public function getByRID( int $rid );


  public function getByTranslationId( int $translationId );


}
