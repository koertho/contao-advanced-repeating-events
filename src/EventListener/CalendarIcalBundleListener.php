<?php

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener;

use Cgoit\ContaoCalendarIcalBundle\Event\AfterImportItemEvent;
use Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class CalendarIcalBundleListener
{
    public function __invoke(AfterImportItemEvent $event): void
    {
        if (!$event->vevent->getRrule()) {
            return;
        }

        $rrule = $event->vevent->createComponent();
        preg_match('/^RRULE:(.+)$/mi', $rrule, $matches);
        $rrule = $matches[1] ?? null;
        if (!is_string($rrule)) {
            return;
        }

        /** @var CalendarEventsModel $model */
        $model = $event->calendarEventModel;
        $model->recurring = false;

        try {
            // validate rrule
            new Rule(trim($rrule));
        } catch (InvalidRRule) {
            $model->save();
            return;
        }

        $model->areRecurring = true;
        $model->rrule = $rrule;
        $model->save();
    }
}