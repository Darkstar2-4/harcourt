<?php

namespace WPML\Core\Component\Translation\Domain\Priority;

class Tier {

    const HOMEPAGE = 0;
    const STRINGS = 1;
    const PAGES_UNDER_HOMEPAGE = 2;
    const PAGES_NO_PARENT = 3;
    const REMAINING_PAGES = 4;
    const OTHER_CPTS = 5;
    const BLOG_POSTS = 6;

    private $value;


  public function __construct( int $value ) {
      $this->value = $value;
  }


  public function getValue(): int {
      return $this->value;
  }


  public static function homepage(): self {
      return new self( self::HOMEPAGE );
  }


  public static function strings(): self {
      return new self( self::STRINGS );
  }


  public static function pagesUnderHomepage(): self {
      return new self( self::PAGES_UNDER_HOMEPAGE );
  }


  public static function pagesNoParent(): self {
      return new self( self::PAGES_NO_PARENT );
  }


  public static function remainingPages(): self {
      return new self( self::REMAINING_PAGES );
  }


  public static function otherCpts(): self {
      return new self( self::OTHER_CPTS );
  }


  public static function blogPosts(): self {
      return new self( self::BLOG_POSTS );
  }


}
