<?php

namespace WPML\PHP\Logger;


function error( string $message ) {
  DebugFileLogger::getInstance()->error( $message );
}


function notice( string $message ) {
  DebugFileLogger::getInstance()->notice( $message );
}
