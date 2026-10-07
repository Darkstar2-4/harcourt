<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPosts;

interface PostsFilterInterface {


  public function filter( array $posts, array $searchCriteria ): array;


  public function filterViewLink( string $viewLink, int $postId, string $postType, string $languageCode ): string;


  public function filterEditLink( string $editLink, int $postId, string $postType, string $languageCode ): string;


}
