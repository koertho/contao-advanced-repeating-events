<?php

namespace Koertho\AdvancedRepeatingEventsBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class KoerthoAdvancedRepeatingEventsBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }


}