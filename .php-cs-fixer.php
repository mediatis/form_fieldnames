<?php

use PhpCsFixer\Finder;
use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->setUsingCache(false);
$config->setRules([
    '@PSR12' => true,
    '@Symfony' => true,

    // modified rules
    'binary_operator_spaces' => ['operators' => ['=>' => null]],
    'cast_spaces' => ['space' => 'none'],
    'class_definition' => ['single_item_single_line' => true],
    'concat_space' => ['spacing' => 'one'],
    'global_namespace_import' => ['import_classes' => true, 'import_constants' => true, 'import_functions' => true],

    // disabled rules
    'no_superfluous_phpdoc_tags' => false, // conflicts with phpstan
    'nullable_type_declaration_for_default_null_value' => false,
    'phpdoc_align' => false,
    'phpdoc_summary' => false,
    'phpdoc_to_comment' => false, // conflicts with phpstan
    'yoda_style' => false,

    // added rules
    'no_useless_else' => true,
]);

$finder = $config->getFinder();
if ($finder instanceof Finder) {
    foreach (['Classes', 'Configuration', 'Tests'] as $directory) {
        if (is_dir($directory)) {
            $finder->in($directory);
        }
    }
}

return $config;
