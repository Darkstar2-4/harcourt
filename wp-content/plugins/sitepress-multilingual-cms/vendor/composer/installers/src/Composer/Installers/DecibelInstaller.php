<?php
namespace Composer\Installers;

class DecibelInstaller extends BaseInstaller
{
    protected $locations = array(
        'app'    => 'app/{$name}/',
    );
}
