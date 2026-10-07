<?php

namespace WPML\Legacy\Component\Translation\Sender\ErrorMapper;

class TranslationServiceUnavailable implements StrategyInterface {


  public function map( array $errors ) {
    $pattern = '/does not accept new translation jobs at this moment/i';

    foreach ( $errors as $error ) {
      if ( preg_match( $pattern, $error['text'] ?? '' ) ) {
        $serviceNamePattern = '/\(\d+\)\s+([^<]+?)\s+does not accept/i';
        if ( preg_match(
          $serviceNamePattern,
          $error['text'] ?? '',
          $matches
        )
        ) {
          $serviceName = trim( $matches[1] );

          return sprintf(
            __(
              'The translation service "%s" is not accepting new translation jobs at this moment. '
              .
              'Please contact the service support for more information and assistance.',
              'wpml'
            ),
            $serviceName
          );
        }

        return __(
          'The translation service is not accepting new translation jobs at this moment. '
          . 'Please contact the service support for more information and assistance.',
          'wpml'
        );
      }
    }

    return null;
  }


}
