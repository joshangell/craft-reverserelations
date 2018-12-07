<?php

namespace robuust\reverserelations\fields;

use Craft;
use craft\base\Field;
use craft\base\Element;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\Entries;
use craft\base\FieldInterface;
use craft\base\ElementInterface;
use craft\events\FieldElementEvent;
use craft\elements\db\ElementQuery;

/**
 * Reverse Relations Entries Field.
 *
 * @author    Bob Olde Hampsink <bob@robuust.digital>
 * @copyright Copyright (c) 2018, Robuust
 * @license   MIT
 *
 * @see       https://robuust.digital
 */
class ReverseEntries extends Entries
{
    /**
     * @var int Target field setting
     */
    public $targetFieldId;

    /**
     * @var bool Read-only setting
     */
    public $readOnly;

    /**
     * {@inheritdoc}
     */
    public $allowLimit = false;

    /**
     * {@inheritdoc}
     */
    protected $sortable = false;

    /**
     * {@inheritdoc}
     */
    public static function displayName(): string
    {
        return Craft::t('reverserelations', 'Reverse Entry Relations');
    }

    /**
     * {@inheritdoc}
     */
    public function getSettingsHtml()
    {
        // Get parent settings
        $settings = parent::getSettingsHtml();

        // Get available fields
        $fields = [];
        /** @var Field $field */
        foreach (Craft::$app->fields->getAllFields() as $field) {
            $fields[$field->id] = $field->name;
        }

        // Add "field" select template
        $fieldSelectTemplate = Craft::$app->view->renderTemplate(
            'reverserelations/_settings', [
                'fields' => $fields,
                'settings' => $this->getSettings(),
            ]
        );

        // Return both
        return $settings.$fieldSelectTemplate;
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        /** @var Element|null $element */
        $query = parent::normalizeValue($value, $element);

        // Get allowed sources
        $sources = [];
        foreach ($this->inputSources() as $source) {
            list($type, $id) = explode(':', $source);
            $sources[] = (int) $id;
        }

        // Overwrite inner join to switch sourceId and targetId
        $query->join = [];
        if ($value !== '' && $element && $element->id) {
            $query
                ->innerJoin(
                    '{{%relations}} relations',
                    [
                        'and',
                        '[[relations.sourceId]] = [[elements.id]]',
                        [
                            'relations.targetId' => $element->id,
                            'relations.fieldId' => $this->targetFieldId,
                        ],
                        [
                            'or',
                            ['relations.sourceSiteId' => null],
                            ['relations.sourceSiteId' => $element->siteId],
                        ],
                    ]
                )
                ->where(['entries.sectionId' => $sources]);
        }

        return $query;
    }

    /**
     * Save relations on the other side.
     *
     * {@inheritdoc}
     */
    public function afterElementSave(ElementInterface $element, bool $isNew)
    {
        /** @var Element $element */
        /** @var Field $field */
        $field = Craft::$app->fields->getFieldById($this->targetFieldId);

        // Determine if a field can save a reverse relation
        if (!$this->canSaveReverseRelation($field)) {
            return;
        }

        // Get targets
        $targetIds = $element->getFieldValue($this->handle);

        // Loop through targets
        /** @var ElementInterface $target */
        foreach ($targetIds->all() as $target) {
            // Set this element on that entry
            $target->setFieldValue(
                $field->handle,
                array_merge($target->getFieldValue($field->handle)->ids(), [$element->id])
            );

            // Save target
            Craft::$app->elements->saveElement($target);
        }

        // This code is from the grandparent method
        // Trigger an 'afterElementSave' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_ELEMENT_SAVE)) {
            $this->trigger(self::EVENT_AFTER_ELEMENT_SAVE, new FieldElementEvent([
                'element' => $element,
                'isNew' => $isNew,
            ]));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        /** @var Element|null $element */
        if ($element !== null && $element->hasEagerLoadedElements($this->handle)) {
            $value = $element->getEagerLoadedElements($this->handle);
        }

        // Get variables
        /** @var ElementQuery|array $value */
        $variables = $this->inputTemplateVariables($value, $element);

        // Disable adding if we can't save a reverse relation
        $field = Craft::$app->fields->getFieldById($this->targetFieldId);
        $variables['readOnly'] = $this->readOnly || !$this->canSaveReverseRelation($field);

        // Return input template (local override if exists)
        $template = 'reverserelations/'.$this->inputTemplate;
        $template = Craft::$app->view->doesTemplateExist($template) ? $template : $this->inputTemplate;

        return Craft::$app->view->renderTemplate($template, $variables);
    }

    /**
     * {@inheritdoc}
     */
    public function settingsAttributes(): array
    {
        $attributes = parent::settingsAttributes();
        $attributes[] = 'targetFieldId';
        $attributes[] = 'readOnly';

        return $attributes;
    }

    /**
     * Determine if a field can save a reverse relation.
     *
     * @param FieldInterface $field
     *
     * @return bool
     */
    private function canSaveReverseRelation(FieldInterface $field): bool
    {
        if ($field instanceof Matrix) {
            return false;
        }

        return true;
    }
}