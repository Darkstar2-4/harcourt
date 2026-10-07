<?php
namespace Composer\Installers;

use Composer\Package\PackageInterface;

class SilverStripeInstaller extends BaseInstaller
{
    protected $locations = array(
        'module' => '{$name}/',
        'theme'  => 'themes/{$name}/',
    );

    public function getInstallPath(PackageInterface $package, $frameworkType = '')
    {
        if (
            $package->getName() == 'silverstripe/framework'
            && preg_match('/^\d+\.\d+\.\d+/', $package->getVersion())
            && version_compare($package->getVersion(), '2.999.999') < 0
        ) {
            return $this->templatePath($this->locations['module'], array('name' => 'sapphire'));
        }

        return parent::getInstallPath($package, $frameworkType);
    }
}
