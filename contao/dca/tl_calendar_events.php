<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$dca = &$GLOBALS['TL_DCA']['tl_calendar_events'];

$dca['palettes']['__selector__'][] = 'areRecurring';
$dca['subpalettes']['areRecurring'] = 'repeatEachValue,repeatEachUnit,repeatPattern,recurrences';

PaletteManipulator::create()
    ->addField('areRecurring', 'recurring_legend', PaletteManipulator::POSITION_APPEND)
    ->removeField('recurring')
    ->applyToPalette('default', 'tl_calendar_events');

$dca['fields']['areRecurring'] = [
    'inputType' => 'checkbox',
    'eval' => ['submitOnChange' => true],
    'sql' => ['type' => 'boolean', 'default' => false]
];

$dca['fields']['repeatEachValue'] = [
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'rgxp' => 'natural', 'minval' => 1],
    'sql' => "smallint(5) unsigned NOT NULL default '1'",
];
$dca['fields']['repeatEachUnit'] = [
    'inputType' => 'select',
    'eval' => ['tl_class' => 'w50', 'submitOnChange' => true,],
    'options' => ['days', 'weeks', 'months', 'years'],
    'reference' => &$GLOBALS['TL_LANG']['tl_calendar_events'],
    'sql' => "varchar(10) NOT NULL default ''",
];

$dca['fields']['repeatPattern'] = [
    'exclude' => true,
    'inputType' => 'radio',
    'eval' => ['tl_class' => 'w100 clr'],
    'sql' => 'blob NULL',
];