<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Fixtures;

use Mediatis\FormFieldnames\Service\FieldNameGenerator;
use ReflectionClass;
use ReflectionNamedType;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Builds a generator with the real transliteration but without language files.
 *
 * The real CharsetConverter is used on purpose: the German letter pairs it
 * produces ("Straße" => "strasse") are part of what the naming rules promise.
 */
trait CreatesFieldNameGeneratorTrait
{
    /**
     * @param array<string,string> $translations language reference => resolved text
     */
    private function createFieldNameGenerator(array $translations = []): FieldNameGenerator
    {
        return new FieldNameGenerator($this->createCharsetConverter(), new FakeLabelResolver($translations));
    }

    private function createCharsetConverter(): CharsetConverter
    {
        // On TYPO3 12 and 13 the converter reads its transliteration tables through
        // ExtensionManagementUtility::extPath('core'), which unit tests do not set up.
        // TYPO3 14 resolves them through a CharsetProvider instead and does not care.
        $package = $this->createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn($this->getCoreExtensionPath());
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturn(true);
        $packageManager->method('getPackage')->willReturn($package);
        ExtensionManagementUtility::setPackageManager($packageManager);

        // TYPO3 14 added a CharsetProvider constructor argument, 12 and 13 take none.
        // The argument is built from the constructor signature so that no class name
        // has to be named that only exists in some of the supported versions.
        $reflection = new ReflectionClass(CharsetConverter::class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = (new ReflectionClass($type->getName()))->newInstance();
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * The installation path of EXT:core, derived from a class that lives in it.
     */
    private function getCoreExtensionPath(): string
    {
        $fileName = (new ReflectionClass(CharsetConverter::class))->getFileName();

        return dirname((string)$fileName, 3) . '/';
    }
}
