<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * A field name proposal, including how it was derived.
 */
final readonly class GeneratedName
{
    public function __construct(
        public string $name,
        public NameSource $source,
        public bool $suffixApplied,
    ) {
    }
}
