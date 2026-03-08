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

    public function __construct($arrAttributes = null)
    {
        parent::__construct($arrAttributes);

        $rootDir = System::getContainer()->getParameter('kernel.project_dir');
        $assetPath = 'bundles/koerthoadvancedrepeatingevents/backend';
        $cssTimestamp = @filemtime($rootDir. '/' . $assetPath . '/rrule-builder.js');
        $GLOBALS['TL_JAVASCRIPT']['are_be'] = $assetPath.'/rrule-builder.js|'.$cssTimestamp;
        $cssTimestamp = @filemtime($rootDir. '/' . $assetPath . '/rrule-builder.css');
        $GLOBALS['TL_CSS']['are_be'] = $assetPath.'/rrule-builder.css|'.$cssTimestamp;

    }

    public function generate(): string
    {
        $context = [
            'id' => StringUtil::specialchars((string)$this->strId),
            'name' => StringUtil::specialchars((string)$this->strName),
            'value' => self::specialcharsValue((string)$this->varValue),
            'class' => $this->strClass ? ' ' . StringUtil::specialchars((string)$this->strClass) : '',
            'attributes' => $this->getAttributes(['readonly', 'required']),
            'required' => $this->mandatory ? ' required' : '',
            'wizard' => $this->wizard,
        ];

        /** @var Environment $twig */
        $twig = System::getContainer()->get('twig');

        return $twig->render('@Contao/backend/widget/rrule_builder.html.twig', $context);
    }
}
