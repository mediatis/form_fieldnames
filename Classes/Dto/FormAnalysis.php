<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * The field name state of a whole form definition.
 */
final readonly class FormAnalysis
{
    /**
     * @param list<FieldAnalysis> $fields   all elements that support a field name
     * @param list<string>        $problems issues a migration cannot resolve
     */
    public function __construct(
        public FormSummary $form,
        public array $fields,
        public array $problems,
    ) {
    }

    /**
     * The elements a migration would fill, in document order.
     *
     * @return list<FieldAnalysis>
     */
    public function getProposals(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FieldAnalysis $field): bool => $field->hasProposal()
        ));
    }

    public function hasProposals(): bool
    {
        return $this->getProposals() !== [];
    }

    public function needsAttention(): bool
    {
        return $this->hasProposals() || $this->problems !== [];
    }
}
