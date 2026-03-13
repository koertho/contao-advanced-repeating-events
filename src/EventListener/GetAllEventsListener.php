<?php

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Module;

#[AsHook('getAllEvents')]
class GetAllEventsListener
{
    public function __invoke(array $events, array $calendars, int $timeStart, int $timeEnd, Module $module): array
    {
        return $events;
    }
}
