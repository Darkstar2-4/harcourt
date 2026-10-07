<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

interface PostTypesToCalculateRepositoryInterface {


  public function get();


  public function init( array $postTypes );


  public function removePostType( string $postTypeName );


  public function delete();


}
