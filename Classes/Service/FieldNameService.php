<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Service;

use Exception;
use Mediatis\FormFieldnames\Dto\FieldAnalysis;
use Mediatis\FormFieldnames\Dto\FieldStatus;
use Mediatis\FormFieldnames\Dto\FormAnalysis;
use Mediatis\FormFieldnames\Dto\FormMigrationResult;
use Mediatis\FormFieldnames\Dto\FormSummary;
use Mediatis\FormFieldnames\Utility\BackendRequestContext;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;

/**
 * Reports which form elements are missing a field name, and fills them in.
 *
 * Analysis never changes anything. Migration recomputes it rather than applying a
 * previously computed plan, so a stale view cannot write the wrong names.
 *
 * Existing names are never overwritten. An element that already has a name is
 * left alone, which also makes repeated runs a no-op.
 */
class FieldNameService
{
    /**
     * The property the form_fieldnames inspector editors write to. Element types
     * are recognised by this path in the prototype configuration rather than by a
     * hard coded list, so element types added by a project are covered as well.
     */
    protected const NAME_PROPERTY_PATH = 'properties.fluidAdditionalAttributes.name';

    /**
     * @var array<string,array<string,bool>>
     */
    private array $supportedElementTypes = [];

    public function __construct(
        protected readonly FormDefinitionService $formDefinitionService,
        protected readonly FieldNameGenerator $fieldNameGenerator,
        protected readonly ConfigurationService $configurationService,
    ) {
    }

    /**
     * @return array<string,FormAnalysis> keyed by persistence identifier
     */
    public function analyseAll(): array
    {
        $analyses = [];
        foreach ($this->formDefinitionService->listForms() as $summary) {
            $analyses[$summary->persistenceIdentifier] = $this->analyseForm($summary);
        }

        return $analyses;
    }

    public function analyse(string $persistenceIdentifier): ?FormAnalysis
    {
        $summary = $this->findSummary($persistenceIdentifier);

        return !$summary instanceof FormSummary ? null : $this->analyseForm($summary);
    }

    public function migrate(string $persistenceIdentifier): ?FormMigrationResult
    {
        $summary = $this->findSummary($persistenceIdentifier);

        return $summary instanceof FormSummary ? $this->migrateForm($summary) : null;
    }

    /**
     * @return array<string,FormMigrationResult> keyed by persistence identifier
     */
    public function migrateAll(): array
    {
        $results = [];
        foreach ($this->formDefinitionService->listForms() as $summary) {
            $results[$summary->persistenceIdentifier] = $this->migrateForm($summary);
        }

        return $results;
    }

    protected function analyseForm(FormSummary $summary): FormAnalysis
    {
        if ($summary->invalid) {
            return new FormAnalysis($summary, [], ['The form definition is invalid and cannot be analysed.']);
        }

        $formDefinition = $this->formDefinitionService->load($summary->persistenceIdentifier);
        if ($formDefinition === null) {
            return new FormAnalysis($summary, [], ['The form definition could not be loaded.']);
        }

        return $this->analyseFormDefinition($summary, $formDefinition);
    }

    /**
     * @param array<string,mixed> $formDefinition
     */
    protected function analyseFormDefinition(FormSummary $summary, array $formDefinition): FormAnalysis
    {
        $problems = [];
        if ($summary->duplicateIdentifier) {
            $problems[] = sprintf(
                'The form identifier "%s" is used by more than one form. '
                . 'Field names are unique per form, but anything that addresses a form by its '
                . 'identifier cannot tell these apart.',
                $summary->identifier
            );
        }

        $prototypeName = (string)($formDefinition['prototypeName'] ?? 'standard');
        $supportedTypes = $this->getSupportedElementTypes($prototypeName);
        if ($supportedTypes === []) {
            $problems[] = sprintf(
                'No element type of prototype "%s" defines a field name editor.',
                $prototypeName
            );

            return new FormAnalysis($summary, [], $problems);
        }

        $elements = [];
        $renderables = $formDefinition['renderables'] ?? [];
        if (is_array($renderables)) {
            $this->collectElements($renderables, $supportedTypes, $elements);
        }

        $namesInUse = [];
        foreach ($elements as $element) {
            if ($element['name'] !== '') {
                $namesInUse[$element['name']][] = $element['identifier'];
            }
        }

        // Numeric names would come back from array_keys() as integers.
        $takenNames = array_map(strval(...), array_keys($namesInUse));
        $duplicates = array_filter($namesInUse, static fn (array $identifiers): bool => count($identifiers) > 1);

        foreach ($duplicates as $name => $identifiers) {
            $problems[] = sprintf(
                'The name "%s" is used by more than one element (%s). Fix this in the form editor.',
                $name,
                implode(', ', $identifiers)
            );
        }

        $fields = [];
        $hasProposals = false;
        foreach ($elements as $element) {
            if ($element['name'] !== '') {
                $fields[] = new FieldAnalysis(
                    $element['identifier'],
                    $element['type'],
                    $element['label'],
                    $element['name'],
                    null,
                    null,
                    false,
                    isset($duplicates[$element['name']]) ? FieldStatus::Duplicate : FieldStatus::Ok,
                );

                continue;
            }

            $generated = $this->fieldNameGenerator->generate($element['label'], $element['identifier'], $takenNames);
            $takenNames[] = $generated->name;
            $hasProposals = true;

            $fields[] = new FieldAnalysis(
                $element['identifier'],
                $element['type'],
                $element['label'],
                '',
                $generated->name,
                $generated->source,
                $generated->suffixApplied,
                FieldStatus::Missing,
            );
        }

        if ($summary->readOnly && $hasProposals) {
            $problems[] = 'The form is stored read-only, the missing names cannot be written.';
        }

        return new FormAnalysis($summary, $fields, $problems);
    }

    protected function migrateForm(FormSummary $summary): FormMigrationResult
    {
        $analysis = $this->analyseForm($summary);

        $proposals = $analysis->getProposals();
        if ($proposals === []) {
            return FormMigrationResult::unchanged($summary->persistenceIdentifier, $analysis->problems);
        }

        if ($summary->readOnly) {
            return FormMigrationResult::skipped(
                $summary->persistenceIdentifier,
                'Form is read-only.',
                $analysis->problems
            );
        }

        $formDefinition = $this->formDefinitionService->load($summary->persistenceIdentifier);
        if ($formDefinition === null) {
            return FormMigrationResult::skipped(
                $summary->persistenceIdentifier,
                'Form could not be loaded.',
                $analysis->problems
            );
        }

        $names = [];
        foreach ($proposals as $field) {
            $names[$field->elementIdentifier] = (string)$field->proposedName;
        }

        $applied = [];
        $renderables = $formDefinition['renderables'] ?? [];
        if (is_array($renderables)) {
            $this->applyNames($renderables, $names, $applied);
            $formDefinition['renderables'] = $renderables;
        }

        if ($applied === []) {
            return FormMigrationResult::unchanged($summary->persistenceIdentifier, $analysis->problems);
        }

        $this->formDefinitionService->save($summary->persistenceIdentifier, $formDefinition);

        return FormMigrationResult::applied($summary->persistenceIdentifier, $applied, $analysis->problems);
    }

    protected function findSummary(string $persistenceIdentifier): ?FormSummary
    {
        foreach ($this->formDefinitionService->listForms() as $summary) {
            if ($summary->persistenceIdentifier === $persistenceIdentifier) {
                return $summary;
            }
        }

        return null;
    }

    /**
     * @param array<int|string,mixed>                                                $renderables
     * @param array<string,bool>                                                     $supportedTypes
     * @param list<array{identifier: string, type: string, label: string, name: string}> $elements
     */
    protected function collectElements(array $renderables, array $supportedTypes, array &$elements): void
    {
        foreach ($renderables as $renderable) {
            if (!is_array($renderable)) {
                continue;
            }

            $type = (string)($renderable['type'] ?? '');
            if (isset($supportedTypes[$type])) {
                $elements[] = [
                    'identifier' => (string)($renderable['identifier'] ?? ''),
                    'type' => $type,
                    'label' => (string)($renderable['label'] ?? ''),
                    'name' => (string)($renderable['properties']['fluidAdditionalAttributes']['name'] ?? ''),
                ];
            }

            if (isset($renderable['renderables']) && is_array($renderable['renderables'])) {
                $this->collectElements($renderable['renderables'], $supportedTypes, $elements);
            }
        }
    }

    /**
     * @param array<int|string,mixed> $renderables
     * @param array<string,string>    $names   element identifier => name to write
     * @param array<string,string>    $applied element identifier => name written
     */
    protected function applyNames(array &$renderables, array $names, array &$applied): void
    {
        foreach ($renderables as &$renderable) {
            if (!is_array($renderable)) {
                continue;
            }

            $identifier = (string)($renderable['identifier'] ?? '');
            $currentName = (string)($renderable['properties']['fluidAdditionalAttributes']['name'] ?? '');
            if ($identifier !== '' && $currentName === '' && isset($names[$identifier])) {
                $renderable['properties']['fluidAdditionalAttributes']['name'] = $names[$identifier];
                $applied[$identifier] = $names[$identifier];
            }

            if (isset($renderable['renderables']) && is_array($renderable['renderables'])) {
                $this->applyNames($renderable['renderables'], $names, $applied);
            }
        }

        unset($renderable);
    }

    /**
     * The element types of a prototype that offer a field name editor.
     *
     * @return array<string,bool>
     */
    protected function getSupportedElementTypes(string $prototypeName): array
    {
        if (isset($this->supportedElementTypes[$prototypeName])) {
            return $this->supportedElementTypes[$prototypeName];
        }

        try {
            $prototypeConfiguration = BackendRequestContext::ensure(
                fn (): array => $this->configurationService->getPrototypeConfiguration($prototypeName)
            );
        } catch (Exception) {
            return $this->supportedElementTypes[$prototypeName] = [];
        }

        $types = [];
        $elementsDefinition = $prototypeConfiguration['formElementsDefinition'] ?? [];
        if (is_array($elementsDefinition)) {
            foreach ($elementsDefinition as $type => $elementConfiguration) {
                $editors = $elementConfiguration['formEditor']['editors'] ?? [];
                if (!is_array($editors)) {
                    continue;
                }

                foreach ($editors as $editor) {
                    if (is_array($editor) && ($editor['propertyPath'] ?? null) === static::NAME_PROPERTY_PATH) {
                        $types[(string)$type] = true;
                        break;
                    }
                }
            }
        }

        return $this->supportedElementTypes[$prototypeName] = $types;
    }
}
