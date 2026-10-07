<?php
namespace Composer\Installers;

use Composer\Package\PackageInterface;

class MauticInstaller extends BaseInstaller
{
    protected $locations = array(
        'plugin'           => 'plugins/{$name}/',
        'theme'            => 'themes/{$name}/',
        'core'             => 'app/',
    );

    private function getDirectoryName()
    {
        $extra = $this->package->getExtra();
        if (!empty($extra['install-directory-name'])) {
            return $extra['install-directory-name'];
        }

        return $this->toCamelCase($this->package->getPrettyName());
    }

    private function toCamelCase($packageName)
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', basename($packageName))));
    }

    public function inflectPackageVars($vars)
    {

        if ($vars['type'] == 'mautic-plugin' || $vars['type'] == 'mautic-theme') {
            $directoryName = $this->getDirectoryName();
            $vars['name'] = $directoryName;
        }

        return $vars;
    }

}
