# Form Field Names

Adds a required, form-unique **Name** field to every element of a TYPO3 form.

## Why

A form element already has a label, but a label is a poor identifier: it is written for
humans, it gets translated, and it changes whenever someone rewords the form. Anything
that consumes submitted data — a CRM mapping, an analytics event, a custom finisher —
needs something that stays put.

This extension adds exactly that: a short, stable name per element, separate from the
label and never translated.

## Requirements

- TYPO3 12.4, 13.4 or 14.x
- PHP 8.2 or newer

## Installation

```bash
composer require mediatis/form_fieldnames
```

## What it adds

A **Name** field appears in the form editor inspector for every element that can hold
a value: Text, Textarea, Email, Telephone, Url, Number, Date, DatePicker, Password,
AdvancedPassword, Hidden, Checkbox, MultiCheckbox, RadioButton, SingleSelect,
MultiSelect, Countryselect, FileUpload and ImageUpload.

Two validations run in the form editor:

- **NotEmpty** — every element must have a name
- **Unique** — no two elements in the same form may share one

The value is stored in the form definition under
`properties.fluidAdditionalAttributes.name`, so it travels with the form and is
available wherever the definition is read.

Existing forms will show a validation error on every element until the names are
filled in. That is intentional: the names are supposed to be chosen, not guessed.

## Filling in existing forms

Choosing a name for every element of every form by hand does not scale when a project
adopts this extension. The `form:fieldnames` command derives a starting point from the
labels and reports what is still open.

### Reporting

```bash
vendor/bin/typo3 form:fieldnames
```

Reporting is the default, so this changes nothing. It lists every element without a
name together with the name that would be generated for it, and it flags problems that
cannot be resolved automatically — forms in read-only storage, unparsable definitions,
names that were set by hand but collide with each other, and form identifiers used by
more than one form.

Because it never writes, it is safe to run at any time to see where an installation
stands. It always exits successfully, though — it is a report to read, not a check to
automate.

Restrict it to a single form with `--form`:

```bash
vendor/bin/typo3 form:fieldnames --form='1:/form_definitions/contact.form.yaml'
```

### Writing

```bash
vendor/bin/typo3 form:fieldnames --apply
```

This writes the proposed names into the form definitions and reports what it changed.
Without `--form` it asks for confirmation first, because it writes to every form in the
installation; `--no-interaction` skips the question.

Two guarantees hold: **an existing name is never overwritten**, and running the command
twice changes nothing the second time. Review the generated names afterwards — they are
a starting point derived from labels, not a substitute for deciding what a field should
be called.

Flush the frontend caches afterwards so pages embedding the changed forms are rebuilt.

### How names are derived

The label is the primary source, reduced to `snake_case`:

| Label | Name |
|-------|------|
| `Vorname` | `vorname` |
| `Nachname (Straße)` | `nachname_strasse` |
| `E-Mail-Adresse` | `e_mail_adresse` |

Transliteration uses the same converter TYPO3 uses for page slugs, so German umlauts
become the expected letter pairs — `Straße` turns into `strasse`, not `strae`.

Labels that are language file references (`LLL:…`) are resolved against the **default**
language, so the generated name does not depend on which translations are installed.
If a label is empty, or the reference cannot be resolved, the element identifier is used
instead.

When a name is already taken within the same form — whether by another generated name or
by one set by hand — a counter is appended, starting at `2`. A hand-written `email2`
therefore pushes the generated one to `email3`.

## Development

The package uses the Mediatis code-quality stack:

```bash
composer ci        # all checks
composer ci:static # style, types and linting only
composer fix       # apply rector and php-cs-fixer
```

Unit tests need neither a database nor a TYPO3 instance:

```bash
composer ci:tests:unit
```

A DDEV configuration is included for running the suite in a container:

```bash
ddev start
ddev composer ci
```

DDEV runs `composer install` on start.

## License

GPL-2.0-or-later
