<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPosts;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchCriteriaBuilder;
use WPML\Core\Component\Post\Application\Query\Dto\PostWithTranslationStatusDto;
use WPML\Core\Component\Post\Application\Query\Dto\TranslationStatusDto;
use WPML\Core\Component\Post\Application\Query\PermalinkQueryInterface;
use WPML\Core\Component\Post\Application\Query\SearchQueryInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class GetPostsController implements GetPostControllerInterface {

  private $findBySearchCriteriaQuery;

  private $permalinkQuery;

  private $filter;

  private $criteriaBuilder;


  public function __construct(
    PermalinkQueryInterface $permalinkQuery,
    SearchQueryInterface $findBySearchCriteriaQueryInterface,
    PostsFilterInterface $filter,
    SearchCriteriaBuilder $criteriaBuilder
  ) {
    $this->permalinkQuery            = $permalinkQuery;
    $this->findBySearchCriteriaQuery = $findBySearchCriteriaQueryInterface;
    $this->filter                    = $filter;
    $this->criteriaBuilder           = $criteriaBuilder;
  }


  public function handle( $requestData = null ): array {
    $requestData  = $requestData ?: [];
    $languageCode = $requestData['sourceLanguageCode'] ?? '';

    try {
      $criteria = $this->criteriaBuilder->build( $requestData );
      $items    = $this->findBySearchCriteriaQuery->get( $criteria );
    } catch ( InvalidArgumentException $e ) {
      throw new InvalidArgumentException(
        'The request data for GetPosts is not valid.' . $e->getMessage()
      );
    }

    $result = array_map(
      function ( PostWithTranslationStatusDto $post ) use ( $languageCode ) {
        $hasRestrictedStatus = in_array(
          $post->getStatus(),
          array( 'draft', 'private', 'trash' ),
          true
        );

        if ( $hasRestrictedStatus ) {
          $viewLink = '';
        } else {
          $viewLink = (string) $this->permalinkQuery->getPermalink( $post->getId() );
        }

        $translations = array_map(
          function ( TranslationStatusDto $translation ) {
            return $translation->toArray();
          },
          $post->getTranslationStatuses()
        );

        return [
          'id'                => $post->getId(),
          'title'             => $post->getTitle(),
          'status'            => $post->getStatus(),
          'createdAt'         => $post->getCreatedAt(),
          'translations'      => $translations,
          'wordCount'         => $post->getWordCount(),
          'translatorNote'    => $post->getTranslatorNote(),
          'viewLink'          => $this->filter->filterViewLink(
            $viewLink,
            $post->getId(),
            $post->getPostType(),
            $languageCode
          ),
          'editLink'          => $this->filter->filterEditLink(
            '',
            $post->getId(),
            $post->getPostType(),
            $languageCode
          ),
          'isBlocked'         => false,
          'image'             => null,
          'usingNativeEditor' => $post->getUsingNativeEditor(),
        ];
      },
      $items->getResults()
    );

    return $this->filter->filter( $result, $requestData );
  }


}
