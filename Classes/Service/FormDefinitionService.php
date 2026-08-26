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
 * Definitions are read as they are stored: the TypoScript overrides that TYPO3 merges
 * in for rendering are kept out of the load path, exactly as FormEditorController does,
 * so that what is analysed is what the form editor shows. TYPO3 12 has no way to opt
 * out - its persistence manager resolves the overrides itself - so on that version a
 * definition may arrive with them merged in, the same way the core form editor gets it.
 *
 * The core API this builds on - FormPersistenceManagerInterface, ext:form's
 * ConfigurationManagerInterface and FormMetadata - is marked @internal, so it can
 * change between TYPO3 releases without a deprecation period.
 */
class FormDefinitionService
{
    /**
     * Passed to load() instead of the resolved overrides. TYPO3 merges
     * formDefinitionOverrides into the definition it returns, and a definition that
     * carries them must never be written back to storage.
     */
    private const NO_OVERRIDES = ['formDefinitionOverrides' => []];

    /**
     * @var ?array<string,mixed>
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

        $formSettings = $this->getFormSettings();

        if ($major === 13) {
            // @phpstan-ignore-next-line TYPO3 version switch
            return $this->buildSummariesFromArrays($this->formPersistenceManager->listForms($formSettings));
        }

        // v14+: listForms() requires a SearchCriteria and returns FormMetadata objects.
        // The class name is resolved through a variable so that older versions never
        // try to load a class that does not exist for them.
        // @phpstan-ignore-next-line TYPO3 version switch — class only exists on v14+
        $searchCriteriaClass = SearchCriteria::class;
        // @phpstan-ignore-next-line TYPO3 version switch
        $searchCriteria = new $searchCriteriaClass();
        // @phpstan-ignore-next-line TYPO3 version switch
        $formMetadataList = $this->formPersistenceManager->listForms($formSettings, $searchCriteria);

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
                // @phpstan-ignore-next-line TYPO3 version switch
                $formDefinition = $this->formPersistenceManager->load($persistenceIdentifier, $this->getFormSettings(), self::NO_OVERRIDES);
            } else {
                // v14+: the formSettings parameter was removed.
                // @phpstan-ignore-next-line TYPO3 version switch
                $formDefinition = $this->formPersistenceManager->load($persistenceIdentifier, self::NO_OVERRIDES);
            }
        } catch (Exception) {
            // A missing file or storage: v12 reports it through exists(), v13+ throw.
            return null;
        }

        // Broken YAML does not throw. Every version catches it internally and returns
        // a stub carrying the parse error as its label, flagged as invalid.
        if ($formDefinition === [] || ($formDefinition['invalid'] ?? false) === true) {
            return null;
        }

        return $formDefinition;
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
        // @phpstan-ignore-next-line TYPO3 version switch
        $this->formPersistenceManager->save($persistenceIdentifier, $formDefinition, $this->getFormSettings());
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
     * Lazily resolves the persistence settings the FormPersistenceManager needs.
     * TYPO3 12 takes none; 13 and up build them from the form YAML configuration, which
     * is in turn resolved from TypoScript.
     *
     * @return array<string,mixed>
     */
    protected function getFormSettings(): array
    {
        if ($this->formSettings === null) {
            if ($this->getMajorVersion() <= 12) {
                $this->formSettings = [];
            } else {
                $typoScriptSettings = BackendRequestContext::ensure(
                    fn (): array => $this->extbaseConfigurationManager->getConfiguration(
                        ExtbaseConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                        'form'
                    )
                );
                // @phpstan-ignore-next-line TYPO3 version switch
                $formSettings = $this->extFormConfigurationManager->getYamlConfiguration($typoScriptSettings, false);
                $this->formSettings = ['persistenceManager' => $formSettings['persistenceManager'] ?? []];
            }
        }

        return $this->formSettings;
    }

    protected function getMajorVersion(): int
    {
        return (new Typo3Version())->getMajorVersion();
    }
}
