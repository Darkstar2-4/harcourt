<?php

namespace WPML\Core\Port\Event;

interface DispatcherInterface {


  public function dispatch( Event $event );


}
