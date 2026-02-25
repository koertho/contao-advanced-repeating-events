<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Widget;

use Contao\StringUtil;
use Contao\Widget;

final class RruleBuilderWidget extends Widget
{
    protected static bool $jsLoaded = false;
    protected static bool $cssLoaded = false;

    protected $blnSubmitInput = true;
    protected $blnForAttribute = true;
    protected $strTemplate = 'be_widget';

    public function __construct($arrAttributes = null)
    {
        parent::__construct($arrAttributes);

        if (!self::$jsLoaded) {
            $GLOBALS['TL_JAVASCRIPT'][] = 'bundles/koerthoadvancedrepeatingevents/backend/rrule-builder.js|static';
            self::$jsLoaded = true;
        }

        if (!self::$cssLoaded) {
            $GLOBALS['TL_CSS'][] = 'bundles/koerthoadvancedrepeatingevents/backend/rrule-builder.css|static';
            self::$cssLoaded = true;
        }
    }

    public function generate(): string
    {
        $id = StringUtil::specialchars((string) $this->strId);
        $name = StringUtil::specialchars((string) $this->strName);
        $value = self::specialcharsValue((string) $this->varValue);
        $class = $this->strClass ? ' ' . StringUtil::specialchars((string) $this->strClass) : '';
        $attributes = $this->getAttributes(['readonly', 'required']);
        $required = $this->mandatory ? ' required' : '';
        $labels = $this->getLabels();

        $frequencyOptions = $this->buildOptions([
            'DAILY' => $labels['frequency_daily'],
            'WEEKLY' => $labels['frequency_weekly'],
            'MONTHLY' => $labels['frequency_monthly'],
            'YEARLY' => $labels['frequency_yearly'],
        ]);

        $monthlyModeOptions = $this->buildOptions([
            'monthday' => $labels['monthly_mode_monthday'],
            'weekdaypos' => $labels['monthly_mode_weekdaypos'],
        ]);

        $occurrenceOptions = $this->buildOptions([
            '1' => $labels['occurrence_1'],
            '2' => $labels['occurrence_2'],
            '3' => $labels['occurrence_3'],
            '4' => $labels['occurrence_4'],
            '-1' => $labels['occurrence_last'],
        ]);

        $weekdayOptions = $this->buildOptions([
            'MO' => $labels['weekday_mo'],
            'TU' => $labels['weekday_tu'],
            'WE' => $labels['weekday_we'],
            'TH' => $labels['weekday_th'],
            'FR' => $labels['weekday_fr'],
            'SA' => $labels['weekday_sa'],
            'SU' => $labels['weekday_su'],
        ]);

        $endModeOptions = $this->buildOptions([
            'never' => $labels['end_never'],
            'count' => $labels['end_count'],
            'until' => $labels['end_until'],
        ]);

        return sprintf(
            <<<'HTML'
<div class="tl_rrule_builder" data-rrule-builder>
  <div class="tl_rrule_builder__grid">
    <div class="widget">
      <label for="ctrl_%1$s_freq">%2$s</label>
      <select id="ctrl_%1$s_freq" class="tl_select" data-rrule-part="freq">%3$s</select>
    </div>
    <div class="widget">
      <label for="ctrl_%1$s_interval">%4$s</label>
      <input type="number" id="ctrl_%1$s_interval" class="tl_text" min="1" value="1" data-rrule-part="interval">
    </div>
  </div>

  <fieldset class="tl_rrule_builder__fieldset is-hidden" data-rrule-section="weekly">
    <legend>%5$s</legend>
    <div class="tl_rrule_builder__checklist">
      <label><input type="checkbox" value="MO" data-rrule-part="byday"> %6$s</label>
      <label><input type="checkbox" value="TU" data-rrule-part="byday"> %7$s</label>
      <label><input type="checkbox" value="WE" data-rrule-part="byday"> %8$s</label>
      <label><input type="checkbox" value="TH" data-rrule-part="byday"> %9$s</label>
      <label><input type="checkbox" value="FR" data-rrule-part="byday"> %10$s</label>
      <label><input type="checkbox" value="SA" data-rrule-part="byday"> %11$s</label>
      <label><input type="checkbox" value="SU" data-rrule-part="byday"> %12$s</label>
    </div>
  </fieldset>

  <fieldset class="tl_rrule_builder__fieldset is-hidden" data-rrule-section="monthly">
    <legend>%13$s</legend>
    <div class="tl_rrule_builder__grid">
      <div class="widget">
        <label for="ctrl_%1$s_monthly_mode">%14$s</label>
        <select id="ctrl_%1$s_monthly_mode" class="tl_select" data-rrule-part="monthly_mode">%15$s</select>
      </div>
      <div class="widget is-hidden" data-rrule-section="monthly-monthday">
        <label for="ctrl_%1$s_bymonthday">%16$s</label>
        <input type="number" id="ctrl_%1$s_bymonthday" class="tl_text" min="1" max="31" value="1" data-rrule-part="bymonthday">
      </div>
      <div class="widget is-hidden" data-rrule-section="monthly-weekdaypos">
        <label for="ctrl_%1$s_bysetpos">%17$s</label>
        <select id="ctrl_%1$s_bysetpos" class="tl_select" data-rrule-part="bysetpos">%18$s</select>
      </div>
      <div class="widget is-hidden" data-rrule-section="monthly-weekdaypos">
        <label for="ctrl_%1$s_monthly_weekday">%19$s</label>
        <select id="ctrl_%1$s_monthly_weekday" class="tl_select" data-rrule-part="monthly_weekday">%20$s</select>
      </div>
    </div>
  </fieldset>

  <fieldset class="tl_rrule_builder__fieldset is-hidden" data-rrule-section="yearly">
    <legend>%21$s</legend>
    <div class="tl_rrule_builder__grid">
      <div class="widget">
        <label for="ctrl_%1$s_bymonth">%22$s</label>
        <input type="number" id="ctrl_%1$s_bymonth" class="tl_text" min="1" max="12" value="1" data-rrule-part="bymonth">
      </div>
      <div class="widget">
        <label for="ctrl_%1$s_yearly_bymonthday">%16$s</label>
        <input type="number" id="ctrl_%1$s_yearly_bymonthday" class="tl_text" min="1" max="31" value="1" data-rrule-part="yearly_bymonthday">
      </div>
    </div>
  </fieldset>

  <fieldset class="tl_rrule_builder__fieldset">
    <legend>%23$s</legend>
    <div class="tl_rrule_builder__grid">
      <div class="widget">
        <label for="ctrl_%1$s_end_mode">%24$s</label>
        <select id="ctrl_%1$s_end_mode" class="tl_select" data-rrule-part="end_mode">%25$s</select>
      </div>
      <div class="widget is-hidden" data-rrule-section="end-count">
        <label for="ctrl_%1$s_count">%26$s</label>
        <input type="number" id="ctrl_%1$s_count" class="tl_text" min="1" value="1" data-rrule-part="count">
      </div>
      <div class="widget is-hidden" data-rrule-section="end-until">
        <label for="ctrl_%1$s_until">%27$s</label>
        <input type="date" id="ctrl_%1$s_until" class="tl_text" data-rrule-part="until">
      </div>
    </div>
  </fieldset>

  <div class="widget">
    <label for="ctrl_%1$s">%28$s</label>
    <input type="text" name="%29$s" id="ctrl_%1$s" class="tl_text%30$s" value="%31$s" readonly data-rrule-output%32$s%33$s>
  </div>
</div>%34$s
HTML,
            $id,
            $labels['frequency'],
            $frequencyOptions,
            $labels['interval'],
            $labels['weekdays'],
            $labels['weekday_mo'],
            $labels['weekday_tu'],
            $labels['weekday_we'],
            $labels['weekday_th'],
            $labels['weekday_fr'],
            $labels['weekday_sa'],
            $labels['weekday_su'],
            $labels['monthly'],
            $labels['monthly_mode'],
            $monthlyModeOptions,
            $labels['day_of_month'],
            $labels['occurrence'],
            $occurrenceOptions,
            $labels['weekday'],
            $weekdayOptions,
            $labels['yearly'],
            $labels['month'],
            $labels['end'],
            $labels['end_mode'],
            $endModeOptions,
            $labels['count'],
            $labels['until'],
            $labels['rrule'],
            $name,
            $class,
            $value,
            $required,
            $attributes,
            $this->wizard
        );
    }

    /**
     * @return array<string, string>
     */
    private function getLabels(): array
    {
        return [
            'frequency' => $this->translate('frequency', 'Frequency'),
            'frequency_daily' => $this->translate('frequency_daily', 'Daily'),
            'frequency_weekly' => $this->translate('frequency_weekly', 'Weekly'),
            'frequency_monthly' => $this->translate('frequency_monthly', 'Monthly'),
            'frequency_yearly' => $this->translate('frequency_yearly', 'Yearly'),
            'interval' => $this->translate('interval', 'Repeat every'),
            'weekdays' => $this->translate('weekdays', 'Weekdays'),
            'monthly' => $this->translate('monthly', 'Monthly details'),
            'monthly_mode' => $this->translate('monthly_mode', 'Pattern'),
            'monthly_mode_monthday' => $this->translate('monthly_mode_monthday', 'Day of month'),
            'monthly_mode_weekdaypos' => $this->translate('monthly_mode_weekdaypos', 'Nth weekday'),
            'day_of_month' => $this->translate('day_of_month', 'Day of month'),
            'occurrence' => $this->translate('occurrence', 'Occurrence'),
            'occurrence_1' => $this->translate('occurrence_1', 'First'),
            'occurrence_2' => $this->translate('occurrence_2', 'Second'),
            'occurrence_3' => $this->translate('occurrence_3', 'Third'),
            'occurrence_4' => $this->translate('occurrence_4', 'Fourth'),
            'occurrence_last' => $this->translate('occurrence_last', 'Last'),
            'weekday' => $this->translate('weekday', 'Weekday'),
            'weekday_mo' => $this->translate('weekday_mo', 'Monday'),
            'weekday_tu' => $this->translate('weekday_tu', 'Tuesday'),
            'weekday_we' => $this->translate('weekday_we', 'Wednesday'),
            'weekday_th' => $this->translate('weekday_th', 'Thursday'),
            'weekday_fr' => $this->translate('weekday_fr', 'Friday'),
            'weekday_sa' => $this->translate('weekday_sa', 'Saturday'),
            'weekday_su' => $this->translate('weekday_su', 'Sunday'),
            'yearly' => $this->translate('yearly', 'Yearly details'),
            'month' => $this->translate('month', 'Month'),
            'end' => $this->translate('end', 'End condition'),
            'end_mode' => $this->translate('end_mode', 'Ends'),
            'end_never' => $this->translate('end_never', 'Never'),
            'end_count' => $this->translate('end_count', 'After count'),
            'end_until' => $this->translate('end_until', 'On date'),
            'count' => $this->translate('count', 'Count'),
            'until' => $this->translate('until', 'Until'),
            'rrule' => $this->translate('rrule', 'Generated RRULE'),
        ];
    }

    private function translate(string $key, string $fallback): string
    {
        $value = $GLOBALS['TL_LANG']['tl_calendar_events']['rrule_builder'][$key] ?? $fallback;

        return StringUtil::specialchars((string) $value);
    }

    /**
     * @param array<string, string> $options
     */
    private function buildOptions(array $options): string
    {
        $markup = '';

        foreach ($options as $value => $label) {
            $markup .= sprintf(
                '<option value="%s">%s</option>',
                StringUtil::specialchars($value),
                $label
            );
        }

        return $markup;
    }
}
