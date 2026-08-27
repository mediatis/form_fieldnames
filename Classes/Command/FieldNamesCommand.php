<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Command;

use Mediatis\FormFieldnames\Dto\FieldStatus;
use Mediatis\FormFieldnames\Dto\FormAnalysis;
use Mediatis\FormFieldnames\Dto\FormMigrationResult;
use Mediatis\FormFieldnames\Service\FieldNameService;
use Mediatis\FormFieldnames\Utility\BackendRequestContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Reports form elements without a field name and fills them in on request.
 *
 * Reporting is the default. Writing needs --apply, because the names are meant
 * to be reviewed: they are a starting point derived from the labels, not a
 * replacement for deciding what a field should be called.
 */
class FieldNamesCommand extends Command
{
    public function __construct(
        protected readonly FieldNameService $fieldNameService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Lists all form elements that have no field name yet, together with the name that '
                . 'would be generated from their label. With --apply those names are written to the '
                . 'form definitions. Existing names are never changed.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Write the proposed names to the form definitions'
            )
            ->addOption(
                'form',
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict the run to a single form (persistence identifier)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Form definitions live in file storages, so the storage permission
        // aspect needs an authenticated backend user before anything is written.
        Bootstrap::initializeBackendAuthentication();

        return BackendRequestContext::ensure(fn (): int => $this->process($input, $output));
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $persistenceIdentifier = $input->getOption('form');
        $persistenceIdentifier = is_string($persistenceIdentifier) ? $persistenceIdentifier : null;

        if ($input->getOption('apply') === true) {
            return $this->apply($io, $persistenceIdentifier, $input->isInteractive());
        }

        return $this->report($io, $persistenceIdentifier, $output->isVerbose());
    }

    protected function report(SymfonyStyle $io, ?string $persistenceIdentifier, bool $verbose): int
    {
        $analyses = $this->collectAnalyses($io, $persistenceIdentifier);
        if ($analyses === null) {
            return Command::FAILURE;
        }

        $missing = 0;
        $untouched = 0;
        foreach ($analyses as $analysis) {
            if (!$analysis->needsAttention()) {
                ++$untouched;
                if ($verbose) {
                    $io->writeln(sprintf('<info>OK</info> %s', $analysis->form->persistenceIdentifier));
                }

                continue;
            }

            $missing += count($analysis->getProposals());
            $this->renderAnalysis($io, $analysis);
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%d form(s) checked, %d without open field names, %d name(s) would be generated.',
            count($analyses),
            $untouched,
            $missing
        ));

        if ($missing > 0) {
            $io->writeln('Run the command again with --apply to write them.');
        }

        return Command::SUCCESS;
    }

    protected function apply(SymfonyStyle $io, ?string $persistenceIdentifier, bool $interactive): int
    {
        if ($persistenceIdentifier === null && $interactive) {
            $io->warning('This writes field names into every form definition of the installation.');
            if (!$io->confirm('Continue?', false)) {
                $io->writeln('Aborted, nothing was written.');

                return Command::SUCCESS;
            }
        }

        if ($persistenceIdentifier !== null) {
            $result = $this->fieldNameService->migrate($persistenceIdentifier);
            if (!$result instanceof FormMigrationResult) {
                $io->error(sprintf('No form found for "%s".', $persistenceIdentifier));

                return Command::FAILURE;
            }

            $results = [$result];
        } else {
            $results = array_values($this->fieldNameService->migrateAll());
        }

        $written = 0;
        $skipped = 0;
        foreach ($results as $result) {
            $written += count($result->appliedNames);
            if ($result->skippedReason !== null) {
                ++$skipped;
            }

            $this->renderMigrationResult($io, $result);
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%d form(s) processed, %d name(s) written, %d form(s) skipped.',
            count($results),
            $written,
            $skipped
        ));

        if ($written > 0) {
            $io->note('Flush the frontend caches so that pages embedding these forms are rebuilt.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return ?list<FormAnalysis>
     */
    protected function collectAnalyses(SymfonyStyle $io, ?string $persistenceIdentifier): ?array
    {
        if ($persistenceIdentifier === null) {
            return array_values($this->fieldNameService->analyseAll());
        }

        $analysis = $this->fieldNameService->analyse($persistenceIdentifier);
        if (!$analysis instanceof FormAnalysis) {
            $io->error(sprintf('No form found for "%s".', $persistenceIdentifier));

            return null;
        }

        return [$analysis];
    }

    protected function renderAnalysis(SymfonyStyle $io, FormAnalysis $analysis): void
    {
        $io->section(sprintf('%s (%s)', $analysis->form->name, $analysis->form->persistenceIdentifier));

        $rows = [];
        foreach ($analysis->fields as $field) {
            if ($field->status === FieldStatus::Ok) {
                continue;
            }

            $rows[] = [
                $field->elementIdentifier,
                $field->elementType,
                $this->shorten($field->label),
                $field->currentName,
                $field->proposedName ?? '',
                $field->source->value ?? '',
                $field->status->value,
            ];
        }

        if ($rows !== []) {
            $io->table(
                ['Element', 'Type', 'Label', 'Current', 'Proposed', 'Source', 'Status'],
                $rows
            );
        }

        foreach ($analysis->problems as $problem) {
            $io->warning($problem);
        }
    }

    protected function renderMigrationResult(SymfonyStyle $io, FormMigrationResult $result): void
    {
        if ($result->skippedReason === null && $result->appliedNames === [] && $result->problems === []) {
            return;
        }

        $io->section($result->persistenceIdentifier);

        if ($result->skippedReason !== null) {
            $io->writeln('<comment>SKIP</comment> ' . $result->skippedReason);
        }

        if ($result->appliedNames !== []) {
            $rows = [];
            foreach ($result->appliedNames as $elementIdentifier => $name) {
                $rows[] = [$elementIdentifier, $name];
            }

            $io->table(['Element', 'Name'], $rows);
        }

        foreach ($result->problems as $problem) {
            $io->warning($problem);
        }
    }

    protected function shorten(string $value, int $length = 40): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
