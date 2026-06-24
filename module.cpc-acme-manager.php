<?php
/**
 * iTop module definition: CPC Certificate Manager (ACME)
 *
 * Compatible with iTop 3.1.x and 3.2.x
 */

SetupWebPage::AddModule(
    __FILE__,
    'cpc-acme-manager/1.2.0',
    [
        'label' => 'CPC Certificate Manager',
        'category' => 'business',
        'dependencies' => [
            'itop-config-mgmt/3.2.1',
            'itop-tickets/3.2.1',
        ],
        'mandatory' => false,
        'visible' => true,
        'datamodel' => [
            'vendor/autoload.php',
            'model.cpc-acme-manager.php',
            'datamodel.cpc-acme-manager.xml',
        ],
        'data.struct' => [],
        'data.sample' => [],
        'dictionaries' => [
            'en.dict.cpc-acme-manager.php',
        ],
        'doc.more_information' => '',
        'doc.manual_setup' => '',
        'settings' => [
            'config_path' => '/var/opt/cert-manager/config.json',
        ],
        'delegated_authentication' => [],
        'delegated_authentication_impact' => 'none',
        // Note: no installer class is declared here. The extension runtime
        // directories are created by the deployment script. Keeping the module
        // manifest free of an installer class avoids iTop setup errors when
        // ModuleInstallerAPI is loaded after the module is scanned.
    ]
);
