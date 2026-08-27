<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Service;

use Mediatis\FormFieldnames\Dto\GeneratedName;
use Mediatis\FormFieldnames\Dto\NameSource;
use TYPO3\CMS\Core\Charset\CharsetConverter;

/**
 * Derives field names from form element labels.
 *
 * Names are meant to be readable and stable, so the label is the primary source
 * and the element identifier only a fallback. The result is snake_case, because
 * the name ends up as an HTML name attribute and as a mapping key.
 */
class FieldNameGenerator
{
    /**
     * Placed between the name and the counter when a name is already taken.
     * "email" and "email2", not "email" and "email_2".
     */
    protected const SUFFIX_SEPARATOR = '';

    /**
     * Used when neither the label nor the identifier contain anything usable.
     */
    protected const FALLBACK_NAME = 'field';

    /**
     * Prefix TYPO3 uses for language file references.
     */
    protected const LANGUAGE_REFERENCE_PREFIX = 'LLL:';

    public function __construct(
        protected readonly CharsetConverter $charsetConverter,
        protected readonly LabelResolverInterface $labelResolver,
    ) {
    }

    /**
     * @param list<string> $takenNames names already in use in the same form
     */
    public function generate(string $label, string $elementIdentifier, array $takenNames): GeneratedName
    {
        [$base, $source] = $this->resolveBase($label, $elementIdentifier);

        $name = $base;
        $counter = 2;
        $suffixApplied = false;
        while (in_array($name, $takenNames, true)) {
            $name = $base . static::SUFFIX_SEPARATOR . $counter;
            ++$counter;
            $suffixApplied = true;
        }

        return new GeneratedName($name, $source, $suffixApplied);
    }

    /**
     * Reduces arbitrary text to snake_case.
     *
     * Transliteration is done with the same converter TYPO3 uses for slugs, so
     * that German umlauts become the expected letter pairs: "Straße" turns into
     * "strasse", not "strae".
     */
    public function sanitize(string $value): string
    {
        $value = $this->charsetConverter->utf8_char_mapping($value);
        $value = strtolower($value);
        $value = (string)preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }

    /**
     * @return array{0: string, 1: NameSource}
     */
    protected function resolveBase(string $label, string $elementIdentifier): array
    {
        $base = $this->sanitize($this->labelResolver->resolve($label));
        if ($base !== '') {
            return [$base, $this->isLanguageReference($label) ? NameSource::LabelReference : NameSource::Label];
        }

        $base = $this->sanitize($elementIdentifier);

        return [$base !== '' ? $base : static::FALLBACK_NAME, NameSource::Identifier];
    }

    protected function isLanguageReference(string $label): bool
    {
        return str_starts_with($label, static::LANGUAGE_REFERENCE_PREFIX);
    }
}
