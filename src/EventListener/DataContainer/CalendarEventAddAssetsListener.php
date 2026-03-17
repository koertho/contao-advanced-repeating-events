<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsCallback(table: 'tl_calendar_events', target: 'config.onload')]
final readonly class CalendarEventAddAssetsListener
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $action = $request?->query->get('act');

        if (!\in_array($action, ['create', 'edit', 'editAll', 'overrideAll'], true)) {
            return;
        }

        $assetPath = 'bundles/koerthoadvancedrepeatingevents/backend';

        $GLOBALS['TL_JAVASCRIPT']['are_be'] = $assetPath.'/rrule-builder.js';
        $GLOBALS['TL_CSS']['are_be'] = $assetPath.'/rrule-builder.css';
    }
}
