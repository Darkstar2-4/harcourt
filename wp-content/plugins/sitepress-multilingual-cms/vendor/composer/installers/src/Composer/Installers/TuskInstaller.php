<?php
    namespace Composer\Installers;
    class TuskInstaller extends BaseInstaller
    {
        protected $locations = array(
            'task'    => '.tusk/tasks/{$name}/',
            'command' => '.tusk/commands/{$name}/',
            'asset'   => 'assets/tusk/{$name}/',
        );
    }
