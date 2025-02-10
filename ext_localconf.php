<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

call_user_func(function () {
    $typoscript = 'module.tx_form {
    settings {
        yamlConfigurations {
            1522324493 = EXT:form_fieldnames/Configuration/Yaml/FormSetup.yaml
        }
    }
}

plugin.tx_form {
    settings {
        yamlConfigurations {
            1522324493 = EXT:form_fieldnames/Configuration/Yaml/FormSetup.yaml
        }
    }
}';
    ExtensionManagementUtility::addTypoScriptSetup($typoscript);
});
