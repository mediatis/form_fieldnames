<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Service;

use Mediatis\FormFieldnames\Dto\NameSource;
use Mediatis\FormFieldnames\Tests\Unit\Fixtures\GeneratorFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FieldNameGeneratorTest extends UnitTestCase
{
    /**
     * @return array<string,array{0: string, 1: string}>
     */
    public static function labelProvider(): array
    {
        return [
            'plain label' => ['Vorname', 'vorname'],
            'umlauts and sharp s' => ['Nachname (Straße)', 'nachname_strasse'],
            'all umlauts' => ['Ärger Ösen Übung', 'aerger_oesen_uebung'],
            'punctuation collapses' => ['E-Mail-Adresse', 'e_mail_adresse'],
            'repeated separators collapse' => ['Telefon   ///   privat', 'telefon_privat'],
            'leading and trailing junk is trimmed' => ['  ... Postleitzahl ...  ', 'postleitzahl'],
            'already snake_case stays' => ['first_name', 'first_name'],
            'digits are kept' => ['Adresse 2', 'adresse_2'],
        ];
    }

    #[Test]
    #[DataProvider('labelProvider')]
    public function labelIsReducedToSnakeCase(string $label, string $expected): void
    {
        $generated = GeneratorFactory::create()->generate($label, 'text-1', []);

        self::assertSame($expected, $generated->name);
        self::assertSame(NameSource::Label, $generated->source);
        self::assertFalse($generated->suffixApplied);
    }

    #[Test]
    public function emptyLabelFallsBackToTheIdentifier(): void
    {
        $generated = GeneratorFactory::create()->generate('', 'text-4', []);

        self::assertSame('text_4', $generated->name);
        self::assertSame(NameSource::Identifier, $generated->source);
    }

    #[Test]
    public function labelWithoutUsableCharactersFallsBackToTheIdentifier(): void
    {
        $generated = GeneratorFactory::create()->generate('!!! ???', 'email-1', []);

        self::assertSame('email_1', $generated->name);
        self::assertSame(NameSource::Identifier, $generated->source);
    }

    #[Test]
    public function unusableLabelAndIdentifierFallBackToAGenericName(): void
    {
        $generated = GeneratorFactory::create()->generate('', '///', []);

        self::assertSame('field', $generated->name);
        self::assertSame(NameSource::Identifier, $generated->source);
    }

    #[Test]
    public function languageReferenceIsResolved(): void
    {
        $generator = GeneratorFactory::create(['LLL:EXT:example/locallang.xlf:street' => 'Straße und Hausnummer']);

        $generated = $generator->generate('LLL:EXT:example/locallang.xlf:street', 'text-1', []);

        self::assertSame('strasse_und_hausnummer', $generated->name);
        self::assertSame(NameSource::LabelReference, $generated->source);
    }

    #[Test]
    public function unresolvableLanguageReferenceFallsBackToTheIdentifier(): void
    {
        $generated = GeneratorFactory::create()->generate('LLL:EXT:example/locallang.xlf:missing', 'text-7', []);

        self::assertSame('text_7', $generated->name);
        self::assertSame(NameSource::Identifier, $generated->source);
    }

    #[Test]
    public function freeNameIsUsedUnchanged(): void
    {
        $generated = GeneratorFactory::create()->generate('Vorname', 'text-1', ['nachname', 'email']);

        self::assertSame('vorname', $generated->name);
        self::assertFalse($generated->suffixApplied);
    }

    #[Test]
    public function takenNameGetsACounterStartingAtTwo(): void
    {
        $generated = GeneratorFactory::create()->generate('Vorname', 'text-1', ['vorname']);

        self::assertSame('vorname2', $generated->name);
        self::assertTrue($generated->suffixApplied);
    }

    #[Test]
    public function counterSkipsNamesThatAreAlreadyTaken(): void
    {
        $generated = GeneratorFactory::create()->generate('Vorname', 'text-1', ['vorname', 'vorname2']);

        self::assertSame('vorname3', $generated->name);
        self::assertTrue($generated->suffixApplied);
    }

    #[Test]
    public function counterAlsoAppliesToTheIdentifierFallback(): void
    {
        $generated = GeneratorFactory::create()->generate('', 'text-1', ['text_1']);

        self::assertSame('text_12', $generated->name);
        self::assertSame(NameSource::Identifier, $generated->source);
        self::assertTrue($generated->suffixApplied);
    }

    #[Test]
    public function sanitizeIsAvailableOnItsOwn(): void
    {
        self::assertSame('ueber_uns', GeneratorFactory::create()->sanitize('Über uns'));
    }
}
