<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PostRector\Rector\NameImportingPostRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\CodeQuality\General\ConvertImplicitVariablesToExplicitGlobalsRector;
use Ssch\TYPO3Rector\CodeQuality\General\ExtEmConfRector;
use Ssch\TYPO3Rector\Configuration\Typo3Option;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return static function (RectorConfig $rectorConfig): void {
    // check the whole extension package, not just Classes and Tests
    $rectorConfig->paths([
        __DIR__,
    ]);

    $rectorConfig->importNames(true, true);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_82,
        SetList::CODING_STYLE,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        Typo3LevelSetList::UP_TO_TYPO3_12,
        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,
    ]);

    $rectorConfig->skip([
        // general / php
        RemoveNonExistingVarAnnotationRector::class, // conflicts with phpstan
        RemoveUselessReturnTagRector::class, // conflicts with phpstan
        CatchExceptionNameMatchingTypeRector::class,
        SafeDeclareStrictTypesRector::class, // don't enforce strict_types on existing codebases

        // typo3 – directories that must not be processed
        // @see https://github.com/sabbelasichon/typo3-rector/issues/2536
        __DIR__ . '/**/Configuration/ExtensionBuilder/*',
        // node_modules / build artifacts would produce false positives
        __DIR__ . '/**/Resources/**/node_modules/*',
        __DIR__ . '/**/Resources/**/NodeModules/*',
        __DIR__ . '/**/Resources/**/BowerComponents/*',
        __DIR__ . '/**/Resources/**/bower_components/*',
        __DIR__ . '/**/Resources/**/build/*',
        __DIR__ . '/**/node_modules/*',
        __DIR__ . '/vendor/*',
        __DIR__ . '/Build/*',
        __DIR__ . '/public/*',
        __DIR__ . '/.github/*',
        __DIR__ . '/.Build/*',
        __DIR__ . '/var/*',
        NameImportingPostRector::class => [
            'ClassAliasMap.php',
        ],

        // TYPO3 14 only classes are referenced as strings on purpose: as ::class
        // constants they make PHPStan fail on TYPO3 12 and 13, where they do not exist.
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/Tests/Unit/Service/FormDefinitionServiceTest.php',
        ],
    ]);

    $rectorConfig->rules([
        TypedPropertyFromStrictConstructorRector::class,
        AddVoidReturnTypeWhereNoReturnRector::class,
        ConvertImplicitVariablesToExplicitGlobalsRector::class,
    ]);

    // teach phpstan (used internally by rector) about TYPO3
    $rectorConfig->phpstanConfig(Typo3Option::PHPSTAN_FOR_RECTOR_PATH);

    // import FQN class names, but not root-namespace classes like \DateTime
    $rectorConfig->importNames();
    // required so non-php file processing (e.g. typoscript) works
    $rectorConfig->disableParallel();
    $rectorConfig->importShortClasses(false);
    $rectorConfig->phpVersion(PhpVersion::PHP_82);

    $rectorConfig->ruleWithConfiguration(ExtEmConfRector::class, [
        ExtEmConfRector::ADDITIONAL_VALUES_TO_BE_REMOVED => [],
    ]);
};
