<?php

/**
 * @package     DPCalendarFilterFields
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use FloorballTurniere\Plugin\System\DPCalendarFilterFields\Extension\DPCalendarFilterFields;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new DPCalendarFilterFields(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'dpcalendarfilterfields')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
