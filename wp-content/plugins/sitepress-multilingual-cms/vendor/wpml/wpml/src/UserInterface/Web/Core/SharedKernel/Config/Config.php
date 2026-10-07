<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config;

use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;

class Config {

  private $adminPages = [];

  private $adminNotices = [];

  private $endpoints = [];

  private $scripts = [];


  public function adminPages() {
    return $this->adminPages;
  }


  public function addAdminPage( Page $page ) {
    $this->adminPages[] = $page;
  }


  public function adminNotices() {
    return $this->adminNotices;
  }


  public function addAdminNotice( Notice $notice ) {
    $this->adminNotices[] = $notice;
  }


  public function endpoints() {
    return $this->endpoints;

  }


  public function addEndpoint( Endpoint $endpoint ) {
    $this->endpoints[] = $endpoint;
    return $this;
  }


  public function setEndpoints( $endpoints ) {
    $this->endpoints = $endpoints;
    return $this;
  }


  public function scripts() {
    return $this->scripts;
  }


  public function addScript( Script $script ) {
    $this->scripts[] = $script;
    return $this;
  }


}
