<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Fixtures;

use Mediatis\FormFieldnames\Service\FieldNameGenerator;
use ReflectionClass;
use ReflectionNamedType;
use TYPO3\CMS\Core\Charset\CharsetConverter;

/**
 * Builds a generator with the real transliteration but without language files.
 *
 * The real CharsetConverter is used on purpose: the German letter pairs it
 * produces ("Straße" => "strasse") are part of what the naming rules promise.
 */
final class GeneratorFactory
{
    /**
     * @param array<string,string> $translations language reference => resolved text
     */
    public static function create(array $translations = []): FieldNameGenerator
    {
        return new FieldNameGenerator(self::createCharsetConverter(), new FakeLabelResolver($translations));
    }

    private static function createCharsetConverter(): CharsetConverter
    {
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
}
