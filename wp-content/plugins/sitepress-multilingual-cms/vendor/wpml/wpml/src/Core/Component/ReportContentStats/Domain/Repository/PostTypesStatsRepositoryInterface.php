<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\PostTypeStats;

interface PostTypesStatsRepositoryInterface {


  public function get(): array;


  public function update( PostTypeStats $postTypeStats );


  public function delete();


}
