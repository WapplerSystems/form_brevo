<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Form Brevo',
    'description' => 'Form finisher that adds or updates Brevo contacts via the API, optionally with double opt-in. Only composer!',
    'category' => 'misc',
    'state' => 'beta',
    'author' => 'Sven Wappler',
    'author_email' => 'typo3YYYY@wappler.systems',
    'author_company' => 'WapplerSystems',
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.4.99',
            'form' => '14.0.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
