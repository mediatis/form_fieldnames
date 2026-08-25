<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Resolves "LLL:" labels against the default language.
 *
 * The default language is the source language of the XLF file, so the generated
 * field names do not depend on which translations happen to be installed - which
 * is the whole point of having a name next to the translatable label.
 */
class LanguageFileLabelResolver implements LabelResolverInterface
{
    private ?LanguageService $languageService = null;

    public function __construct(
        protected readonly LanguageServiceFactory $languageServiceFactory,
    ) {
    }

    public function resolve(string $label): string
    {
        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }

        $resolved = $this->getLanguageService()->sL($label);

        // An unknown file or key comes back as an empty string or unchanged.
        return str_starts_with($resolved, 'LLL:') ? '' : $resolved;
    }

    protected function getLanguageService(): LanguageService
    {
        if (!$this->languageService instanceof LanguageService) {
            $this->languageService = $this->languageServiceFactory->create('default');
        }

        return $this->languageService;
    }
}
