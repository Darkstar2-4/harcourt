<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetWordsToTranslate;

use WPML\Core\Component\WordsToTranslate\Application\Service\WordsToTranslateService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\SharedKernel\Component\Item\Application\Service\UntranslatedService;
use WPML\PHP\Exception\InvalidArgumentException;

class GetWordsToTranslateForTypesController implements EndpointInterface {

  private $untranslatedService;

  private $wordsToTranslateService;


  public function __construct(
    UntranslatedService $untranslatedService,
    WordsToTranslateService $wordsToTranslateServices
  ) {
    $this->untranslatedService = $untranslatedService;
    $this->wordsToTranslateService = $wordsToTranslateServices;
  }


  public function handle( $requestData = null ): array {
    $start = microtime( true );

    $result = [
      'processingTime' => 0,
      'types' => [],
    ];

    if (
      ! is_array( $requestData )
      || ( ! isset( $requestData['types'] ) || ! is_array( $requestData['types'] ) )
      || ( ! isset( $requestData['langs'] ) || ! is_array( $requestData['langs'] ) )
      || ( ! isset( $requestData['numberOfItemsToFetch'] ) || ! is_numeric( $requestData['numberOfItemsToFetch'] ) )
    ) {
      throw new InvalidArgumentException( 'Invalid request data.' );
    }

    $langs = $requestData['langs'];
    $numberOfItemsToFetch = (int) $requestData['numberOfItemsToFetch'];

    foreach ( $requestData['types'] as $key => $value ) {
      if (
        ! is_array( $value )
        || ! isset( $value['itemKind'] ) || ! is_string( $value['itemKind'] )
        || ! isset( $value['offset'] ) || ! is_numeric( $value['offset'] )
      ) {
        throw new InvalidArgumentException( 'Invalid type data.' );
      }

      $kind = $value['itemKind'];
      $type = isset( $value['itemType'] ) && is_string( $value['itemType'] ) ? $value['itemType'] : '';
      $offset = (int) $value['offset'];

      $ids = $this->untranslatedService->getSomeUntranslatedIds(
        $numberOfItemsToFetch,
        $offset,
        $kind,
        $type
      );

      $resultType = [
        'kind' => $kind,
        'type' => $type,
        'wordsToTranslateItemsCounted' => 0,
        'wordsToTranslate' => [],
      ];

      $wttKind = $kind === 'package' ? 'stringPackage' : $kind;

      foreach ( $ids as $id ) {
        $resultType['wordsToTranslateItemsCounted']++;
        try {
          $item = $this->wordsToTranslateService->getForIdAndType( $id, $wttKind, $langs );
        } catch ( InvalidArgumentException $e ) {
          continue;
        }

        $item = $this->wordsToTranslateService->getForIdAndType( $id, $wttKind, $langs );
        foreach ( $langs as $lang ) {
          $resultType['wordsToTranslate'][ $lang ] =
            ( $resultType['wordsToTranslate'][ $lang ] ?? 0 ) + $item->getWordsToTranslate( $lang );
        }
      }

      $result['types'][ $key ] = $resultType;
    }

    $end = microtime( true );
    $result['processingTime'] = ( $end - $start ) * 1000;

    return $result;
  }


}
