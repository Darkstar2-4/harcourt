<?php

namespace WPML\Infrastructure\WordPress\Component\StringPackage\Application\Query;

use WPML\Core\Component\StringPackage\Application\Query\Dto\PackageDefinitionDto;
use WPML\Core\Component\StringPackage\Application\Query\PackageDefinitionQueryInterface;

class PackageDefinitionQuery implements PackageDefinitionQueryInterface {


  public function getInfoList(): array {
    $packageDefinitions = [];

    foreach ( $this->callFilter() as $slug => $info ) {
      $packageDefinitions[ $slug ] = new PackageDefinitionDto(
        $info['title'],
        $info['slug'],
        $info['plural']
      );
    }

    return $packageDefinitions;
  }


  public function isPackageOnTheList( string $packageKindSlug ): bool {
    $list = $this->getNamesList();

    $lowercaseList = array_map( 'strtolower', $list );

    return in_array( strtolower( $packageKindSlug ), $lowercaseList, true );
  }


  public function getNamesList(): array {
    return array_keys( $this->callFilter() );
  }


  private function callFilter(): array {
    return \apply_filters( 'wpml_active_string_package_kinds', [] );
  }


}
