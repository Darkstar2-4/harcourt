<?php

namespace Composer\Installers;


class YawikInstaller extends BaseInstaller
{
    protected $locations = array(
        'module'  => 'module/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        $vars['name'] = strtolower(preg_replace('/(?<=\\w)([A-Z])/', '_\\1', $vars['name']));
        $vars['name'] = str_replace(array('-', '_'), ' ', $vars['name']);
        $vars['name'] = str_replace(' ', '', ucwords($vars['name']));

        return $vars;
    }
}