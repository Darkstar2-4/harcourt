<?php
namespace Composer\Installers;

class TYPO3CmsInstaller extends BaseInstaller
{
    protected $locations = array(
        'extension'   => 'typo3conf/ext/{$name}/',
    );
}
