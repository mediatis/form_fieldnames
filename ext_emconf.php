<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Form Field Names',
    'description' => 'Extends ext:form by adding the field "name" to all form fields.',
    'category' => 'be',
    'author_email' => 'info@mediatis.de',
    'author_company' => 'Mediatis AG',
    'state' => 'stable',
    'version' => '4.3.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '12.4.0-14.4.99',
            'form' => '12.4.0-14.4.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
