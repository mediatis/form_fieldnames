<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * Where a proposed field name was derived from.
 */
enum NameSource: string
{
    /**
     * The element label was used.
     */
    case Label = 'label';

    /**
     * The element label was a language file reference and was resolved
     * against the default language.
     */
    case LabelReference = 'labelReference';

    /**
     * The element had no usable label, so its identifier was used.
     */
    case Identifier = 'identifier';
}
