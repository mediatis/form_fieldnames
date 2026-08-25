<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Service;

/**
 * Turns a form element label into plain text.
 *
 * Labels can be language file references, which have to be resolved before a
 * field name can be derived from them.
 */
interface LabelResolverInterface
{
    /**
     * Returns the resolved label, or an empty string if it cannot be resolved.
     */
    public function resolve(string $label): string;
}
