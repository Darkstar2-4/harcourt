<?php

namespace WPML\Legacy\Component\Translation\Sender\ErrorMapper;

class LegacyAteJobCreationError implements StrategyInterface {


  public function map( array $errors ) {
    foreach ( $errors as $error ) {
      if (
        array_key_exists( 'id', $error )
        && array_key_exists( 'type', $error )
        && array_key_exists( 'text', $error )
        && $error['type'] === 'error'
        && $error['id'] === 'wpml_tm_ate_create_job'
      ) {
        return $error['text'];
      }
    }

    return null;
  }


}
