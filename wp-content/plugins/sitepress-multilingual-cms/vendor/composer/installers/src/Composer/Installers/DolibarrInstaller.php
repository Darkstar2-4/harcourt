<?php
namespace Composer\Installers;

class DolibarrInstaller extends BaseInstaller
{
    protected $locations = array(
        'module' => 'htdocs/custom/{$name}/',
    );
}
