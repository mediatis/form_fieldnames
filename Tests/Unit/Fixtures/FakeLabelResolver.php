<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Fixtures;

use Mediatis\FormFieldnames\Service\LabelResolverInterface;

/**
 * Resolves language references from a fixed map instead of from XLF files.
 */
final readonly class FakeLabelResolver implements LabelResolverInterface
{
    /**
     * @param array<string,string> $translations reference => resolved text
     */
    public function __construct(private array $translations = [])
    {
    }

    public function resolve(string $label): string
    {
        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }

        return $this->translations[$label] ?? '';
    }
}
