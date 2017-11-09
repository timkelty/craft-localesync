<?php
namespace Craft;

class LocaleSyncService extends BaseApplicationComponent
{
	private $_elementBeforeSave;
	private $_element;
	private $_elementSettings;

	public function getElementOptionsHtml(BaseElementModel $element)
	{
		$isNew = $element->id === null;
		$locales = array_keys($element->getLocales());
		$settings = craft()->plugins->getPlugin('localeSync')->getSettings();

		if ($isNew || count($locales) < 2) {
			return;
		}

		return craft()->templates->render('localesync/_cp/editRightPane', [
			'settings' => $settings,
			'localeId' => $element->locale,
		]);
	}

	public function getLocaleInputOptions($locales = null, $exclude = [])
	{
		$locales = $locales ?: craft()->i18n->getSiteLocales();
		$locales = array_map(function($locale) use ($exclude) {
			if (!$locale instanceof LocaleModel) {
				$locale = craft()->i18n->getLocaleById($locale);
			}

			if ($locale instanceof LocaleModel && !in_array($locale->id, $exclude)) {
				$locale = [
					'label' => $locale->name,
					'value' => $locale->id,
				];
			} else {
				$locale = null;
			}

			return $locale;
		}, $locales);

		return array_filter($locales);
	}

	public function syncElementContent(Event $event, $elementSettings)
	{
		if ($elementSettings !== null) {
			$pluginSettings = craft()->plugins->getPlugin('localeSync')->getSettings();
			$this->_element = $event->params['element'];
			$this->_elementSettings = $elementSettings;

			// elementSettings will be null in HUD, where we want to continue with defaults
			if ($this->_elementSettings !== null && ($event->params['isNewElement'] || empty($this->_elementSettings['enabled']))) {
				return;
			}

			$this->_elementBeforeSave = craft()->elements->getElementById($this->_element->id, $this->_element->elementType, $this->_element->locale);
			$locales = $this->_element->getLocales();

			// Matrix elements return a non-associative array from getLocales(). Normalize it.
			if ($this->_element instanceof MatrixBlockModel) {
				$locales = array_flip($locales);
			}

			$defaultTargets = array_key_exists($this->_element->locale, $pluginSettings->localeDefaults) ? $pluginSettings->localeDefaults[$this->_element->locale]['targets'] : [];
			$elementTargets = $this->_elementSettings['targets'];
			$targets = [];

			if (!empty($elementTargets)) {
				$targets = $elementTargets;
			} elseif (!empty($defaultTargets)) {
				$targets = $defaultTargets;
			}

			foreach ($locales as $localeId => $localeInfo)
			{
				$localizedElement = craft()->elements->getElementById($this->_element->id, $this->_element->elementType, $localeId);
				$matchingTarget = $targets === '*' || in_array($localeId, $targets);
				$updates = false;

				if ($localizedElement && $matchingTarget && $this->_element->locale !== $localeId) {
					foreach ($localizedElement->getFieldLayout()->getFields() as $fieldLayoutField) {
						$field = $fieldLayoutField->getField();

						if ($this->updateElement($localizedElement, $field)) {
							$updates = true;
						}
					}

					if ($this->updateElement($localizedElement, 'title')) {
						$updates = true;
					}

				}

				if ($updates) {
					craft()->content->saveContent($localizedElement, false, false);

					if ($localizedElement instanceof EntryModel) {
						craft()->entryRevisions->saveVersion($localizedElement);
					}
				}
			}
		}
	}

	public function updateElement(&$element, $field)
	{
		$update = false;

		if ($field instanceof Fieldmodel) {
			$fieldHandle = $field->handle;
			$translatable = $field->translatable;
		} elseif ($field === 'title') {
			$fieldHandle = $field;
			$translatable = true;
		}

		$matches = $this->_elementBeforeSave->content->$fieldHandle === $element->content->$fieldHandle;
		$overwrite = (isset($this->_elementSettings['overwrite']) && $this->_elementSettings['overwrite']);
		$updateField = $overwrite || $matches;

		if ($updateField && $translatable) {
			$element->content->$fieldHandle = $this->_element->content->$fieldHandle;
			$update = true;
		}

		return $update;
	}
}
