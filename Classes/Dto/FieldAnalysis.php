<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * The field name state of a single form element.
 */
final readonly class FieldAnalysis
{
    public function __construct(
        public string $elementIdentifier,
        public string $elementType,
        public string $label,
        public string $currentName,
        public ?string $proposedName,
        public ?NameSource $source,
        public bool $suffixApplied,
        public FieldStatus $status,
    ) {
    }

    public function hasProposal(): bool
    {
        return $this->proposedName !== null;
    }
}
