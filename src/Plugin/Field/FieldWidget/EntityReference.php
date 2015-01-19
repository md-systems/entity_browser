<?php

/**
 * @file
 * Contains \Drupal\entity_browser\Plugin\Field\FieldWidget\EntityReference.
 */

namespace Drupal\entity_browser\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\String;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\entity_browser\Events\Events;
use Drupal\entity_browser\Events\RegisterJSCallbacks;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation of the 'entity_reference' widget for entity browser.

 * @FieldWidget(
 *   id = "entity_browser_entity_reference",
 *   label = @Translation("Entity browser"),
 *   description = @Translation("Uses entity browser to select entities."),
 *   multiple_values = FALSE,
 *   field_types = {
 *     "entity_reference", "file"
 *   }
 * )
 */
class EntityReference extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Entity manager service
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Constructs widget plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   Entity manager service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher.
   *
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityManagerInterface $entity_manager, EventDispatcherInterface $event_dispatcher) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->entityManager = $entity_manager;

    $event_dispatcher->addListener(Events::REGISTER_JS_CALLBACKS, [$this, 'registerJSCallback']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity.manager'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'entity_browser' => NULL,
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $options = [];
    /** @var \Drupal\entity_browser\EntityBrowserInterface $browser */
    foreach ($this->entityManager->getStorage('entity_browser')->loadMultiple() as $browser) {
      $options[$browser->id()] = $browser->label();
    }

    $element['entity_browser'] = [
      '#title' => t('Entity browser'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('entity_browser'),
      '#options' => $options,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $entity_browser_id = $this->getSetting('entity_browser');
    if (empty($entity_browser_id)) {
      return [t('No entity browser selected.')];
    }

    $browser = $this->entityManager->getStorage('entity_browser')->load($entity_browser_id);
    return [t('Entity browser: @browser', ['@browser' => $browser->label()])];

  }

  /**
   * {@inheritdoc}
   */
  function formElement1(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $ids = [];
    foreach ($items as $item) {
      $ids[] = $item->target_id;
    }

    return [
      'target_id' => [
        '#type' => 'hidden',
        '#default_value' => $ids,
      ],
      'entity_browser' => $this->entityManager->getStorage('entity_browser')->load($this->getSetting('entity_browser'))->getDisplay()->displayEntityBrowser(),
      '#attached' => ['library' => ['entity_browser/entity_reference']],
      'current' => [
        // TODO - create better display of current entities (possibly using a view)
        // See: https://www.drupal.org/node/2366241
        '#markup' => '<strong>'. t('Selected:') . '</strong> <div class="current-markup">' . implode(' ', $ids) . '</div>',
      ],
    ];
  }

  /**
   * Overrides \Drupal\Core\Field\WidgetBase::formMultipleElements().
   *
   * Special handling for draggable multiple widgets and 'add more' button.
   *
   * @note - copy&paste of FileWidget::formMultipleElements() and modified
   *   a bit.
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $form['#parents'];

    // Load the items for form rebuilds from the field state as they might not
    // be in $form_state->getValues() because of validation limitations. Also,
    // they are only passed in as $items when editing existing entities.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    if (isset($field_state['items'])) {
      $items->setValue($field_state['items']);
    }

    // Determine the number of widgets to display.
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    switch ($cardinality) {
      case FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED:
        $max = count($items);
        $is_multiple = TRUE;
        break;

      default:
        $max = $cardinality - 1;
        $is_multiple = ($cardinality > 1);
        break;
    }

    $title = String::checkPlain($this->fieldDefinition->getLabel());
    $description = $this->fieldFilterXss($this->fieldDefinition->getDescription());

    $elements = array();

    $delta = 0;
    // Add an element for every existing item.
    foreach ($items as $item) {
      $element = array(
        '#title' => $title,
        '#description' => $description,
      );
      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

      if ($element) {
        // Input field for the delta (drag-n-drop reordering).
        if ($is_multiple) {
          // We name the element '_weight' to avoid clashing with elements
          // defined by widget.
          $element['_weight'] = array(
            '#type' => 'weight',
            '#title' => t('Weight for row @number', array('@number' => $delta + 1)),
            '#title_display' => 'invisible',
            // Note: this 'delta' is the FAPI #type 'weight' element's property.
            '#delta' => $max,
            '#default_value' => $item->_weight ?: $delta,
            '#weight' => 100,
          );
        }

        $elements[$delta] = $element;
        $delta++;
      }
    }

    $empty_single_allowed = ($cardinality == 1 && $delta == 0);
    $empty_multiple_allowed = ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED || $delta < $cardinality) && !$form_state->isProgrammed();

    // Add one more empty row for new uploads except when this is a programmed
    // multiple form as it is not necessary.
    // @todo - we probably do not need this and can remove it?
    if (FALSE && ($empty_single_allowed || $empty_multiple_allowed)) {
      $element = array(
        '#title' => $title,
        '#description' => $description,
      );
      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);
      if ($element) {
        $element['#required'] = ($element['#required'] && $delta == 0);
        $elements[$delta] = $element;
      }
    }

    if ($is_multiple) {
      // The group of elements all-together need some extra functionality after
      // building up the full list (like draggable table rows).
      $elements['#file_upload_delta'] = $delta;
      $elements['#type'] = 'details';
      $elements['#open'] = TRUE;
      $elements['#theme'] = 'file_widget_multiple';
      $elements['#theme_wrappers'] = array('details');
      $elements['#process'] = array(array(get_class($this), 'processMultiple'));
      $elements['#title'] = $title;

      $elements['#description'] = $description;
      $elements['#field_name'] = $field_name;
      $elements['#language'] = $items->getLangcode();
      $elements['#display_field'] = (bool) $this->getFieldSetting('display_field');
      // The field settings include defaults for the field type. However, this
      // widget is a base class for other widgets (e.g., ImageWidget) that may
      // act on field types without these expected settings.
      $field_settings = $this->getFieldSettings() + array('display_field' => NULL);
      $elements['#display_field'] = (bool) $field_settings['display_field'];

      $elements['entity_browser'] = $this->entityManager->getStorage('entity_browser')->load($this->getSetting('entity_browser'))->getDisplay()->displayEntityBrowser();
      // Add some properties that will eventually be added to the file upload
      // field. These are added here so that they may be referenced easily
      // through a hook_form_alter().
//      $elements['#file_upload_title'] = t('Add a new file');
//      $elements['#file_upload_description'] = array(
//        '#theme' => 'file_upload_help',
//        '#description' => '',
//        '#upload_validators' => $elements[0]['#upload_validators'],
//        '#cardinality' => $cardinality,
//      );
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   *
   * @note - copy&paste  of FileWidget::formElement() and modified a
   *   little bit.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_settings = $this->getFieldSettings();

    // @todo - do we need this stuff?
    // The field settings include defaults for the field type. However, this
    // widget is a base class for other widgets (e.g., ImageWidget) that may act
    // on field types without these expected settings.
//    $field_settings += array(
//      'display_default' => NULL,
//      'display_field' => NULL,
//      'description_field' => NULL,
//    );

    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $defaults = array(
      'fids' => array(),
      'display' => (bool) $field_settings['display_default'],
      'description' => '',
    );

    // Essentially we use the managed_file type, extended with some
    // enhancements.
    $element_info = element_info('managed_file');
    $element += array(
      '#type' => 'managed_file',
      '#upload_location' => $items[$delta]->getUploadLocation(),
      '#upload_validators' => $items[$delta]->getUploadValidators(),
      '#value_callback' => array(get_class($this), 'value'),
      // @todo - do we need our own processor which expand our stuff, probably yes?
      //'#process' => array_merge($element_info['#process'], array(array(get_class($this), 'process'))),
      '#process' => $element_info['#process'],
      '#progress_indicator' => $this->getSetting('progress_indicator'),
      // Allows this field to return an array instead of a single value.
      '#extended' => TRUE,
      // Add properties needed by value() and process() methods.
      '#field_name' => $this->fieldDefinition->getName(),
      '#entity_type' => $items->getEntity()->getEntityTypeId(),
      '#display_field' => (bool) $field_settings['display_field'],
      '#display_default' => $field_settings['display_default'],
      '#description_field' => $field_settings['description_field'],
      '#cardinality' => $cardinality,
    );

    $element['#weight'] = $delta;

    // Field stores FID value in a single mode, so we need to transform it for
    // form element to recognize it correctly.
    if (!isset($items[$delta]->fids) && isset($items[$delta]->target_id)) {
      $items[$delta]->fids = array($items[$delta]->target_id);
    }
    $element['#default_value'] = $items[$delta]->getValue() + $defaults;

    $default_fids = $element['#extended'] ? $element['#default_value']['fids'] : $element['#default_value'];
    if (empty($default_fids)) {
      $element['entity_browser'] = $this->entityManager->getStorage('entity_browser')->load($this->getSetting('entity_browser'))->getDisplay()->displayEntityBrowser();
      // @todo - we need our own upload control stuff here...
//      $file_upload_help = array(
//        '#theme' => 'file_upload_help',
//        '#description' => $element['#description'],
//        '#upload_validators' => $element['#upload_validators'],
//        '#cardinality' => $cardinality,
//      );
//      $element['#description'] = drupal_render($file_upload_help);
      $element['#multiple'] = $cardinality != 1 ? TRUE : FALSE;
      if ($cardinality != 1 && $cardinality != -1) {
        $element['#element_validate'] = array(array(get_class($this), 'validateMultipleCount'));
      }
    }

    return $element;
  }

  /**
   * Form API callback: Processes a group of file_generic field elements.
   *
   * Adds the weight field to each row so it can be ordered and adds a new Ajax
   * wrapper around the entire group so it can be replaced all at once.
   *
   * This method on is assigned as a #process callback in formMultipleElements()
   * method.
   *
   * @note - copy&paste of FileWidget::processMultiple().
   */
  public static function processMultiple($element, FormStateInterface $form_state, $form) {
    $element_children = Element::children($element, TRUE);
    $count = count($element_children);

    foreach ($element_children as $delta => $key) {
      if ($key != $element['#file_upload_delta']) {
        $description = static::getDescriptionFromElement($element[$key]);
        $element[$key]['_weight'] = array(
          '#type' => 'weight',
          '#title' => $description ? t('Weight for @title', array('@title' => $description)) : t('Weight for new file'),
          '#title_display' => 'invisible',
          '#delta' => $count,
          '#default_value' => $delta,
        );
      }
      else {
        // The title needs to be assigned to the upload field so that validation
        // errors include the correct widget label.
        $element[$key]['#title'] = $element['#title'];
        $element[$key]['_weight'] = array(
          '#type' => 'hidden',
          '#default_value' => $delta,
        );
      }
    }

    // Add a new wrapper around all the elements for Ajax replacement.
    $element['#prefix'] = '<div id="' . $element['#id'] . '-ajax-wrapper">';
    $element['#suffix'] = '</div>';

    return $element;
  }

  /**
   * Retrieves the file description from a field field element.
   *
   * This helper static method is used by processMultiple() method.
   *
   * @param array $element
   *   An associative array with the element being processed.
   *
   * @return array|false
   *   A description of the file suitable for use in the administrative
   *   interface.
   *
   * @note - copy&paste of FileWidget::getDescriptionFromElement().
   */
  protected static function getDescriptionFromElement($element) {
    // Use the actual file description, if it's available.
    if (!empty($element['#default_value']['description'])) {
      return $element['#default_value']['description'];
    }
    // Otherwise, fall back to the filename.
    if (!empty($element['#default_value']['filename'])) {
      return $element['#default_value']['filename'];
    }
    // This is probably a newly uploaded file; no description is available.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $ids = explode(' ', $values['target_id']);
    $return = [];
    foreach ($ids as $id) {
      $return[]['target_id'] = $id;
    }

    return $return;
  }

  /**
   * Registers JS callback that gets entities from entity browser and updates
   * form values accordingly.
   */
  public function registerJSCallback(RegisterJSCallbacks $event) {
    if ($event->getBrowserID() == $this->getSetting('entity_browser')) {
      $event->registerCallback('Drupal.entityBrowserEntityReference.selectionCompleted');
    }
  }
}
