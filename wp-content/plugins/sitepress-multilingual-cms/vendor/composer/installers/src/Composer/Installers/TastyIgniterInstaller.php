<?php

namespace Composer\Installers;

class TastyIgniterInstaller extends BaseInstaller
{
    protected $locations = array(
        'extension' => 'extensions/{$vendor}/{$name}/',
        'theme' => 'themes/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        if ($vars['type'] === 'tastyigniter-extension') {
            $vars['vendor'] = preg_replace('/[^a-z0-9_]/i', '', $vars['vendor']);
            $vars['name'] = preg_replace('/^ti-ext-/', '', $vars['name']);
        }

        if ($vars['type'] === 'tastyigniter-theme') {
            $vars['name'] = preg_replace('/^ti-theme-/', '', $vars['name']);
        }

        return $vars;
    }
}