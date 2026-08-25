<?php

declare(strict_types=1);

namespace Mediatis\FormFieldnames\Dto;

/**
 * The state of a single form element with regard to its field name.
 */
enum FieldStatus: string
{
    /**
     * The element has a name and no other element in the form uses it.
     */
    case Ok = 'ok';

    /**
     * The element has no name yet. A migration will fill it.
     */
    case Missing = 'missing';

    /**
     * The element has a name, but another element in the same form uses the
     * same one. A migration cannot resolve this, because both names were set
     * deliberately.
     */
    case Duplicate = 'duplicate';
}
