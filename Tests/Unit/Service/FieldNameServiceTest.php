<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Tests\Unit\Service;

use Mediatis\FormFieldnames\Dto\FieldStatus;
use Mediatis\FormFieldnames\Dto\FormSummary;
use Mediatis\FormFieldnames\Service\FieldNameService;
use Mediatis\FormFieldnames\Service\FormDefinitionService;
use Mediatis\FormFieldnames\Tests\Unit\Fixtures\CreatesFieldNameGeneratorTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Form\Domain\Configuration\ConfigurationService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FieldNameServiceTest extends UnitTestCase
{
    use CreatesFieldNameGeneratorTrait;

    private const PERSISTENCE_IDENTIFIER = '1:/form_definitions/test.form.yaml';

    /**
     * Text and Email offer a field name editor, Textarea and the container types do not.
     *
     * @var array<string,mixed>
     */
    private const PROTOTYPE = [
        'formElementsDefinition' => [
            'Page' => ['formEditor' => ['editors' => [100 => ['propertyPath' => 'label']]]],
            'Fieldset' => ['formEditor' => ['editors' => [100 => ['propertyPath' => 'label']]]],
            'Text' => ['formEditor' => ['editors' => [
                100 => ['propertyPath' => 'label'],
                207 => ['identifier' => 'name', 'propertyPath' => 'properties.fluidAdditionalAttributes.name'],
            ]]],
            'Email' => ['formEditor' => ['editors' => [
                100 => ['propertyPath' => 'label'],
                207 => ['identifier' => 'name', 'propertyPath' => 'properties.fluidAdditionalAttributes.name'],
            ]]],
            'Textarea' => ['formEditor' => ['editors' => [100 => ['propertyPath' => 'label']]]],
        ],
    ];

    #[Test]
    public function onlyElementTypesWithAFieldNameEditorAreCollected(): void
    {
        $subject = $this->createSubject($this->stubFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname'),
            $this->element('Textarea', 'textarea-1', 'Nachricht'),
            $this->element('Email', 'email-1', 'E-Mail'),
        ])));

        $analysis = $subject->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame(['text-1', 'email-1'], array_map(
            static fn ($field): string => $field->elementIdentifier,
            $analysis->fields
        ));
    }

    #[Test]
    public function nestedElementsAreCollected(): void
    {
        $definition = $this->buildForm([
            $this->element('Text', 'text-1', 'Vorname'),
        ]);
        $definition['renderables'][] = [
            'type' => 'Page',
            'identifier' => 'page-2',
            'label' => 'Step 2',
            'renderables' => [[
                'type' => 'Fieldset',
                'identifier' => 'fieldset-1',
                'label' => 'Erreichbarkeit',
                'renderables' => [$this->element('Text', 'text-2', 'Telefon')],
            ]],
        ];

        $analysis = $this->createSubject($this->stubFormDefinitionService($definition))
            ->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame(['text-1', 'text-2'], array_map(
            static fn ($field): string => $field->elementIdentifier,
            $analysis->fields
        ));
        self::assertSame('telefon', $analysis->fields[1]->proposedName);
    }

    #[Test]
    public function elementWithANameIsReportedAsOk(): void
    {
        $analysis = $this->createSubject($this->stubFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname', 'first_name'),
        ])))->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame(FieldStatus::Ok, $analysis->fields[0]->status);
        self::assertNull($analysis->fields[0]->proposedName);
        self::assertFalse($analysis->hasProposals());
    }

    #[Test]
    public function duplicateNamesAreReportedWithEveryElementInvolved(): void
    {
        $analysis = $this->createSubject($this->stubFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Doppelt A', 'kollision'),
            $this->element('Text', 'text-2', 'Doppelt B', 'kollision'),
        ])))->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame(FieldStatus::Duplicate, $analysis->fields[0]->status);
        self::assertSame(FieldStatus::Duplicate, $analysis->fields[1]->status);
        self::assertCount(1, $analysis->problems);
        self::assertStringContainsString('text-1, text-2', $analysis->problems[0]);
    }

    #[Test]
    public function generatedNamesAvoidNamesThatWereSetByHand(): void
    {
        $analysis = $this->createSubject($this->stubFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname'),
            $this->element('Text', 'text-2', 'Reserviert', 'vorname2'),
            $this->element('Text', 'text-3', 'Vorname'),
        ])))->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame('vorname', $analysis->fields[0]->proposedName);
        self::assertSame('vorname3', $analysis->fields[2]->proposedName);
    }

    #[Test]
    public function generatedNamesAvoidEachOtherInDocumentOrder(): void
    {
        $analysis = $this->createSubject($this->stubFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname'),
            $this->element('Text', 'text-2', 'Vorname'),
            $this->element('Text', 'text-3', 'Vorname'),
        ])))->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame(
            ['vorname', 'vorname2', 'vorname3'],
            array_map(static fn ($field): ?string => $field->proposedName, $analysis->fields)
        );
    }

    #[Test]
    public function readOnlyStorageIsOnlyReportedWhenThereIsSomethingToWrite(): void
    {
        $withOpenNames = $this->createSubject($this->stubFormDefinitionService(
            $this->buildForm([$this->element('Text', 'text-1', 'Vorname')]),
            $this->summary(readOnly: true)
        ))->analyse(self::PERSISTENCE_IDENTIFIER);

        $complete = $this->createSubject($this->stubFormDefinitionService(
            $this->buildForm([$this->element('Text', 'text-1', 'Vorname', 'vorname')]),
            $this->summary(readOnly: true)
        ))->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($withOpenNames);
        self::assertNotNull($complete);
        self::assertCount(1, $withOpenNames->problems);
        self::assertStringContainsString('read-only', $withOpenNames->problems[0]);
        self::assertSame([], $complete->problems);
    }

    #[Test]
    public function invalidFormIsReportedWithoutBeingLoaded(): void
    {
        $formDefinitionService = $this->createMock(FormDefinitionService::class);
        $formDefinitionService->method('listForms')->willReturn([$this->summary(invalid: true)]);
        $formDefinitionService->expects(self::never())->method('load');

        $analysis = $this->createSubject($formDefinitionService)->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame([], $analysis->fields);
        self::assertStringContainsString('invalid', $analysis->problems[0]);
    }

    #[Test]
    public function unloadableFormIsReported(): void
    {
        $formDefinitionService = $this->createStub(FormDefinitionService::class);
        $formDefinitionService->method('listForms')->willReturn([$this->summary()]);
        $formDefinitionService->method('load')->willReturn(null);

        $analysis = $this->createSubject($formDefinitionService)->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame([], $analysis->fields);
        self::assertStringContainsString('could not be loaded', $analysis->problems[0]);
    }

    #[Test]
    public function prototypeWithoutAFieldNameEditorIsReported(): void
    {
        $subject = $this->createSubject(
            $this->stubFormDefinitionService($this->buildForm([$this->element('Text', 'text-1', 'Vorname')])),
            ['formElementsDefinition' => ['Text' => ['formEditor' => ['editors' => []]]]]
        );

        $analysis = $subject->analyse(self::PERSISTENCE_IDENTIFIER);

        self::assertNotNull($analysis);
        self::assertSame([], $analysis->fields);
        self::assertStringContainsString('field name editor', $analysis->problems[0]);
    }

    #[Test]
    public function unknownFormIsNotAnalysed(): void
    {
        $formDefinitionService = $this->createStub(FormDefinitionService::class);
        $formDefinitionService->method('listForms')->willReturn([]);

        self::assertNull($this->createSubject($formDefinitionService)->analyse('1:/nope.form.yaml'));
    }

    #[Test]
    public function migrationWritesOnlyTheMissingNames(): void
    {
        $formDefinitionService = $this->mockFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname'),
            $this->element('Text', 'text-2', 'Nachname', 'last_name'),
        ]));

        $saved = null;
        $formDefinitionService->expects(self::once())->method('save')->willReturnCallback(
            static function (string $persistenceIdentifier, array $formDefinition) use (&$saved): void {
                $saved = $formDefinition;
            }
        );

        $result = $this->createSubject($formDefinitionService)->migrate(self::PERSISTENCE_IDENTIFIER);

        self::assertTrue($result->saved);
        self::assertSame(['text-1' => 'vorname'], $result->appliedNames);
        self::assertIsArray($saved);
        $elements = $saved['renderables'][0]['renderables'];
        self::assertSame('vorname', $elements[0]['properties']['fluidAdditionalAttributes']['name']);
        self::assertSame('last_name', $elements[1]['properties']['fluidAdditionalAttributes']['name']);
    }

    #[Test]
    public function migrationOfACompleteFormChangesNothing(): void
    {
        $formDefinitionService = $this->mockFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname', 'vorname'),
        ]));
        $formDefinitionService->expects(self::never())->method('save');

        $result = $this->createSubject($formDefinitionService)->migrate(self::PERSISTENCE_IDENTIFIER);

        self::assertFalse($result->saved);
        self::assertSame([], $result->appliedNames);
        self::assertNull($result->skippedReason);
    }

    #[Test]
    public function migrationIsSkippedForReadOnlyStorage(): void
    {
        $formDefinitionService = $this->mockFormDefinitionService(
            $this->buildForm([$this->element('Text', 'text-1', 'Vorname')]),
            $this->summary(readOnly: true)
        );
        $formDefinitionService->expects(self::never())->method('save');

        $result = $this->createSubject($formDefinitionService)->migrate(self::PERSISTENCE_IDENTIFIER);

        self::assertFalse($result->saved);
        self::assertSame('Form is read-only.', $result->skippedReason);
    }

    #[Test]
    public function migrationIsIdempotent(): void
    {
        $formDefinitionService = $this->mockFormDefinitionService($this->buildForm([
            $this->element('Text', 'text-1', 'Vorname'),
            $this->element('Text', 'text-2', 'Vorname'),
        ]));

        $saved = null;
        $formDefinitionService->expects(self::once())->method('save')->willReturnCallback(
            static function (string $persistenceIdentifier, array $formDefinition) use (&$saved): void {
                $saved = $formDefinition;
            }
        );
        $this->createSubject($formDefinitionService)->migrate(self::PERSISTENCE_IDENTIFIER);
        self::assertIsArray($saved);

        // Feed the written definition back in: nothing is left to do.
        $second = $this->mockFormDefinitionService($saved);
        $second->expects(self::never())->method('save');
        $result = $this->createSubject($second)->migrate(self::PERSISTENCE_IDENTIFIER);

        self::assertFalse($result->saved);
        self::assertSame([], $result->appliedNames);
    }

    #[Test]
    public function migrationOfAnUnknownFormIsSkipped(): void
    {
        $formDefinitionService = $this->createMock(FormDefinitionService::class);
        $formDefinitionService->method('listForms')->willReturn([]);
        $formDefinitionService->expects(self::never())->method('save');

        $result = $this->createSubject($formDefinitionService)->migrate('1:/nope.form.yaml');

        self::assertSame('Form not found.', $result->skippedReason);
    }

    /**
     * @param array<string,mixed> $prototype
     */
    private function createSubject(
        FormDefinitionService $formDefinitionService,
        array $prototype = self::PROTOTYPE,
    ): FieldNameService {
        $configurationService = $this->createStub(ConfigurationService::class);
        $configurationService->method('getPrototypeConfiguration')->willReturn($prototype);

        return new FieldNameService($formDefinitionService, $this->createFieldNameGenerator(), $configurationService);
    }

    /**
     * @param array<string,mixed> $formDefinition
     *
     * @return FormDefinitionService&Stub
     */
    private function stubFormDefinitionService(array $formDefinition, ?FormSummary $summary = null): Stub
    {
        $formDefinitionService = $this->createStub(FormDefinitionService::class);
        $formDefinitionService->method('listForms')->willReturn([$summary ?? $this->summary()]);
        $formDefinitionService->method('load')->willReturn($formDefinition);

        return $formDefinitionService;
    }

    /**
     * @param array<string,mixed> $formDefinition
     *
     * @return FormDefinitionService&MockObject
     */
    private function mockFormDefinitionService(array $formDefinition, ?FormSummary $summary = null): MockObject
    {
        $formDefinitionService = $this->createMock(FormDefinitionService::class);
        $formDefinitionService->method('listForms')->willReturn([$summary ?? $this->summary()]);
        $formDefinitionService->method('load')->willReturn($formDefinition);

        return $formDefinitionService;
    }

    private function summary(bool $readOnly = false, bool $invalid = false): FormSummary
    {
        return new FormSummary(
            self::PERSISTENCE_IDENTIFIER,
            'test',
            'Test Form',
            '1:/form_definitions/',
            $readOnly,
            $invalid,
            false,
        );
    }

    /**
     * @param list<array<string,mixed>> $elements
     *
     * @return array<string,mixed>
     */
    private function buildForm(array $elements): array
    {
        return [
            'identifier' => 'test',
            'type' => 'Form',
            'prototypeName' => 'standard',
            'renderables' => [[
                'type' => 'Page',
                'identifier' => 'page-1',
                'label' => 'Step 1',
                'renderables' => $elements,
            ]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function element(string $type, string $identifier, string $label, ?string $name = null): array
    {
        $element = ['type' => $type, 'identifier' => $identifier, 'label' => $label];
        if ($name !== null) {
            $element['properties']['fluidAdditionalAttributes']['name'] = $name;
        }

        return $element;
    }
}
