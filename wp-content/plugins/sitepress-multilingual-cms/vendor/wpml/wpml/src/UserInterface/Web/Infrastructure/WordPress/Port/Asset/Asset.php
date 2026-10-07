<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Port\Asset;

use WPML\UserInterface\Web\Core\Port\Asset\AssetInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\AssetInterface as ConfigAssetInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Script;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Style;

class Asset implements AssetInterface {


  public function enqueueScript( Script $script ) {

    wp_enqueue_script(
      $script->id(),
      $this->assetUrl( $script ),
      $script->dependencies(),
      WPML_VERSION,
      [
        'in_footer' => true
      ]
    );

    wp_set_script_translations(
      $script->id(),
      'wpml',
      WPML_ROOT_DIR . '/languages/'
    );

    if ( ! empty( $script->scriptData() ) && ! empty( $script->scriptVarName() ) ) {
      $scriptVarName = $script->scriptVarName() ?: '';
      wp_add_inline_script(
        $script->id(),
        'var ' . $scriptVarName . ' = ' . json_encode( $script->scriptData() ) . ';',
        'before'
      );
    }

  }


  public function enqueueStyle( Style $style ) {
    if ( $style->src() === null ) {
      return;
    }
    wp_enqueue_style(
      $style->id(),
      $this->assetUrl( $style ),
      $style->dependencies(),
      WPML_VERSION
    );

  }


  private function assetUrl( ConfigAssetInterface $asset ): string {
    $relativePath = $asset->src() ?: '';

    if ( defined( 'WPML_HMR_SERVER' ) && $asset->supportsHMR() ) {
      return WPML_HMR_SERVER . preg_replace( '#public/(js|css)/#', '', $relativePath );
    }

      return plugins_url( $relativePath, WPML_PUBLIC_DIR );
  }


}
