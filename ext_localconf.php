<?php
defined('TYPO3_MODE') or die();

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
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup($typoscript);
});
