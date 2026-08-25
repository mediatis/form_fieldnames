<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * What a migration did to a single form definition.
 */
final readonly class FormMigrationResult
{
    /**
     * @param array<string,string> $appliedNames  element identifier => name written
     * @param list<string>         $problems      issues the migration could not resolve
     */
    private function __construct(
        public string $persistenceIdentifier,
        public array $appliedNames,
        public bool $saved,
        public ?string $skippedReason,
        public array $problems,
    ) {
    }

    /**
     * @param array<string,string> $appliedNames
     * @param list<string>         $problems
     */
    public static function applied(string $persistenceIdentifier, array $appliedNames, array $problems): self
    {
        return new self($persistenceIdentifier, $appliedNames, true, null, $problems);
    }

    /**
     * Nothing to do: every element already has a name.
     *
     * @param list<string> $problems
     */
    public static function unchanged(string $persistenceIdentifier, array $problems): self
    {
        return new self($persistenceIdentifier, [], false, null, $problems);
    }

    /**
     * The form could not be migrated, for example because it is read-only.
     *
     * @param list<string> $problems
     */
    public static function skipped(string $persistenceIdentifier, string $reason, array $problems): self
    {
        return new self($persistenceIdentifier, [], false, $reason, $problems);
    }
}
