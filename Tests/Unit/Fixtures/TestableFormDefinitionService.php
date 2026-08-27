<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Fixtures;

use Mediatis\FormFieldnames\Dto\FormSummary;
use Mediatis\FormFieldnames\Service\FormDefinitionService;

/**
 * Opens up the parts of the service that are otherwise reached only through a
 * TYPO3 version specific code path.
 */
final class TestableFormDefinitionService extends FormDefinitionService
{
    /**
     * @var ?list<FormSummary>
     */
    public ?array $formsOverride = null;

    /**
     * @return list<FormSummary>
     */
    public function listForms(): array
    {
        return $this->formsOverride ?? parent::listForms();
    }

    /**
     * @param array<int|string,array<string,mixed>> $forms
     *
     * @return list<FormSummary>
     */
    public function exposeBuildSummariesFromArrays(array $forms): array
    {
        return $this->buildSummariesFromArrays($forms);
    }
}
