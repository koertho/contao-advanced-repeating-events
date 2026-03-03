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
use Contao\System;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsContentElement(type: 'are_event_reader', category: 'includes', template: 'content_element/are_event_reader')]
final class AreEventReaderController extends AbstractContentElementController
{
    public function __construct(
        private readonly ContentUrlGenerator     $contentUrlGenerator,
        private readonly ResponseContextAccessor $responseContextAccessor,
        private readonly HtmlDecoder             $htmlDecoder,
        private readonly InsertTagParser         $insertTagParser,
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

        [$startTs, $endTs, $isOccurrence] = $this->resolveOccurrence($objEvent, $this->resolveRequestedOccurrenceTimestamp($request));

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

    private function redirectIfApplicable(CalendarEventsModel $objEvent): void
    {
        switch ($objEvent->source) {
            case 'internal':
            case 'article':
            case 'external':
                throw new RedirectResponseException(
                    $this->contentUrlGenerator->generate($objEvent, array(), UrlGeneratorInterface::ABSOLUTE_URL),
                    301
                );
        }
    }

    private function setPageMeta(CalendarEventsModel $objEvent, Request $request): void
    {
        // Overwrite the page metadata (see #2853, #4955 and #87)
        $responseContext = $this->responseContextAccessor->getResponseContext();

        if ($responseContext?->has(HtmlHeadBag::class)) {
            $htmlHeadBag = $responseContext->get(HtmlHeadBag::class);

            if ($objEvent->pageTitle) {
                $htmlHeadBag->setTitle($objEvent->pageTitle); // Already stored decoded
            } elseif ($objEvent->title) {
                $htmlHeadBag->setTitle($this->htmlDecoder->inputEncodedToPlainText($objEvent->title));
            }

            if ($objEvent->description) {
                $htmlHeadBag->setMetaDescription($this->htmlDecoder->inputEncodedToPlainText($objEvent->description));
            } elseif ($objEvent->teaser) {
                $htmlHeadBag->setMetaDescription($this->htmlDecoder->htmlToPlainText($objEvent->teaser));
            }

            if ($objEvent->robots) {
                $htmlHeadBag->setMetaRobots($objEvent->robots);
            }

            if ($objEvent->canonicalLink) {
                $url = $this->insertTagParser->replaceInline($objEvent->canonicalLink);

                // Ensure absolute links
                if (!preg_match('#^https?://#', $url)) {
                    $url = UrlUtil::makeAbsolute($url, $request->getUri());
                }

                $htmlHeadBag->setCanonicalUri($url);
            } elseif (!$this->cal_keepCanonical) {
                $htmlHeadBag->setCanonicalUri($this->contentUrlGenerator->generate($objEvent, array(), UrlGeneratorInterface::ABSOLUTE_URL));
            }
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

    private function resolveRequestedOccurrenceTimestamp(Request $request): ?int
    {
        $times = $request->query->get('times');

        if (\is_scalar($times) && preg_match('/^\d{6,12}$/', (string)$times)) {
            return (int)$times;
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function resolveOccurrence(CalendarEventsModel $event, ?int $requestedStartTs): array
    {
        $startTs = (int)$event->startTime;
        $endTs = (int)$event->endTime;

        if (
            !$event->areRecurring
            || null === $requestedStartTs
            || '' === trim((string)$event->rrule)
        ) {
            return [$startTs, $endTs, false];
        }

        $normalizedRrule = $this->normalizeRrule((string)$event->rrule);

        if (null === $normalizedRrule) {
            return [$startTs, $endTs, false];
        }

        $timezone = new \DateTimeZone((string)date_default_timezone_get());
        $eventStart = (new \DateTimeImmutable('@' . $startTs))->setTimezone($timezone);
        $eventEnd = (new \DateTimeImmutable('@' . $endTs))->setTimezone($timezone);
        $requested = (new \DateTimeImmutable('@' . $requestedStartTs))->setTimezone($timezone);

        try {
            $rule = new Rule($normalizedRrule, $eventStart, $eventEnd, $timezone->getName());
            $config = new ArrayTransformerConfig();
            $config->setVirtualLimit(10000);

            $transformer = new ArrayTransformer($config);
            $constraint = new BetweenConstraint(
                $requested->modify('-1 second'),
                $requested->modify('+1 second'),
                true
            );

            foreach ($transformer->transform($rule, $constraint) as $recurrence) {
                $recurrenceStart = $recurrence->getStart();
                $recurrenceEnd = $recurrence->getEnd();

                if (!$recurrenceStart instanceof \DateTimeInterface || !$recurrenceEnd instanceof \DateTimeInterface) {
                    continue;
                }

                if ($recurrenceStart->getTimestamp() !== $requestedStartTs) {
                    continue;
                }

                return [$recurrenceStart->getTimestamp(), $recurrenceEnd->getTimestamp(), true];
            }
        } catch (\Throwable) {
            return [$startTs, $endTs, false];
        }

        return [$startTs, $endTs, false];
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

    private function normalizeRrule(string $raw): ?string
    {
        $value = trim($raw);

        if ('' === $value) {
            return null;
        }

        if (str_starts_with(strtoupper($value), 'RRULE:')) {
            $value = trim(substr($value, 6));
        }

        if (!str_contains(strtoupper($value), 'FREQ=')) {
            return null;
        }

        return preg_replace('/\s+/', '', $value) ?: null;
    }
}
