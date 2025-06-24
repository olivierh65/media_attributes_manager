<?php

namespace Drupal\media_attributes_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\Entity\MediaType;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Media Attributes Manager settings.
 */
class MediaAttributesSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_attributes_manager_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['media_attributes_manager.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('media_attributes_manager.settings');

    // Check and display any field creation results
    $queue_manager = \Drupal::service('media_attributes_manager.exif_field_creation_queue_manager');
    $queue_manager->checkAndDisplayResults();

    // Debug: log current config state
    if (\Drupal::hasService('logger.factory')) {
      $logger = \Drupal::logger('media_attributes_manager');
      $logger->debug('Building form with config: @config', [
        '@config' => json_encode($config->getRawData(), JSON_PRETTY_PRINT)
      ]);
    }

    // Migrate old configuration if necessary
    $this->migrateOldConfiguration($config);

    // General settings section
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['enable_exif_feature'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable EXIF data extraction and application'),
      '#default_value' => $config->get('enable_exif_feature') ?? TRUE,
      '#description' => $this->t('When enabled, the widget will show a button to apply EXIF data to selected media items.'),
    ];

    $form['general']['auto_create_fields'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically create missing fields'),
      '#default_value' => $config->get('auto_create_fields') ?? FALSE,
      '#description' => $this->t('When enabled, the system will automatically create fields for selected EXIF data if they do not exist in the media type. <strong>Note:</strong> Field creation will be processed in the background and may take a few moments.'),
      '#states' => [
        'visible' => [
          ':input[name="enable_exif_feature"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Show queue information if available
    $queue_manager = \Drupal::service('media_attributes_manager.exif_field_creation_queue_manager');
    $queue_info = $queue_manager->getQueueInfo();
    if ($queue_info['items_in_queue'] > 0) {
      $form['general']['queue_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Field Creation Status'),
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('There are currently @count field creation task(s) in the background queue.', ['@count' => $queue_info['items_in_queue']]) .
          '</div>',
      ];

      $form['general']['process_queue'] = [
        '#type' => 'submit',
        '#value' => $this->t('Process Queue Now'),
        '#submit' => ['::processQueueSubmit'],
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['button--primary'],
        ],
        '#description' => $this->t('Click to immediately process the field creation queue instead of waiting for the next cron run.'),
      ];
    }

    // EXIF Field selection section
    $form['exif_data_selection'] = [
      '#type' => 'details',
      '#title' => $this->t('EXIF Data Selection'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_exif_feature"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Available EXIF data grouped by category
    $exif_categories = [
      'computed' => [
        'title' => $this->t('Basic Image Information'),
        'items' => [
          'computed_height' => $this->t('Height (pixels)'),
          'computed_width' => $this->t('Width (pixels)'),
        ],
      ],
      'ifd0' => [
        'title' => $this->t('Camera Information'),
        'items' => [
          'make' => $this->t('Camera Make'),
          'model' => $this->t('Camera Model'),
          'orientation' => $this->t('Orientation'),
          'software' => $this->t('Software'),
          'copyright' => $this->t('Copyright'),
          'artist' => $this->t('Artist/Author'),
        ],
      ],
      'exif' => [
        'title' => $this->t('EXIF Information'),
        'items' => [
          'datetime_original' => $this->t('Date/Time Original'),
          'datetime_digitized' => $this->t('Date/Time Digitized'),
          'exif_image_width' => $this->t('EXIF Image Width'),
          'exif_image_length' => $this->t('EXIF Image Height'),
          'exposure' => $this->t('Exposure Time'),
          'aperture' => $this->t('Aperture (F-Number)'),
          'iso' => $this->t('ISO Speed'),
          'focal_length' => $this->t('Focal Length'),
        ],
      ],
      'gps' => [
        'title' => $this->t('GPS Information'),
        'items' => [
          'gps_latitude' => $this->t('GPS Latitude'),
          'gps_longitude' => $this->t('GPS Longitude'),
          'gps_altitude' => $this->t('GPS Altitude'),
          'gps_date' => $this->t('GPS Date/Time'),
          'gps_coordinates' => $this->t('GPS Coordinates (formatted)'),
        ],
      ],
    ];

    // Create selection fields for each EXIF category
    foreach ($exif_categories as $category_key => $category) {
      $form['exif_data_selection'][$category_key] = [
        '#type' => 'details',
        '#title' => $category['title'],
        '#open' => TRUE,
      ];

      foreach ($category['items'] as $item_key => $item_label) {
        $form['exif_data_selection'][$category_key][$item_key] = [
          '#type' => 'checkbox',
          '#title' => $item_label,
          '#default_value' => $config->get("exif_data_selection.$item_key") ?? FALSE,
          '#description' => $this->t('Extract this data from media files.'),
        ];
      }
    }

    // Media Types Selection for EXIF processing
    $form['exif_media_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Media Types for EXIF Processing'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_exif_feature"]' => ['checked' => TRUE],
        ],
      ],
      '#description' => $this->t('Select which media types should have EXIF fields created and populated automatically.'),
    ];

    // Get all media types
    $media_types = MediaType::loadMultiple();
    $media_type_options = [];

    foreach ($media_types as $type_id => $type) {
      // Only include media types that potentially have EXIF data (e.g., image)
      if ($type->getSource()->getPluginId() == 'image') {
        $media_type_options[$type_id] = $type->label();
      }
    }

    if (!empty($media_type_options)) {
      $form['exif_media_types']['exif_enabled_media_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Enabled Media Types'),
        '#options' => $media_type_options,
        '#default_value' => $config->get('exif_enabled_media_types') ?: array_keys($media_type_options),
        '#description' => $this->t('EXIF fields will be created and populated for the selected media types. If none are selected, all image media types will be processed.'),
      ];
    } else {
      $form['exif_media_types']['no_image_types'] = [
        '#markup' => '<p>' . $this->t('No image media types found.') . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('media_attributes_manager.settings');

    // Debug: log form submission
    if (\Drupal::hasService('logger.factory')) {
      $logger = \Drupal::logger('media_attributes_manager');
      $logger->debug('Form submitted with values: @values', [
        '@values' => json_encode($form_state->getValues(), JSON_PRETTY_PRINT)
      ]);
    }

    // Save general settings
    $config->set('enable_exif_feature', $form_state->getValue('enable_exif_feature'));
    $config->set('auto_create_fields', $form_state->getValue('auto_create_fields'));

    // Save EXIF data selection
    $exif_categories = ['computed', 'ifd0', 'exif', 'gps'];
    $all_exif_fields = [
      'computed_height', 'computed_width',
      'make', 'model', 'orientation', 'software', 'copyright', 'artist',
      'datetime_original', 'datetime_digitized', 'exif_image_width', 'exif_image_length',
      'exposure', 'aperture', 'iso', 'focal_length',
      'gps_latitude', 'gps_longitude', 'gps_altitude', 'gps_date', 'gps_coordinates',
    ];

    // First, reset all EXIF data selection fields to FALSE to ensure clean state
    foreach ($all_exif_fields as $field_key) {
      $config->set("exif_data_selection.$field_key", FALSE);
    }

    // Save selections for all EXIF fields
    $exif_data_selection_values = $form_state->getValue('exif_data_selection');
    if (is_array($exif_data_selection_values)) {
      foreach ($exif_categories as $category) {
        if (isset($exif_data_selection_values[$category]) && is_array($exif_data_selection_values[$category])) {
          foreach ($exif_data_selection_values[$category] as $field_key => $enabled) {
            $config->set("exif_data_selection.$field_key", (bool) $enabled);

            // Debug: log each field being saved
            if (\Drupal::hasService('logger.factory')) {
              $logger = \Drupal::logger('media_attributes_manager');
              $logger->debug('Setting exif_data_selection.@field to @value', [
                '@field' => $field_key,
                '@value' => $enabled ? 'TRUE' : 'FALSE'
              ]);
            }
          }
        }
      }
    }

    // Save selected media types for EXIF processing
    $enabled_media_types = $form_state->getValue('exif_enabled_media_types');
    if (is_array($enabled_media_types)) {
      // Filter out unchecked values (Drupal checkboxes return 0 for unchecked)
      $enabled_media_types = array_filter($enabled_media_types);
      $config->set('exif_enabled_media_types', array_keys($enabled_media_types));
    }

    // If auto-create fields is enabled, we'll schedule field creation on the next page refresh
    // This is just setting a flag - actual field creation will happen in a separate service
    if ($form_state->getValue('auto_create_fields')) {
      $config->set('fields_to_create', TRUE);
    }

    $config->save();

    // If auto-create fields is enabled, queue field creation instead of doing it immediately
    if ($form_state->getValue('auto_create_fields')) {
      // Collect selected EXIF fields from form values
      $selected_exif = [];
      $exif_data_selection_values = $form_state->getValue('exif_data_selection');
      if (is_array($exif_data_selection_values)) {
        foreach (['computed', 'ifd0', 'exif', 'gps'] as $category) {
          if (isset($exif_data_selection_values[$category]) && is_array($exif_data_selection_values[$category])) {
            foreach ($exif_data_selection_values[$category] as $field_key => $enabled) {
              if ($enabled) {
                $selected_exif[] = $field_key;
              }
            }
          }
        }
      }

      // Get selected media types
      $enabled_media_types = $form_state->getValue('exif_enabled_media_types');
      if (is_array($enabled_media_types)) {
        $enabled_media_types = array_filter($enabled_media_types);
        $enabled_media_types = array_keys($enabled_media_types);
      } else {
        $enabled_media_types = [];
      }

      // Queue the field creation instead of doing it immediately
      if (!empty($selected_exif) && !empty($enabled_media_types)) {
        $queue_manager = \Drupal::service('media_attributes_manager.exif_field_creation_queue_manager');
        $queue_manager->queueFieldCreation($selected_exif, $enabled_media_types);
      } else {
        \Drupal::messenger()->addWarning($this->t('No EXIF fields or media types selected for field creation.'));
      }
    }

    // Debug: log final config state after save
    if (\Drupal::hasService('logger.factory')) {
      $logger = \Drupal::logger('media_attributes_manager');
      $logger->debug('Configuration saved. Final state: @config', [
        '@config' => json_encode($config->getRawData(), JSON_PRETTY_PRINT)
      ]);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for processing the queue manually.
   */
  public function processQueueSubmit(array &$form, FormStateInterface $form_state) {
    $queue_manager = \Drupal::service('media_attributes_manager.exif_field_creation_queue_manager');
    $processed = $queue_manager->processQueue(10); // Process up to 10 items

    if ($processed > 0) {
      $message = \Drupal::translation()->formatPlural(
        $processed,
        'Successfully processed 1 field creation task.',
        'Successfully processed @count field creation tasks.'
      );
      $this->messenger()->addStatus($message);
    } else {
      $this->messenger()->addWarning($this->t('No field creation tasks were found in the queue.'));
    }
  }

  /**
   * Migrate old configuration format to new format.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   */
  protected function migrateOldConfiguration($config) {
    // Check if old exif_mappings exists and remove it
    if ($config->get('exif_mappings') !== NULL) {
      $config->clear('exif_mappings');

      // If no media types are selected yet, set all image media types as default
      if (empty($config->get('exif_enabled_media_types'))) {
        $media_types = MediaType::loadMultiple();
        $image_media_types = [];

        foreach ($media_types as $type_id => $type) {
          if ($type->getSource()->getPluginId() == 'image') {
            $image_media_types[] = $type_id;
          }
        }

        $config->set('exif_enabled_media_types', $image_media_types);
      }

      $config->save();

      // Log the migration
      if (\Drupal::hasService('logger.factory')) {
        $logger = \Drupal::logger('media_attributes_manager');
        $logger->info('Migrated old exif_mappings configuration to new format.');
      }
    }
  }

}
