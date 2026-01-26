<?php

/**
 * @package     DPCalendarFilterFields
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 3 or later
 */

namespace FloorballTurniere\Plugin\System\DPCalendarFilterFields\Extension;

\defined('_JEXEC') or die;

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
            'onContentPrepareForm' => 'onContentPrepareForm',
        ];
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
        // The form name could be:
        // - com_dpcalendar.events (direct model form)
        // - com_dpcalendar.filter.events (filter form variant)
        // - calendar.XXX.filter (calendar view with Itemid)
        // - list.XXX.filter (list view with Itemid)
        // - map.XXX.filter (map view with Itemid)
        $isDPCalendarEventsForm = false;

        if (str_starts_with($formName, 'com_dpcalendar')) {
            // Check for events form patterns
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

            // Build XML for a multi-select dropdown field
            // Using filter[com_fields][fieldname] format that DPCalendar expects
            $optionsXml = '';
            $optionsXml .= '<option value="">- ' . htmlspecialchars($field->label, ENT_XML1, 'UTF-8') . ' -</option>';

            foreach ($options as $option) {
                $value = $option['value'] ?? '';
                $name = $option['name'] ?? $value;
                if ($value !== '') {
                    $optionsXml .= '<option value="' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '">' . htmlspecialchars($name, ENT_XML1, 'UTF-8') . '</option>';
                }
            }

            // Clean up label for XML
            $label = htmlspecialchars($field->label, ENT_XML1, 'UTF-8');
            $fieldName = htmlspecialchars($field->name, ENT_XML1, 'UTF-8');

            // Use Joomla's fancyselect/chosen class for better UX
            // The 'advancedSelect' layout enables click-to-select behavior
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
            >
                {$optionsXml}
            </field>
        </fields>
    </fields>
</form>
XML;

            // Load the field into the form
            try {
                $form->load($fieldXml);
            } catch (\Exception $e) {
                // Silently fail if form loading fails
            }
        }
    }
}
