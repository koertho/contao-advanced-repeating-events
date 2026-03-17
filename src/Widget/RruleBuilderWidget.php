<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Widget;

use Contao\StringUtil;
use Contao\System;
use Contao\Widget;
use Twig\Environment;

final class RruleBuilderWidget extends Widget
{
    protected $blnSubmitInput = true;
    protected $blnForAttribute = true;
    protected $strTemplate = 'be_widget';

    public function generate(): string
    {
        $timezone = new \DateTimeZone(date_default_timezone_get());

        $context = [
            'id' => StringUtil::specialchars((string) $this->strId),
            'name' => StringUtil::specialchars((string) $this->strName),
            'value' => self::specialcharsValue((string) $this->varValue),
            'class' => $this->strClass ? ' '.StringUtil::specialchars((string) $this->strClass) : '',
            'attributes' => $this->getAttributes(['readonly', 'required']),
            'required' => $this->mandatory ? ' required' : '',
            'timezone' => StringUtil::specialchars($timezone->getName()),
            'wizard' => $this->wizard,
        ];

        /** @var Environment $twig */
        $twig = System::getContainer()->get('twig');

        return $twig->render('@Contao/backend/widget/rrule_builder.html.twig', $context);
    }
}
