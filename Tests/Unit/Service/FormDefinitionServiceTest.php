<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Service;

use Mediatis\FormFieldnames\Dto\FormSummary;
use Mediatis\FormFieldnames\Tests\Unit\Fixtures\TestableFormDefinitionService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface as ExtbaseConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as ExtFormConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The version specific dispatch inside the service can only ever be exercised for
 * the TYPO3 version the test run installs, because a mock of
 * FormPersistenceManagerInterface carries that version's signatures. The CI matrix
 * covers the other branches by running these tests once per supported version.
 */
final class FormDefinitionServiceTest extends UnitTestCase
{
    private const PERSISTENCE_IDENTIFIER = '1:/form_definitions/test.form.yaml';

    #[Test]
    public function formListEntriesAreMappedToSummaries(): void
    {
        $summaries = $this->createSubject()->exposeBuildSummariesFromArrays([
            [
                'persistenceIdentifier' => self::PERSISTENCE_IDENTIFIER,
                'identifier' => 'test',
                'name' => 'Test Form',
                'location' => '1:/form_definitions/',
                'readOnly' => true,
                'invalid' => false,
                'duplicateIdentifier' => true,
            ],
        ]);

        self::assertCount(1, $summaries);
        $summary = $summaries[0];
        self::assertSame(self::PERSISTENCE_IDENTIFIER, $summary->persistenceIdentifier);
        self::assertSame('test', $summary->identifier);
        self::assertSame('Test Form', $summary->name);
        self::assertSame('1:/form_definitions/', $summary->storageLocation);
        self::assertTrue($summary->readOnly);
        self::assertFalse($summary->invalid);
        self::assertTrue($summary->duplicateIdentifier);
    }

    #[Test]
    public function missingListEntryKeysFallBackToHarmlessDefaults(): void
    {
        $summaries = $this->createSubject()->exposeBuildSummariesFromArrays([
            ['persistenceIdentifier' => self::PERSISTENCE_IDENTIFIER],
        ]);

        self::assertSame('', $summaries[0]->identifier);
        self::assertSame('', $summaries[0]->name);
        self::assertSame('', $summaries[0]->storageLocation);
        self::assertFalse($summaries[0]->readOnly);
        self::assertFalse($summaries[0]->invalid);
        self::assertFalse($summaries[0]->duplicateIdentifier);
    }

    #[Test]
    public function listEntriesWithoutAPersistenceIdentifierAreSkipped(): void
    {
        $summaries = $this->createSubject()->exposeBuildSummariesFromArrays([
            ['identifier' => 'no-identifier'],
            ['persistenceIdentifier' => '', 'identifier' => 'empty-identifier'],
            ['persistenceIdentifier' => self::PERSISTENCE_IDENTIFIER],
        ]);

        self::assertCount(1, $summaries);
        self::assertSame(self::PERSISTENCE_IDENTIFIER, $summaries[0]->persistenceIdentifier);
    }

    #[Test]
    public function formMetadataIsMappedOnTypo3V14AndAbove(): void
    {
        $metadataClass = 'TYPO3\\CMS\\Form\\Domain\\DTO\\FormMetadata';
        if (!class_exists($metadataClass)) {
            self::markTestSkipped('FormMetadata exists only on TYPO3 14 and above.');
        }

        $persistenceManager = $this->createMock(FormPersistenceManagerInterface::class);
        $persistenceManager->expects(self::once())->method('listForms')->willReturn([
            new $metadataClass(
                identifier: 'test',
                type: 'yaml',
                name: 'Test Form',
                prototypeName: 'standard',
                persistenceIdentifier: self::PERSISTENCE_IDENTIFIER,
                readOnly: true,
                storageLocation: '1:/form_definitions/',
            ),
            // No persistence identifier: cannot be addressed, so it is dropped.
            new $metadataClass(identifier: 'orphan', type: 'yaml', name: 'Orphan', prototypeName: 'standard'),
        ]);

        $summaries = $this->createSubject($persistenceManager)->listForms();

        self::assertCount(1, $summaries);
        self::assertSame(self::PERSISTENCE_IDENTIFIER, $summaries[0]->persistenceIdentifier);
        self::assertSame('1:/form_definitions/', $summaries[0]->storageLocation);
        self::assertTrue($summaries[0]->readOnly);
    }

    #[Test]
    public function loadReturnsNullWhenTheFormCannotBeRead(): void
    {
        $persistenceManager = $this->createStub(FormPersistenceManagerInterface::class);
        // TYPO3 12 asks exists() first, later versions throw instead.
        if (method_exists($persistenceManager, 'exists')) {
            $persistenceManager->method('exists')->willReturn(true);
        }

        $persistenceManager->method('load')->willThrowException(new RuntimeException('broken yaml', 1756100000));

        self::assertNull($this->createSubject($persistenceManager)->load(self::PERSISTENCE_IDENTIFIER));
    }

    #[Test]
    public function loadReturnsNullForAnEmptyDefinition(): void
    {
        $persistenceManager = $this->createStub(FormPersistenceManagerInterface::class);
        if (method_exists($persistenceManager, 'exists')) {
            $persistenceManager->method('exists')->willReturn(true);
        }

        $persistenceManager->method('load')->willReturn([]);

        self::assertNull($this->createSubject($persistenceManager)->load(self::PERSISTENCE_IDENTIFIER));
    }

    #[Test]
    public function loadReturnsTheDefinitionUnchanged(): void
    {
        $definition = ['identifier' => 'test', 'renderables' => []];
        $persistenceManager = $this->createMock(FormPersistenceManagerInterface::class);
        if (method_exists($persistenceManager, 'exists')) {
            $persistenceManager->method('exists')->willReturn(true);
        }

        $persistenceManager->expects(self::once())->method('load')->willReturn($definition);

        self::assertSame($definition, $this->createSubject($persistenceManager)->load(self::PERSISTENCE_IDENTIFIER));
    }

    #[Test]
    public function loadReturnsNullForADefinitionCoreFlaggedAsInvalid(): void
    {
        // Broken YAML never throws: every version catches it and returns this stub.
        $persistenceManager = $this->createStub(FormPersistenceManagerInterface::class);
        if (method_exists($persistenceManager, 'exists')) {
            $persistenceManager->method('exists')->willReturn(true);
        }

        $persistenceManager->method('load')->willReturn([
            'type' => 'Form',
            'identifier' => self::PERSISTENCE_IDENTIFIER,
            'label' => 'Unable to parse the YAML file',
            'invalid' => true,
        ]);

        self::assertNull($this->createSubject($persistenceManager)->load(self::PERSISTENCE_IDENTIFIER));
    }

    #[Test]
    public function loadNeverPassesTypoScriptOverridesToThePersistenceManager(): void
    {
        $capturedArguments = [];
        $persistenceManager = $this->createMock(FormPersistenceManagerInterface::class);
        if (method_exists($persistenceManager, 'exists')) {
            $persistenceManager->method('exists')->willReturn(true);
        }

        $persistenceManager->expects(self::once())->method('load')->willReturnCallback(
            function (mixed ...$arguments) use (&$capturedArguments): array {
                $capturedArguments = $arguments;

                return ['identifier' => 'test'];
            }
        );

        $subject = $this->createSubject($persistenceManager, [
            'formDefinitionOverrides' => ['test' => ['label' => 'Overridden at runtime']],
        ]);
        $subject->load(self::PERSISTENCE_IDENTIFIER);

        $nonEmptyOverrides = [];
        foreach ($capturedArguments as $argument) {
            if (is_array($argument) && ($argument['formDefinitionOverrides'] ?? []) !== []) {
                $nonEmptyOverrides[] = $argument['formDefinitionOverrides'];
            }
        }

        // Empty on 13 and 14, absent on 12: the analysis has to see what is stored,
        // not what TypoScript turns the form into for rendering.
        self::assertSame([], $nonEmptyOverrides);
    }

    #[Test]
    public function saveIsDelegatedToThePersistenceManager(): void
    {
        $persistenceManager = $this->createMock(FormPersistenceManagerInterface::class);
        $invocation = $persistenceManager->expects(self::once())->method('save');
        $returnType = (new ReflectionMethod(FormPersistenceManagerInterface::class, 'save'))->getReturnType();
        if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin()) {
            $identifierClass = $returnType->getName();
            $invocation->willReturn(new $identifierClass(self::PERSISTENCE_IDENTIFIER));
        }

        $this->createSubject($persistenceManager)->save(self::PERSISTENCE_IDENTIFIER, ['identifier' => 'test']);
    }

    #[Test]
    public function writabilityFollowsTheReadOnlyFlagOfTheForm(): void
    {
        $subject = $this->createSubject();
        $subject->formsOverride = [
            new FormSummary(self::PERSISTENCE_IDENTIFIER, 'test', 'Test', '1:/form_definitions/', false, false, false),
            new FormSummary('EXT:example/Forms/fixed.form.yaml', 'fixed', 'Fixed', 'EXT:example/Forms/', true, false, false),
        ];

        self::assertTrue($subject->isWritable(self::PERSISTENCE_IDENTIFIER));
        self::assertFalse($subject->isWritable('EXT:example/Forms/fixed.form.yaml'));
        self::assertFalse($subject->isWritable('1:/form_definitions/unknown.form.yaml'));
    }

    /**
     * @param array<string,mixed> $typoScriptSettings
     */
    private function createSubject(
        ?FormPersistenceManagerInterface $persistenceManager = null,
        array $typoScriptSettings = [],
    ): TestableFormDefinitionService {
        $extbaseConfigurationManager = $this->createStub(ExtbaseConfigurationManagerInterface::class);
        $extbaseConfigurationManager->method('getConfiguration')->willReturn($typoScriptSettings);

        $formConfigurationManager = $this->createStub(ExtFormConfigurationManagerInterface::class);
        if (method_exists($formConfigurationManager, 'getYamlConfiguration')) {
            $formConfigurationManager->method('getYamlConfiguration')->willReturn([]);
        }

        return new TestableFormDefinitionService(
            $persistenceManager ?? $this->createStub(FormPersistenceManagerInterface::class),
            $extbaseConfigurationManager,
            $formConfigurationManager,
        );
    }
}
