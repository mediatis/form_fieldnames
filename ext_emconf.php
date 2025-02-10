<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Form Field Names',
    'description' => 'Extends ext:form by adding the field "name" to all form fields.',
    'category' => 'be',
    'author_email' => 'info@mediatis.de',
    'author_company' => 'Mediatis AG',
    'state' => 'stable',
    'version' => '4.1.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
