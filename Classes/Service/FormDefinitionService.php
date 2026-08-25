<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Service;

use Exception;
use Mediatis\FormFieldnames\Dto\FormSummary;
use Mediatis\FormFieldnames\Utility\BackendRequestContext;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface as ExtbaseConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\DTO\SearchCriteria;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

/**
 * Version agnostic access to form definitions.
 *
 * FormPersistenceManager changed the signatures of load(), save() and
 * listForms() in every supported TYPO3 version, and TYPO3 14 returns metadata
 * objects instead of arrays. This service hides those differences, so that
 * everything built on top of it works unchanged on TYPO3 12, 13 and 14.
 *
 * The definitions are handed out and taken back exactly as TYPO3 stores them.
 * Nothing is added to them, so that a load/save round trip cannot introduce
 * keys the form editor never wrote.
 */
class FormDefinitionService
{
    /**
     * @var ?array{formSettings: array<string,mixed>, typoScriptSettings: array<string,mixed>}
     */
    private ?array $formSettings = null;

    public function __construct(
        protected readonly FormPersistenceManagerInterface $formPersistenceManager,
        protected readonly ExtbaseConfigurationManagerInterface $extbaseConfigurationManager,
        protected readonly ExtFormConfigurationManagerInterface $extFormConfigurationManager,
    ) {
    }

    /**
     * All form definitions known to the installation, in the order TYPO3 reports them.
     *
     * @return list<FormSummary>
     */
    public function listForms(): array
    {
        $major = $this->getMajorVersion();

        if ($major <= 12) {
            // @phpstan-ignore-next-line TYPO3 version switch
            return $this->buildSummariesFromArrays($this->formPersistenceManager->listForms());
        }

        $settings = $this->getFormSettings();

        if ($major === 13) {
            // @phpstan-ignore-next-line TYPO3 version switch
            return $this->buildSummariesFromArrays($this->formPersistenceManager->listForms($settings['formSettings']));
        }

        // v14+: listForms() requires a SearchCriteria and returns FormMetadata objects.
        // The class name is resolved through a variable so that older versions never
        // try to load a class that does not exist for them.
        $searchCriteriaClass = SearchCriteria::class;
        // @phpstan-ignore-next-line TYPO3 version switch
        $searchCriteria = new $searchCriteriaClass();
        // @phpstan-ignore-next-line TYPO3 version switch
        $formMetadataList = $this->formPersistenceManager->listForms($settings['formSettings'], $searchCriteria);

        $summaries = [];
        foreach ($formMetadataList as $formMetadata) {
            // FormMetadata::persistenceIdentifier is set by all known storage adapters
            // (file path for YAML forms, UID string for DB stored forms). The null case
            // is defensive - skip rather than crash on an unexpected adapter.
            $persistenceIdentifier = $formMetadata->persistenceIdentifier;
            if (!is_string($persistenceIdentifier) || $persistenceIdentifier === '') {
                continue;
            }

            $summaries[] = new FormSummary(
                $persistenceIdentifier,
                $formMetadata->identifier,
                $formMetadata->name,
                (string)($formMetadata->storageLocation ?? ''),
                $formMetadata->readOnly,
                $formMetadata->invalid,
                $formMetadata->duplicateIdentifier,
            );
        }

        return $summaries;
    }

    /**
     * The raw form definition, or null if it is missing, unreadable or unparsable.
     *
     * @return ?array<string,mixed>
     */
    public function load(string $persistenceIdentifier): ?array
    {
        $major = $this->getMajorVersion();

        try {
            if ($major <= 12) {
                // @phpstan-ignore-next-line TYPO3 version switch
                if (!$this->formPersistenceManager->exists($persistenceIdentifier)) {
                    return null;
                }

                // @phpstan-ignore-next-line TYPO3 version switch
                $formDefinition = $this->formPersistenceManager->load($persistenceIdentifier);
            } elseif ($major === 13) {
                $settings = $this->getFormSettings();
                // @phpstan-ignore-next-line TYPO3 version switch
                $formDefinition = $this->formPersistenceManager->load($persistenceIdentifier, $settings['formSettings'], $settings['typoScriptSettings']);
            } else {
                // v14+: the formSettings parameter was removed.
                $settings = $this->getFormSettings();
                // @phpstan-ignore-next-line TYPO3 version switch
                $formDefinition = $this->formPersistenceManager->load($persistenceIdentifier, $settings['typoScriptSettings']);
            }
        } catch (Exception) {
            // v12 reports a missing form through exists(), v13+ throw instead.
            // Unparsable YAML surfaces here as well.
            return null;
        }

        return $formDefinition === [] ? null : $formDefinition;
    }

    /**
     * @param array<string,mixed> $formDefinition
     */
    public function save(string $persistenceIdentifier, array $formDefinition): void
    {
        if ($this->getMajorVersion() <= 12) {
            // @phpstan-ignore-next-line TYPO3 version switch
            $this->formPersistenceManager->save($persistenceIdentifier, $formDefinition);

            return;
        }

        // v14 added an optional storage location parameter, so the v13 call covers both.
        $settings = $this->getFormSettings();
        // @phpstan-ignore-next-line TYPO3 version switch
        $this->formPersistenceManager->save($persistenceIdentifier, $formDefinition, $settings['formSettings']);
    }

    /**
     * Whether the storage behind this identifier accepts writes.
     *
     * Forms provided by extensions are read-only. Callers that already hold a
     * FormSummary should use its readOnly property instead, because this method
     * has to list all forms again.
     */
    public function isWritable(string $persistenceIdentifier): bool
    {
        foreach ($this->listForms() as $summary) {
            if ($summary->persistenceIdentifier === $persistenceIdentifier) {
                return !$summary->readOnly;
            }
        }

        return false;
    }

    /**
     * @param array<int|string,array<string,mixed>> $forms
     *
     * @return list<FormSummary>
     */
    protected function buildSummariesFromArrays(array $forms): array
    {
        $summaries = [];
        foreach ($forms as $form) {
            $persistenceIdentifier = (string)($form['persistenceIdentifier'] ?? '');
            if ($persistenceIdentifier === '') {
                continue;
            }

            $summaries[] = new FormSummary(
                $persistenceIdentifier,
                (string)($form['identifier'] ?? ''),
                (string)($form['name'] ?? ''),
                (string)($form['location'] ?? ''),
                (bool)($form['readOnly'] ?? false),
                (bool)($form['invalid'] ?? false),
                (bool)($form['duplicateIdentifier'] ?? false),
            );
        }

        return $summaries;
    }

    /**
     * Lazily resolves the settings the FormPersistenceManager needs.
     * On TYPO3 12 it needs none, on 13+ they come from Extbase and the form YAML configuration.
     *
     * @return array{formSettings: array<string,mixed>, typoScriptSettings: array<string,mixed>}
     */
    protected function getFormSettings(): array
    {
        if ($this->formSettings === null) {
            if ($this->getMajorVersion() <= 12) {
                $this->formSettings = [
                    'formSettings' => [],
                    'typoScriptSettings' => [],
                ];
            } else {
                $typoScriptSettings = BackendRequestContext::ensure(
                    fn (): array => $this->extbaseConfigurationManager->getConfiguration(
                        ExtbaseConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                        'form'
                    )
                );
                // @phpstan-ignore-next-line TYPO3 version switch
                $formSettings = $this->extFormConfigurationManager->getYamlConfiguration($typoScriptSettings, false);
                $this->formSettings = [
                    'formSettings' => [
                        'persistenceManager' => $formSettings['persistenceManager'] ?? [],
                    ],
                    'typoScriptSettings' => [
                        'formDefinitionOverrides' => $typoScriptSettings['formDefinitionOverrides'] ?? [],
                    ],
                ];
            }
        }

        return $this->formSettings;
    }

    protected function getMajorVersion(): int
    {
        return (new Typo3Version())->getMajorVersion();
    }
}
