<?php

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Template;

class ApplyToReaderModuleListener
{
    #[AsHook('parseTemplate')]
    public function parseTemplate(Template $template)
    {
        if (!str_starts_with($template->getName(), 'event_full')) {
            return;
        }
    }
}