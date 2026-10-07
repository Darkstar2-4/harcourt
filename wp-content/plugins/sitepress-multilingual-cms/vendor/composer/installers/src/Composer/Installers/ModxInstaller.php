<?php
namespace Composer\Installers;

class ModxInstaller extends BaseInstaller
{
    protected $locations = array(
        'extra' => 'core/packages/{$name}/'
    );
}
