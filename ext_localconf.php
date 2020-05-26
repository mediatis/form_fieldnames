<?php
defined('TYPO3_MODE') or die();

call_user_func(function () {
    $typoscript = 'module.tx_form.settings.yamlConfigurations {
  1522324494 = EXT:formrelay/Configuration/Yaml/FormEditorSetup.yaml
}';
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup($typoscript);
});
