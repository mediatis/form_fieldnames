<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

(function () {
    // Add module configuration
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
        'module.tx_form {
    settings {
        yamlConfigurations {
            1522324493 = EXT:form_fieldnames/Configuration/Yaml/FormSetup.yaml
        }
    }
}'
    );
})();
