<?php

/**
 * @file
 * Contains \Drupal\entity_browser\Plugin\Field\FieldWidget\EntityReference.
 */

namespace Drupal\entity_browser\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\entity_browser\Events\Events;
use Drupal\entity_browser\Events\RegisterJSCallbacks;
use Drupal\entity_browser\FieldWidgetDisplayManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation of the 'entity_reference' widget for entity browser.

 * @FieldWidget(
 *   id = "entity_browser_entity_reference",
 *   label = @Translation("Entity browser"),
 *   description = @Translation("Uses entity browser to select entities."),
 *   multiple_values = TRUE,
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
   * Field widget display plugin manager.
   *
   * @var \Drupal\entity_browser\FieldWidgetDisplayManager
   */
  protected $fieldDisplayManager;

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
   * @param \Drupal\entity_browser\FieldWidgetDisplayManager $field_display_manager
   *   Field widget display plugin manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityManagerInterface $entity_manager, EventDispatcherInterface $event_dispatcher, FieldWidgetDisplayManager $field_display_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->entityManager = $entity_manager;
    $this->fieldDisplayManager = $field_display_manager;

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
      $container->get('event_dispatcher'),
      $container->get('plugin.manager.entity_browser.field_widget_display')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'entity_browser' => NULL,
      'field_widget_display' => NULL,
      'field_widget_display_settings' => [],
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $browsers = [];
    /** @var \Drupal\entity_browser\EntityBrowserInterface $browser */
    foreach ($this->entityManager->getStorage('entity_browser')->loadMultiple() as $browser) {
      $browsers[$browser->id()] = $browser->label();
    }

    $element['entity_browser'] = [
      '#title' => t('Entity browser'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('entity_browser'),
      '#options' => $browsers,
    ];

    $displays = [];
    foreach ($this->fieldDisplayManager->getDefinitions() as $id => $definition) {
      $displays[$id] = $definition['label'];
    }

    $id = Html::getUniqueId('field-' . $this->fieldDefinition->getName() . '-display-settings-wrapper');
    $element['field_widget_display'] = [
      '#title' => t('Entity display plugin'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('field_widget_display'),
      '#options' => $displays,
      '#validate' => [[$this, 'submitFieldWidgetDisplay']],
      '#ajax' => [
        'callback' => array($this, 'updateSettingsAjax'),
        'wrapper' => $id,
      ],
    ];

    $element['field_widget_display_settings'] = [
      '#type' => 'fieldset',
      '#title' => t('Entity display plugin configuration'),
      '#tree' => TRUE,
      '#prefix' => '<div id="' . $id . '">',
      '#suffix' => '</div>',
    ];

    if ($this->getSetting('field_widget_display')) {
      $element['field_widget_display_settings'] += $this->fieldDisplayManager
        ->createInstance(
          $form_state->getValue(
            ['fields', $this->fieldDefinition->getName(), 'settings_edit_form', 'settings', 'field_widget_display'],
            $this->getSetting('field_widget_display')
          ),
          $form_state->getValue(
            ['fields', $this->fieldDefinition->getName(), 'settings_edit_form', 'settings', 'field_widget_display_settings'],
            $this->getSetting('field_widget_display_settings')
          ) + ['entity_type' => $this->fieldDefinition->getFieldStorageDefinition()->getSetting('target_type')]
        )
        ->settingsForm($form, $form_state);
    }

    return $element;
  }

  /**
   * Ajax callback that updates field widget display settings fieldset.
   */
  public function updateSettingsAjax(array $form, FormStateInterface $form_state) {
    return $form['fields'][$this->fieldDefinition->getName()]['plugin']['settings_edit_form']['settings']['field_widget_display_settings'];
  }

    /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $entity_browser_id = $this->getSetting('entity_browser');
    $field_widget_display = $this->getSetting('field_widget_display');

    if (empty($entity_browser_id)) {
      return [t('No entity browser selected.')];
    }
    else {
      $browser = $this->entityManager->getStorage('entity_browser')
        ->load($entity_browser_id);
      $summary[] = t('Entity browser: @browser', ['@browser' => $browser->label()]);
    }

    if (!empty($field_widget_display)) {
      $plugin = $this->fieldDisplayManager->getDefinition($field_widget_display);
      $summary[] = t('Entity display: @name', ['@name' => $plugin['label']]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $entity_type = $this->fieldDefinition->getFieldStorageDefinition()->getSetting('target_type');
    $entity_storage = $this->entityManager->getStorage($entity_type);
    $field_widget_display = $this->fieldDisplayManager->createInstance(
      $this->getSetting('field_widget_display'),
      $this->getSetting('field_widget_display_settings') + ['entity_type' => $this->fieldDefinition->getFieldStorageDefinition()->getSetting('target_type')]
    );

    $ids = [];
    if ($form_state->isRebuilding()) {
      if ($value = $form_state->getValue([$this->fieldDefinition->getName(), 'target_id'])) {

        $ids = explode(' ', $value);
        $ids = array_combine($ids, $ids);
        foreach ($items as $item) {
          unset($ids[$item->target_id]);
        }
        foreach ($ids as $id) {
          $items[] = $id;
        }
      }
    }
    else {
      foreach ($items as $item) {
        $ids[] = $item->target_id;
      }
    }
    $ids = array_filter($ids);

    $hidden_id = Html::getUniqueId('edit-' . $this->fieldDefinition->getName() . '-target-id');
    $table_id = Html::getUniqueId('edit-' . $this->fieldDefinition->getName() . '-table');
    $details_id = Html::getUniqueId('edit-' . $this->fieldDefinition->getName());
    $weight_class = Html::cleanCssIdentifier($this->fieldDefinition->getName() . '-weight');

    $element += [
      '#id' => $details_id,
      '#type' => 'details',
      '#open' => !empty($ids),
      'target_id' => [
        '#type' => 'hidden',
        '#id' => $hidden_id,
        // We need to repeat ID here as it is otherwise skipped when rendering.
        '#attributes' => ['id' => $hidden_id],
        '#default_value' => $ids,
        // #ajax is officially not supported for hidden elements but if we
        // specify event manually it works.
        '#ajax' => [
          'callback' => array($this, 'selectEntitiesCallback'),
          'wrapper' => $details_id,
          'event' => 'entity_browser_value_updated',
        ],
      ],
      'entity_browser' => $this->entityManager->getStorage('entity_browser')->load($this->getSetting('entity_browser'))->getDisplay()->displayEntityBrowser(),
      '#attached' => ['library' => ['entity_browser/entity_reference']],
      'current' => [
        '#type' => 'table',
        '#header' => [
          t('File'),
          t('Weight'),
          t('Description'),
          t('Operations'),
        ],
        '#attached' => ['library' => ['core/jquery.ui.sortable']],
        '#attributes' => [
          'class' => ['entities-list'],
          'id' => $table_id,
        ],
        '#tabledrag' => array(
          array(
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => $weight_class,
          ),
        ),
      ]
    ];

    $delta = 0;
    foreach ($items as $item) {
      $entity = $item->entity;

      $operations = array();
      if ($entity->access('update') && $entity->hasLinkTemplate('edit-form')) {
        $operations['edit'] = array(
          'title' => $this->t('Edit'),
          'weight' => 10,
          'url' => $entity->urlInfo('edit-form'),
          'attributes' => array(
            'class' => ['use-ajax'],
            'data-accepts' => 'application/vnd.drupal-modal',
            'data-dialog-options' => '{"width":800}',
          )
        );
      }
      $operations['delete'] = array(
        'title' => $this->t('Delete'),
        'weight' => 100,
        'url' => $entity->urlInfo('delete-form'),
      );

      $element['current'][$delta] = [
        '#attributes' => ['class' => ['draggable']],
        'file' => [
          'display' => $item->view('teaser'),
          'target_id' => [
            '#type' => 'value',
            '#value' => $entity->id(),
          ]
        ],
        '_ŵeight' => array(
          '#type' => 'weight',
          '#title' => t('Weight for row @number', array('@number' => $delta + 1)),
          '#title_display' => 'invisible',
          // Note: this 'delta' is the FAPI #type 'weight' element's property.
          //'#delta' => $max,
          '#default_value' => $delta,
          '#weight' => 100,
          '#attributes' => ['class' => [$weight_class]],
        ),
        'description' => array(
          '#type' => 'textfield',
          '#title' => t('Description'),
          '#title_display' => 'invisible',
          '#default_value' => $item->description,
          '#description' => t('The description may be used as the label of the link to the file.'),
        ),
        'operations' => [
          '#type' => 'operations',
          '#links' => $operations,
        ]

      ];

      $delta++;
    }


    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $ids = empty($values['target_id']) ? [] : explode(' ', $values['target_id']);
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

  /**
   * AJAX form callback for hidden value updated event.
   */
  public function selectEntitiesCallback(array &$form, FormStateInterface $form_state) {
    return $form[$this->fieldDefinition->getName()];
  }

}
