<?php

namespace Koertho\AdvancedRepeatingEventsBundle\Controller\FrontendModule;

use Contao\Calendar;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\Date;
use Contao\Environment;
use Contao\Events;
use Contao\Input;
use Contao\ModuleEventReader;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\Template;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @property Template $Template
 */
#[AsFrontendModule(type: self::TYPE, category: 'events', template: 'mod_eventreader')]
class AreEventReaderController extends ModuleEventReader
{
    public const string TYPE = 'are_event_reader';

    private ?Template $templateCache = null;

    public function __construct(
        private readonly RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ModuleModel $model, string $section): Response
    {
        parent::__construct($model, $section);

        return new Response($this->generate());
    }

    #[\Override]
    protected function compile()
    {
        $eventModel = CalendarEventsModel::findPublishedByParentAndIdOrAlias(
            Input::get('auto_item', blnKeepUnusedRouteParameter: true),
            $this->cal_calendar
        );
        // The event does not exist (see #33)
        if (null === $eventModel) {
            throw new PageNotFoundException('Page not found: '.Environment::get('uri'));
        }

        if (!$eventModel->areRecurring) {
            parent::compile();

            return;
        }

        $eventModel->recurring = false;

        $cssClasses = $eventModel->cssClass;
        $this->templateCache = null;
        $GLOBALS['TL_HOOKS']['parseTemplate']['are_reader'] = [self::class, 'parseTemplate'];
        parent::compile();
        unset($GLOBALS['TL_HOOKS']['parseTemplate']['are_reader']);

        $template = $this->templateCache;
        $this->templateCache = null;
        $eventModel->cssClass = $cssClasses;

        $this->applyRecurrence($eventModel, $template, $this->objModel);

        $this->Template->wrapperAttributes = new HtmlAttributes()
            ->addClass('mod_eventreader')
            ->mergeWith($this->Template->wrapperAttributes);
    }

    public function parseTemplate(Template $template): void
    {
        if (!str_starts_with($template->getName(), 'event_full')) {
            return;
        }

        $this->templateCache = $template;
    }

    private function applyRecurrence(CalendarEventsModel $objEvent, Template $template, ModuleModel $model): void
    {
        $calculator = $this->recurrenceCalculatorFactory->createForEvent($objEvent);
        $occurrence = $calculator?->resolveCurrentOrUpcomingOccurrence(time(), $model->cal_hideRunning);
        if (null === $occurrence) {
            return;
        }

        $span = Calendar::calculateSpan($objEvent->startTime, $objEvent->endTime);
        ['start' => $intStartTime, 'end' => $intEndTime] = $occurrence;

        // Mark past and upcoming events (see #187)
        if ($intEndTime < strtotime('00:00:00')) {
            $objEvent->cssClass .= ' bygone';
        } elseif ($intStartTime > strtotime('23:59:59')) {
            $objEvent->cssClass .= ' upcoming';
        } else {
            $objEvent->cssClass .= ' current';
        }

        global $objPage;
        [$strDate, $strTime] = $this->getDateAndTime($objEvent, $objPage, $intStartTime, $intEndTime, $span);

        $template->date = $strDate;
        $template->time = $strTime;
        $template->datetime = $objEvent->addTime ? date('Y-m-d\TH:i:sP', $intStartTime) : date('Y-m-d', $intStartTime);
        $template->begin = $intStartTime;
        $template->end = $intEndTime;
        $template->class = $objEvent->cssClass ? ' '.trim($objEvent->cssClass) : '';

        $template->recurring = $this->translator->trans(
            'MSC.cal_repeat',
            [
                $calculator->toText(), // Recurrence pattern
                '', // until -> provided already by toText()
                date('Y-m-d\TH:i:sP', $intStartTime),
                $strDate.($strTime ? ' '.$strTime : ''),
            ],
            'contao_default'
        );

        // Add a function to retrieve upcoming dates (see #175)
        $template->getUpcomingDates = function ($count) use ($calculator, $objEvent, $intStartTime, $objPage, $span) {
            $recurrences = $calculator->listOccurrencesInRange(
                rangeStart: \DateTimeImmutable::createFromTimestamp($intStartTime),
                limit: $count,
            );

            $dates = [];
            foreach ($recurrences as $recurrence) {
                ['start' => $startTime, 'end' => $endTime] = $recurrence;
                [$strDate, $strTime] = $this->getDateAndTime($objEvent, $objPage, $startTime, $endTime, $span);
                $dates[] = [
                    'date' => $strDate,
                    'time' => $strTime,
                    'datetime' => $objEvent->addTime ? date('Ya-m-d\TH:i:sP', $startTime) : date('Y-m-d', $endTime),
                    'begin' => $startTime,
                    'end' => $endTime,
                ];
            }

            return $dates;
        };

        // Add a function to retrieve past dates (see #175)
        $template->getPastDates = function ($count) use ($calculator, $objEvent, $intStartTime, $objPage, $span) {
            $recurrences = $calculator->listOccurrencesInRange(
                rangeEnd: \DateTimeImmutable::createFromTimestamp($intStartTime),
                limit: $count,
            );

            $dates = [];
            foreach ($recurrences as $recurrence) {
                ['start' => $startTime, 'end' => $endTime] = $recurrence;
                [$strDate, $strTime] = $this->getDateAndTime($objEvent, $objPage, $startTime, $endTime, $span);
                $dates[] = [
                    'date' => $strDate,
                    'time' => $strTime,
                    'datetime' => $objEvent->addTime ? date('Ya-m-d\TH:i:sP', $startTime) : date('Y-m-d', $endTime),
                    'begin' => $startTime,
                    'end' => $endTime,
                ];
            }

            return $dates;
        };
        // schema.org information
        $template->getSchemaOrgData = static function () use ($objEvent, $template, $calculator): array {
            $jsonLd = Events::getSchemaOrgData($objEvent);

            if ($template->addImage && $template->figure) {
                $jsonLd['image'] = $template->figure->getSchemaOrgData();
            }

            $jsonLd['eventSchedule'] = $calculator->toSchemaOrgData();

            return $jsonLd;
        };

        $this->Template->event = $template->parse();
    }

    private function getDateAndTime(CalendarEventsModel $objEvent, PageModel $objPage, $intStartTime, $intEndTime, $span)
    {
        $strDate = Date::parse($objPage->dateFormat, $intStartTime);

        if ($span > 0) {
            $strDate = Date::parse($objPage->dateFormat, $intStartTime).$GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'].Date::parse($objPage->dateFormat, $intEndTime);
        }

        $strTime = '';

        if ($objEvent->addTime) {
            if ($span > 0) {
                $strDate = Date::parse($objPage->datimFormat, $intStartTime).$GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'].Date::parse($objPage->datimFormat, $intEndTime);
            } elseif ($intStartTime == $intEndTime) {
                $strTime = Date::parse($objPage->timeFormat, $intStartTime);
            } else {
                $strTime = Date::parse($objPage->timeFormat, $intStartTime).$GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'].Date::parse($objPage->timeFormat, $intEndTime);
            }
        }

        return [$strDate, $strTime];
    }
}
