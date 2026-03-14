<?php

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener;

use Cgoit\ContaoCalendarIcalBundle\Event\AfterImportItemEvent;
use Contao\CoreBundle\Cache\CacheTagManager;
use Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class CalendarIcalBundleListener
{
    public function __construct(
        private readonly RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
        private readonly CacheTagManager $cacheTagManager,
    ) {
    }

    public function __invoke(AfterImportItemEvent $event): void
    {
        if (!$event->vevent->getRrule()) {
            return;
        }

        $rrule = $event->vevent->createComponent();
        preg_match('/^RRULE:(.+)$/mi', (string) $rrule, $matches);
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
            $model->repeatEnd = 0;
            $model->save();
            $this->cacheTagManager->invalidateTagsForModelInstance($model);
            return;
        }

        $model->areRecurring = true;
        $model->rrule = $rrule;
        $model->repeatEnd = $this->recurrenceCalculatorFactory->createForRawData(
            true,
            $rrule,
            (int) $model->startTime,
            (int) $model->endTime
        )?->resolveRepeatEnd() ?? 0;
        $model->save();
        $this->cacheTagManager->invalidateTagsForModelInstance($model);
    }
}
