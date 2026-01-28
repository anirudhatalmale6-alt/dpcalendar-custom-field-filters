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
            'onContentPrepareForm'  => ['onContentPrepareForm', -100],
            'onAfterInitialise'     => ['onAfterInitialise', 100],
            'onBeforeCompileHead'   => ['onBeforeCompileHead', 100],
        ];
    }

    /**
     * Add custom CSS to replace "Remove" text with X
     */
    public function onBeforeCompileHead(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $doc = $app->getDocument();
        if ($doc->getType() !== 'html') {
            return;
        }

        // CSS to hide "Remove" text and show X instead, and fix z-index for dropdown
        $css = '
            .choices__button {
                font-size: 0 !important;
                padding: 0 8px !important;
            }
            .choices__button::after {
                content: "×" !important;
                font-size: 16px !important;
                font-weight: bold !important;
            }
            /* Fix dropdown z-index and overflow to appear above other content */
            .dp-filter,
            .dp-filter__fields,
            .dp-filter .control-group,
            .dp-filter .controls,
            .dp-form,
            .dp-form__fields,
            .dp-form .control-group,
            .dp-form .controls,
            form[name="adminForm"] .control-group,
            form[name="adminForm"] .controls {
                overflow: visible !important;
            }
            .choices {
                position: relative;
                z-index: 100;
            }
            .choices.is-open {
                z-index: 9999 !important;
            }
            .choices__list--dropdown,
            .choices__list[aria-expanded="true"] {
                z-index: 99999 !important;
                position: absolute !important;
                overflow: visible !important;
            }
        ';

        $doc->addStyleDeclaration($css);
    }

    /**
     * Capture filter[com_fields] from request very early
     */
    public function onAfterInitialise(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        // Get filter data from request
        $filterFromInput = $app->getInput()->get('filter', [], 'array');
        $filterFromPost = $app->getInput()->post->get('filter', [], 'array');
        $filterFromGet = $app->getInput()->get->get('filter', [], 'array');

        // Use whichever has data
        $filter = !empty($filterFromPost) ? $filterFromPost : (!empty($filterFromGet) ? $filterFromGet : $filterFromInput);

        if (empty($filter['com_fields'])) {
            return;
        }

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

        if (empty($comFields)) {
            return;
        }

        // Get Itemid and view for context
        $itemId = $app->getInput()->getInt('Itemid', 0);
        $view = $app->getInput()->get('view', 'calendar');

        // Build context variations
        $contexts = [
            $view . '.' . $itemId,
            'list.' . $itemId,
            'calendar.' . $itemId,
            'map.' . $itemId,
        ];

        foreach ($contexts as $context) {
            $existingFilter = $app->getUserState($context . '.filter', []);
            if (!is_array($existingFilter)) {
                $existingFilter = [];
            }
            $existingFilter['com_fields'] = $comFields;
            $app->setUserState($context . '.filter', $existingFilter);
        }
    }

    /**
     * Adds custom field dropdowns to DPCalendar filter forms
     */
    public function onContentPrepareForm(Event $event): void
    {
        /** @var Form $form */
        $form = $event->getArgument(0);
        $formName = $form->getName();

        // Match DPCalendar events filter forms
        $isDPCalendarEventsForm = false;

        if (str_starts_with($formName, 'com_dpcalendar')) {
            if (str_contains($formName, 'events') || str_contains($formName, 'filter')) {
                $isDPCalendarEventsForm = true;
            }
        }

        if (preg_match('/^(calendar|list|map)\.\d+/', $formName)) {
            $isDPCalendarEventsForm = true;
        }

        if (!$isDPCalendarEventsForm) {
            return;
        }

        $fieldNamesParam = $this->params->get('field_names', 'altersklasse,spielform');
        $configuredFieldNames = array_map('trim', explode(',', $fieldNamesParam));

        try {
            $customFields = FieldsHelper::getFields('com_dpcalendar.event');
        } catch (\Exception $e) {
            return;
        }

        if (empty($customFields)) {
            return;
        }

        // Remove existing text fields
        foreach ($configuredFieldNames as $fieldName) {
            $form->removeField($fieldName, 'com_fields');
            $form->removeField($fieldName, 'filter.com_fields');
        }

        // Add our dropdown fields
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

            // Start with empty options - placeholder is handled by hint attribute
            $optionsXml = '';

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
                default=""
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
