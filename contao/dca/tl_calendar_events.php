<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$dca = &$GLOBALS['TL_DCA']['tl_calendar_events'];

PaletteManipulator::create()
    ->addField('repeatPattern', 'recurring_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_calendar_events');

$dca['fields']['repeatPattern'] = [
    'exclude' => true,
    'inputType' => 'select',
    'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
];