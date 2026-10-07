<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPosts;

use WPML\Core\Component\Post\Application\WordCount\ItemWordCountService;
use WPML\PHP\Exception\InvalidItemIdException;
use function WPML\PHP\Logger\notice;

class WordCountDecoratorController implements GetPostControllerInterface {

  private $innerController;

  private $itemWordCountService;


  public function __construct(
    GetPostControllerInterface $innerController,
    ItemWordCountService $itemWordCountService
  ) {
    $this->innerController      = $innerController;
    $this->itemWordCountService = $itemWordCountService;
  }


  public function handle( $requestData = null ): array {
    $posts = $this->innerController->handle( $requestData );

    return array_map(
      function ( array $post ) {
        return $this->maybeCalculateWords( $post );
      },
      $posts
    );
  }


  private function maybeCalculateWords( array $post ): array {
    if ( ! $post['wordCount'] ) {
      try {
        $wordCount         = $this->itemWordCountService->calculatePost( $post['id'], true );
        $post['wordCount'] = $wordCount;
      } catch ( InvalidItemIdException $e ) {
        notice( 'Failed to calculate word count for post ' . $post['id'] );
      }
    }

    return $post;
  }


}
