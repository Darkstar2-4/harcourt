<?php
namespace Composer\Installers;

class Symfony1Installer extends BaseInstaller
{
    protected $locations = array(
        'plugin'    => 'plugins/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        $vars['name'] = preg_replace_callback('/(-[a-z])/', function ($matches) {
            return strtoupper($matches[0][1]);
        }, $vars['name']);

        return $vars;
    }
}
