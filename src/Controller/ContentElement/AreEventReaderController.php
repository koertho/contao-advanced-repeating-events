<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Controller\ContentElement;

use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\Controller;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\CoreBundle\Util\UrlUtil;
use Contao\Date;
use Contao\Environment;
use Contao\Input;
use Contao\StringUtil;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsContentElement(type: 'are_event_reader', category: 'includes', template: 'content_element/are_event_reader')]
final class AreEventReaderController extends AbstractContentElementController
{
    public function __construct(
        private readonly ContentUrlGenerator         $contentUrlGenerator,
        private readonly ResponseContextAccessor     $responseContextAccessor,
        private readonly HtmlDecoder                 $htmlDecoder,
        private readonly InsertTagParser             $insertTagParser,
        private readonly RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
    ) {}

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        if ('backend' === $request->attributes->get('_scope')) {
            $template->set('is_backend', true);
            $template->set('wildcard', 'ARE Event Leser');

            return $template->getResponse();
        }

        $objEvent = $this->getEvent($model);
        $this->redirectIfApplicable($objEvent);
        $this->setPageMeta($objEvent, $request);

        [$startTs, $endTs, $isOccurrence] = $this->resolveOccurrence($objEvent);

        $template->set('event', $this->buildViewData($objEvent, $startTs, $endTs, $isOccurrence));
        $template->set('content_elements', $this->renderEventContentElements((int)$objEvent->id));

        return $template->getResponse();
    }

    private function getEvent(ContentModel $model): CalendarEventsModel
    {
        $calendarIds = array_values(
            array_filter(
                array_map(static fn(mixed $value): int => (int)$value, StringUtil::deserialize($model->areReaderCalendars, true)),
                static fn(int $id): bool => $id > 0
            )
        );
        $identifier = trim((string)Input::get('auto_item'));

        $objEvent = CalendarEventsModel::findPublishedByParentAndIdOrAlias($identifier, $calendarIds);

        // The event does not exist (see #33)
        if ($objEvent === null) {
            throw new PageNotFoundException('Page not found: ' . Environment::get('uri'));
        }
        return $objEvent;
    }

    private function redirectIfApplicable(CalendarEventsModel $eventModel): void
    {
        switch ($eventModel->source) {
            case 'internal':
            case 'article':
            case 'external':
                throw new RedirectResponseException(
                    $this->contentUrlGenerator->generate($eventModel, array(), UrlGeneratorInterface::ABSOLUTE_URL),
                    301
                );
        }
    }

    private function setPageMeta(CalendarEventsModel $eventModel, Request $request): void
    {
        // Overwrite the page metadata (see #2853, #4955 and #87)
        $responseContext = $this->responseContextAccessor->getResponseContext();

        if ($responseContext?->has(HtmlHeadBag::class)) {
            $htmlHeadBag = $responseContext->get(HtmlHeadBag::class);

            if ($eventModel->pageTitle) {
                $htmlHeadBag->setTitle($eventModel->pageTitle); // Already stored decoded
            } elseif ($eventModel->title) {
                $htmlHeadBag->setTitle($this->htmlDecoder->inputEncodedToPlainText($eventModel->title));
            }

            if ($eventModel->description) {
                $htmlHeadBag->setMetaDescription($this->htmlDecoder->inputEncodedToPlainText($eventModel->description));
            } elseif ($eventModel->teaser) {
                $htmlHeadBag->setMetaDescription($this->htmlDecoder->htmlToPlainText($eventModel->teaser));
            }

            if ($eventModel->robots) {
                $htmlHeadBag->setMetaRobots($eventModel->robots);
            }

            if ($eventModel->canonicalLink) {
                $url = $this->insertTagParser->replaceInline($eventModel->canonicalLink);

                // Ensure absolute links
                if (!preg_match('#^https?://#', $url)) {
                    $url = UrlUtil::makeAbsolute($url, $request->getUri());
                }

                $htmlHeadBag->setCanonicalUri($url);
            }
//            elseif (!$this->cal_keepCanonical) {
//                $htmlHeadBag->setCanonicalUri($this->contentUrlGenerator->generate($eventModel, array(), UrlGeneratorInterface::ABSOLUTE_URL));
//            }
        }
    }


    private function renderEventContentElements(int $eventId): string
    {
        $elements = ContentModel::findPublishedByPidAndTable($eventId, 'tl_calendar_events');

        if (null === $elements) {
            return '';
        }

        $buffer = '';

        while ($elements->next()) {
            $buffer .= Controller::getContentElement((int)$elements->id);
        }

        return $buffer;
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function resolveOccurrence(CalendarEventsModel $event): array
    {
        $startTs = (int)$event->startTime;
        $endTs = (int)$event->endTime;
        $calculator = $this->recurrenceCalculatorFactory->createForEvent($event);

        if (null === $calculator) {
            return [$startTs, $endTs, false];
        }

        $occurrence = $calculator->resolveCurrentOrUpcomingOccurrence(time(), false);

        if (null === $occurrence) {
            return [$startTs, $endTs, false];
        }

        $occurrenceStartTs = $occurrence['start'];
        $occurrenceEndTs = $occurrence['end'];
        $isOccurrence = $occurrenceStartTs !== $startTs || $occurrenceEndTs !== $endTs;

        return [$occurrenceStartTs, $occurrenceEndTs, $isOccurrence];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(CalendarEventsModel $event, int $startTs, int $endTs, bool $isOccurrence): array
    {
        $dateFormat = (string)Config::get('dateFormat');
        $timeFormat = (string)Config::get('timeFormat');
        $datimFormat = (string)Config::get('datimFormat');
        $showTime = (bool)$event->addTime;

        return [
            'id' => (int)$event->id,
            'title' => (string)$event->title,
            'location' => (string)($event->location ?? ''),
            'teaser' => (string)($event->teaser ?? ''),
            'details' => (string)($event->details ?? ''),
            'isOccurrence' => $isOccurrence,
            'showTime' => $showTime,
            'dateLabel' => Date::parse($showTime ? $datimFormat : $dateFormat, $startTs),
            'timeLabel' => $showTime ? Date::parse($timeFormat, $startTs) : null,
            'startIso' => date(DATE_ATOM, $startTs),
            'endIso' => date(DATE_ATOM, $endTs),
            'startTs' => $startTs,
            'endTs' => $endTs,
        ];
    }

}
