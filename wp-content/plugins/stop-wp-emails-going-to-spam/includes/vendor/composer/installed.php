<?php return array(
    'root' => array(
        'name' => 'fullworks/stop-wp-emails-going-to-spam',
        'pretty_version' => 'v2.2.2',
        'version' => '2.2.2.0',
        'reference' => '4d030998384cdf3c9fa95e898db295f40de4a4c5',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'alanef/free_plugin_lib' => array(
            'pretty_version' => '1.2.4',
            'version' => '1.2.4.0',
            'reference' => '13c1802b6abe03800dfc93400c92aaf0e74c4a6d',
            'type' => 'library',
            'install_path' => __DIR__ . '/../alanef/free_plugin_lib',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'alanef/wp_autoloader' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'ab82c9014dd47efbe72cb3612c2a57715bcb212d',
            'type' => 'library',
            'install_path' => __DIR__ . '/../alanef/wp_autoloader',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'composer/installers' => array(
            'pretty_version' => 'v1.0.12',
            'version' => '1.0.12.0',
            'reference' => '4127333b03e8b4c08d081958548aae5419d1a2fa',
            'type' => 'composer-installer',
            'install_path' => __DIR__ . '/./installers',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'fullworks/stop-wp-emails-going-to-spam' => array(
            'pretty_version' => 'v2.2.2',
            'version' => '2.2.2.0',
            'reference' => '4d030998384cdf3c9fa95e898db295f40de4a4c5',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'shama/baton' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
    ),
);
