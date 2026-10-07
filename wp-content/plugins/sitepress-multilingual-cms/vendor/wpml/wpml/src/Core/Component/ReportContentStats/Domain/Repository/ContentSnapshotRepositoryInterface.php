<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

interface ContentSnapshotRepositoryInterface {


  public function get(): ?int;


  public function save( int $count ): void;


}
