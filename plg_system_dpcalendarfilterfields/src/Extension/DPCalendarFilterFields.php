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
            'onAfterRoute'         => 'onAfterRoute',
        ];
    }

    /**
     * Capture filter[com_fields] from request and store in user state
     * This ensures the model can retrieve the filter values
     */
    public function onAfterRoute(Event $event): void
    {
        $app = $this->getApplication();

        // Only process on site frontend
        if (!$app->isClient('site')) {
            return;
        }

        // Check if this is a DPCalendar request
        $option = $app->getInput()->get('option', '');
        if ($option !== 'com_dpcalendar') {
            return;
        }

        // Get filter data from request
        $filter = $app->getInput()->get('filter', [], 'array');

        if (!empty($filter['com_fields'])) {
            // Get the Itemid to build the correct context
            $itemId = $app->getInput()->getInt('Itemid', 0);
            $view = $app->getInput()->get('view', 'calendar');

            // DPCalendar uses view-specific contexts like "calendar.123" or "list.456"
            $context = $view . '.' . $itemId;

            // Store in multiple possible contexts to ensure it's found
            $app->setUserState($context . '.filter.com_fields', $filter['com_fields']);
            $app->setUserState('com_dpcalendar.events.filter.com_fields', $filter['com_fields']);

            // Also try the generic filter state
            $currentFilter = $app->getUserState($context . '.filter', []);
            $currentFilter['com_fields'] = $filter['com_fields'];
            $app->setUserState($context . '.filter', $currentFilter);
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
        // (DPCalendar adds them as text inputs by default)
        foreach ($configuredFieldNames as $fieldName) {
            $form->removeField($fieldName, 'com_fields');
            $form->removeField($fieldName, 'filter.com_fields');
        }

        // Find the fields we want to add as filters
        foreach ($customFields as $field) {
            // Check if this field is in our configured list
            if (!in_array($field->name, $configuredFieldNames, true)) {
                continue;
            }

            // Only process list-type fields (list, checkboxes, radio)
            $listTypes = ['list', 'checkboxes', 'radio'];
            if (!in_array($field->type, $listTypes, true)) {
                continue;
            }

            // Get field options from fieldparams
            $fieldParams = json_decode($field->fieldparams, true);
            $options = $fieldParams['options'] ?? [];

            if (empty($options)) {
                continue;
            }

            // Build options XML
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

            // The DPCalendar EventsModel expects filter.com_fields to be an associative array
            // keyed by field name. Using the correct nested structure:
            // filter[com_fields][fieldname][] for multiple values
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
