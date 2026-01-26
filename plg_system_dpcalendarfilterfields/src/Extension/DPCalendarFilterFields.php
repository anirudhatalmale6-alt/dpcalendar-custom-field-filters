<?php

/**
 * @package     DPCalendarFilterFields
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 3 or later
 */

namespace FloorballTurniere\Plugin\System\DPCalendarFilterFields\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

class DPCalendarFilterFields extends CMSPlugin implements SubscriberInterface
{
    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => ['onContentPrepareForm', -100], // Run after DPCalendar
            'onAfterInitialise'    => ['onAfterInitialise', 100],     // Run very early
        ];
    }

    /**
     * Capture filter[com_fields] from request very early in the request lifecycle
     * Before the component/model even loads
     */
    public function onAfterInitialise(Event $event): void
    {
        $app = $this->getApplication();

        // Only process on site frontend
        if (!$app->isClient('site')) {
            return;
        }

        // Get filter data directly from POST/GET
        // At this point, routing hasn't happened yet, so we check raw input
        $filter = $app->getInput()->get('filter', [], 'array');

        // We need Itemid for context - may not be set yet, get from request
        $itemId = $app->getInput()->getInt('Itemid', 0);

        // Try to determine the view from multiple sources
        $view = $app->getInput()->get('view', '');
        if (empty($view)) {
            // Fallback to common DPCalendar views
            $view = 'calendar';
        }

        // Build all possible context variations
        $contexts = [
            $view . '.' . $itemId,
            'com_dpcalendar.' . $view . '.' . $itemId,
            'com_dpcalendar.events',
            'list.' . $itemId,
            'calendar.' . $itemId,
            'map.' . $itemId,
        ];

        if (!empty($filter['com_fields'])) {
            // Clean up empty values
            $comFields = [];
            foreach ($filter['com_fields'] as $fieldName => $value) {
                if (is_array($value)) {
                    $filtered = array_filter($value, fn($v) => $v !== '' && $v !== null);
                    if (!empty($filtered)) {
                        $comFields[$fieldName] = array_values($filtered);
                    }
                } elseif ($value !== '' && $value !== null) {
                    $comFields[$fieldName] = [$value];
                }
            }

            if (!empty($comFields)) {
                // Store in all possible contexts so the model can find it
                foreach ($contexts as $context) {
                    $app->setUserState($context . '.filter.com_fields', $comFields);
                }
            }
        } elseif ($app->getInput()->getMethod() === 'POST' && !empty($filter)) {
            // Clear filters if form submitted without com_fields
            foreach ($contexts as $context) {
                $app->setUserState($context . '.filter.com_fields', null);
            }
        }
    }

    /**
     * Adds custom field dropdowns to DPCalendar filter forms
     */
    public function onContentPrepareForm(Event $event): void
    {
        /** @var Form $form */
        $form = $event->getArgument(0);

        // Get form name
        $formName = $form->getName();

        // Match DPCalendar events filter forms
        $isDPCalendarEventsForm = false;

        if (str_starts_with($formName, 'com_dpcalendar')) {
            if (str_contains($formName, 'events') || str_contains($formName, 'filter')) {
                $isDPCalendarEventsForm = true;
            }
        }

        // Also check for view-specific forms (calendar, list, map views)
        if (preg_match('/^(calendar|list|map)\.\d+/', $formName)) {
            $isDPCalendarEventsForm = true;
        }

        if (!$isDPCalendarEventsForm) {
            return;
        }

        // Get configured field names from plugin params
        $fieldNamesParam = $this->params->get('field_names', 'altersklasse,spielform');
        $configuredFieldNames = array_map('trim', explode(',', $fieldNamesParam));

        // Get all custom fields for DPCalendar events
        try {
            $customFields = FieldsHelper::getFields('com_dpcalendar.event');
        } catch (\Exception $e) {
            return;
        }

        if (empty($customFields)) {
            return;
        }

        // First, remove any existing text fields for our configured fields
        foreach ($configuredFieldNames as $fieldName) {
            $form->removeField($fieldName, 'com_fields');
            $form->removeField($fieldName, 'filter.com_fields');
        }

        // Find the fields we want to add as filters
        foreach ($customFields as $field) {
            if (!in_array($field->name, $configuredFieldNames, true)) {
                continue;
            }

            $listTypes = ['list', 'checkboxes', 'radio'];
            if (!in_array($field->type, $listTypes, true)) {
                continue;
            }

            $fieldParams = json_decode($field->fieldparams, true);
            $options = $fieldParams['options'] ?? [];

            if (empty($options)) {
                continue;
            }

            $optionsXml = '';
            $optionsXml .= '<option value="">- ' . htmlspecialchars($field->label, ENT_XML1, 'UTF-8') . ' -</option>';

            foreach ($options as $option) {
                $value = $option['value'] ?? '';
                $name = $option['name'] ?? $value;
                if ($value !== '') {
                    $optionsXml .= '<option value="' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '">' . htmlspecialchars($name, ENT_XML1, 'UTF-8') . '</option>';
                }
            }

            $label = htmlspecialchars($field->label, ENT_XML1, 'UTF-8');
            $fieldName = htmlspecialchars($field->name, ENT_XML1, 'UTF-8');

            $fieldXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<form>
    <fields name="filter">
        <fields name="com_fields">
            <field
                name="{$fieldName}"
                type="list"
                label="{$label}"
                multiple="true"
                layout="joomla.form.field.list-fancy-select"
                class="dp-select advancedSelect"
                hint="{$label}"
            >
                {$optionsXml}
            </field>
        </fields>
    </fields>
</form>
XML;

            try {
                $form->load($fieldXml);
            } catch (\Exception $e) {
                // Silently fail
            }
        }
    }
}
