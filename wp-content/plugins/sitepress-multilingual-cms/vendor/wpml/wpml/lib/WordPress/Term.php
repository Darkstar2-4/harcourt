<?php

namespace WPML\WordPress;

use WPML\PHP\Boolean;

class Term {


  public static function get(
    int $id,
    string $taxonomy = '',
    string $output = 'OBJECT'
  ) {
    add_filter( 'wpml_disable_term_adjust_id', [ Boolean::class, 'true' ] );
    $item = get_term( $id, $taxonomy, $output );
    remove_filter( 'wpml_disable_term_adjust_id', [ Boolean::class, 'true' ] );

    return $item;
  }


}
