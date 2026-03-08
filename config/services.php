<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Koertho\AdvancedRepeatingEventsBundle\Contao\EventGeneratorDecorator;

return function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load(
        'Koertho\\AdvancedRepeatingEventsBundle\\',
        '../src/{Command,Controller,EventListener,Recurrence}'
    );

    $services->set(EventGeneratorDecorator::class)
        ->decorate('contao_calendar.generator.calendar_events');
};
