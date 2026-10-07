<?php

namespace WPML\PHP;


function array_keys_exists( array $keys, array $array ): bool {
  foreach ( $keys as $key ) {
    if ( ! array_key_exists( $key, $array ) ) {
      return false;
    }
  }

  return true;
}

function partition(array $array, callable $callback): array {
  $partitions = [[], []];

  foreach ($array as $key => $value) {
    if ($callback($value, $key)) {
      $partitions[0][$key] = $value;
    } else {
      $partitions[1][$key] = $value;
    }
  }

  return $partitions;
}

function flatten(array $array): array {
  $result = [];

  foreach ($array as $value) {
    if (is_array($value)) {
      $result = array_merge($result, flatten($value));
    } else {
      $result[] = $value;
    }
  }

  return $result;
}