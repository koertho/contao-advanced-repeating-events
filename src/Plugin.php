<?php

namespace Koertho\AdvancedRepeatingEventsBundle;

use Contao\CalendarBundle\ContaoCalendarBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;

class Plugin implements BundlePluginInterface
{

    /**
     * @inheritDoc
     */
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(KoerthoAdvancedRepeatingEventsBundle::class)
                ->setLoadAfter([
                    ContaoCalendarBundle::class,
                ])
        ];
    }
}