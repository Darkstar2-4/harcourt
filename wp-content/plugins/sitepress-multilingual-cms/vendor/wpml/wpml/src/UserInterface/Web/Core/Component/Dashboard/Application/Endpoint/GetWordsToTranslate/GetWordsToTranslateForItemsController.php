<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetWordsToTranslate;

use WPML\Core\Component\WordsToTranslate\Application\Service\WordsToTranslateService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\InvalidArgumentException;

class GetWordsToTranslateForItemsController implements EndpointInterface {

  private $wordsToTranslateService;


  public function __construct( WordsToTranslateService $wordsToTranslateServices ) {
    $this->wordsToTranslateService = $wordsToTranslateServices;
  }


  public function handle( $requestData = null ): array {
    $start = microtime( true );

    $result = [
      'processingTime' => 0,
      'items' => [],
    ];

    if ( ! is_array( $requestData ) ) {
      throw new InvalidArgumentException( 'Request data must be an array.' );
    }

    $requestData = array_filter( $requestData, 'is_array' );

    foreach ( $requestData as $key => $value ) {
      if (
        ! isset( $value['itemId'] ) || ! is_numeric( $value['itemId'] )
        || ! isset( $value['itemKind'] ) || ! is_string( $value['itemKind'] )
        || ! isset( $value['langs'] ) || ! is_array( $value['langs'] )
        || empty( $value['langs'] )
      ) {
        throw new InvalidArgumentException( 'Invalid request data.' );
      }

      $id = (int) $value['itemId'];
      $kind = $value['itemKind'];

      $langs = $value['langs'];

      $langs = [];
      $langsFresh = [];

      $resultItem = [
        'id' => $id,
        'langs' => []
      ];

      foreach ( $value['langs'] as $lang => $settings ) {
        if ( $settings['freshTranslation'] ) {
          $langsFresh[] = $lang;
        } else {
          $langs[] = $lang;
        }
      }

      if ( ! empty( $langs ) ) {
        $item = $this->wordsToTranslateService->getForIdAndType( $id, $kind, $langs );
        foreach ( $langs as $lang ) {
          $resultItem['langs'][ $lang ] = [
            'wordsToTranslate' => $item->getWordsToTranslate( $lang ),
          ];
        }
      }

      if ( ! empty( $langsFresh ) ) {
        $item = $this->wordsToTranslateService->getForIdAndType( $id, $kind, $langsFresh, true );
        foreach ( $langsFresh as $lang ) {
          $resultItem['langs'][ $lang ] = [
            'wordsToTranslate' => $item->getWordsToTranslate( $lang ),
          ];
        }
      }

      $result['items'][ $key ] = $resultItem;
    }

    $end = microtime( true );
    $result['processingTime'] = ( $end - $start ) * 1000;

    return $result;
  }


}
