<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Widget;

use Contao\StringUtil;
use Contao\System;
use Contao\Widget;
use Twig\Environment;

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
        $context = [
            'id' => StringUtil::specialchars((string) $this->strId),
            'name' => StringUtil::specialchars((string) $this->strName),
            'value' => self::specialcharsValue((string) $this->varValue),
            'class' => $this->strClass ? ' '.StringUtil::specialchars((string) $this->strClass) : '',
            'attributes' => $this->getAttributes(['readonly', 'required']),
            'required' => $this->mandatory ? ' required' : '',
            'wizard' => $this->wizard,
        ];

        /** @var Environment $twig */
        $twig = System::getContainer()->get('twig');

        return $twig->render('@Contao/backend/widget/rrule_builder.html.twig', $context);
    }
}
