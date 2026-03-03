<?php

declare(strict_types=1);

$dca = &$GLOBALS['TL_DCA']['tl_content'];

$dca['palettes']['are_event_reader'] = '{type_legend},type,headline;{are_event_reader_legend},areReaderCalendars;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID,space';

$dca['fields']['areReaderCalendars'] = [
    'exclude' => true,
    'inputType' => 'checkboxWizard',
    'foreignKey' => 'tl_calendar.title',
    'eval' => [
        'mandatory' => true,
        'multiple' => true,
        'tl_class' => 'clr',
    ],
    'sql' => ['type' => 'blob', 'notnull' => false],
    'relation' => ['type' => 'hasMany', 'load' => 'lazy'],
];
