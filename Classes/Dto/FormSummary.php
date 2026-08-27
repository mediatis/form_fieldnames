<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * Metadata of a single form definition.
 *
 * The properties are the intersection of what TYPO3 12, 13 and 14 report when
 * listing forms, so that consumers do not have to care about the version.
 */
final readonly class FormSummary
{
    public function __construct(
        public string $persistenceIdentifier,
        public string $identifier,
        public string $name,
        public string $storageLocation,
        public bool $readOnly,
        public bool $invalid,
        public bool $duplicateIdentifier,
    ) {
    }
}
