<?php

namespace Drupal\media_attributes_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\Entity\MediaType;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\media_attributes_manager\Traits\ExifFieldDefinitionsTrait;

/**
 * Configure Media Attributes Manager settings.
 */
class MediaAttributesSettingsForm extends ConfigFormBase {
  use ExifFieldDefinitionsTrait;

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
    
    // Show field creation progress
    $field_creation_progress = $queue_manager->getFieldCreationProgress();
    if ($field_creation_progress['in_progress']) {
      $form['field_creation_status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('Field creation is currently in progress: @count tasks remaining. New field creation will be queued after current tasks complete.', [
            '@count' => $field_creation_progress['items_in_queue'],
          ]) . '</div>',
        '#weight' => -10,
      ];
    }

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

    $form['general']['use_ajax_progress_bar'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use interactive progress bar for EXIF processing'),
      '#default_value' => $config->get('use_ajax_progress_bar') ?? TRUE,
      '#description' => $this->t('When enabled, EXIF data application will show a real-time progress bar. When disabled, uses a simple loading indicator.'),
      '#states' => [
        'visible' => [
          ':input[name="enable_exif_feature"]' => ['checked' => TRUE],
        ],
      ],
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

    // Show field removal queue information if available
    if (\Drupal::hasService('media_attributes_manager.exif_field_removal_queue_manager')) {
      $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');
      $removal_progress = $removal_queue_manager->getFieldRemovalProgress();
      
      if ($removal_progress['in_progress'] || $removal_progress['items_in_queue'] > 0) {
        $form['general']['removal_status'] = [
          '#type' => 'item',
          '#title' => $this->t('Field Removal Status'),
          '#markup' => '<div class="messages messages--warning">' .
            $this->t('Field removal in progress: @progress% complete (@processed/@total tasks)', [
              '@progress' => $removal_progress['progress_percentage'],
              '@processed' => $removal_progress['processed_tasks'],
              '@total' => $removal_progress['total_tasks'],
            ]) .
            '</div>',
        ];

        if ($removal_progress['items_in_queue'] > 0) {
          $form['general']['process_removal_queue'] = [
            '#type' => 'submit',
            '#value' => $this->t('Process Removal Queue Now'),
            '#submit' => ['::processRemovalQueueSubmit'],
            '#limit_validation_errors' => [],
            '#attributes' => [
              'class' => ['button--primary'],
            ],
          ];
        }
      }
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
    $exif_categories = static::getExifFormStructure();

    // Create selection fields for each EXIF category
    foreach ($exif_categories as $category_key => $category) {
      $form['exif_data_selection'][$category_key] = [
        '#type' => 'details',
        '#title' => $this->t($category['title']),
        '#open' => TRUE,
      ];

      foreach ($category['items'] as $item_key => $item_label) {
        $form['exif_data_selection'][$category_key][$item_key] = [
          '#type' => 'checkbox',
          '#title' => $this->t($item_label),
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
      
      $form['exif_media_types']['remove_fields_on_disable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Automatically remove EXIF fields when media types are disabled'),
        '#default_value' => $config->get('remove_fields_on_disable') ?? FALSE,
        '#description' => $this->t('When enabled, EXIF fields will be automatically removed from media types that are unchecked above. <strong>Warning:</strong> This will permanently delete the fields and their data.'),
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
    $config->set('use_ajax_progress_bar', $form_state->getValue('use_ajax_progress_bar'));
    $config->set('auto_create_fields', $form_state->getValue('auto_create_fields'));
    $config->set('remove_fields_on_disable', $form_state->getValue('remove_fields_on_disable'));

    // Save EXIF data selection
    $exif_categories = static::getExifCategoryKeys();
    $all_exif_fields = static::getExifFieldKeys();

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

    // Check if any media types were unchecked (removed from selection)
    $this->handleRemovedMediaTypes($form_state, $config);

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

    // Always try to queue field creation if EXIF fields and media types are selected
    // (regardless of auto_create_fields setting)
    $selected_exif = [];
    $exif_data_selection_values = $form_state->getValue('exif_data_selection');
    if (is_array($exif_data_selection_values)) {
      foreach (static::getExifCategoryKeys() as $category) {
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

    // Queue field creation if both EXIF fields and media types are selected
    if (!empty($selected_exif) && !empty($enabled_media_types)) {
      $queue_manager = \Drupal::service('media_attributes_manager.exif_field_creation_queue_manager');
      $auto_create_enabled = $form_state->getValue('auto_create_fields');
      
      try {
        $queue_manager->queueFieldCreation($selected_exif, $enabled_media_types, $auto_create_enabled);
        
        $this->messenger()->addStatus($this->t('Field creation has been queued for @types with @fields EXIF fields. Fields will be created automatically in the background.', [
          '@types' => implode(', ', $enabled_media_types),
          '@fields' => count($selected_exif),
        ]));
        
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error queuing field creation: @error', [
          '@error' => $e->getMessage(),
        ]));
        
        if (\Drupal::hasService('logger.factory')) {
          $logger = \Drupal::logger('media_attributes_manager');
          $logger->error('Error queuing field creation: @error', [
            '@error' => $e->getMessage(),
          ]);
        }
      }
    } else {
      // Only show warnings if EXIF feature is enabled but requirements aren't met
      if ($form_state->getValue('enable_exif_feature')) {
        if (empty($selected_exif)) {
          $this->messenger()->addWarning($this->t('No EXIF fields selected. Please select at least one EXIF data type to enable field creation.'));
        }
        if (empty($enabled_media_types)) {
          $this->messenger()->addWarning($this->t('No media types selected. Please select at least one media type to enable field creation.'));
        }
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
   * Submit handler for processing the removal queue manually.
   */
  public function processRemovalQueueSubmit(array &$form, FormStateInterface $form_state) {
    $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');
    $processed = $removal_queue_manager->processQueue(10); // Process up to 10 items

    if ($processed > 0) {
      $message = \Drupal::translation()->formatPlural(
        $processed,
        'Successfully processed 1 field removal task.',
        'Successfully processed @count field removal tasks.'
      );
      $this->messenger()->addStatus($message);
    } else {
      $this->messenger()->addWarning($this->t('No field removal tasks were found in the queue.'));
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

  /**
   * Handle removal of EXIF fields when media types are unchecked.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   */
  protected function handleRemovedMediaTypes(FormStateInterface $form_state, $config) {
    // Get current enabled media types from config
    $current_enabled = $config->get('exif_enabled_media_types') ?: [];
    
    // Get newly selected media types from form
    $new_enabled_raw = $form_state->getValue('exif_enabled_media_types');
    $new_enabled = [];
    
    if (is_array($new_enabled_raw)) {
      // Filter out unchecked values (Drupal checkboxes return 0 for unchecked)
      $new_enabled_filtered = array_filter($new_enabled_raw);
      $new_enabled = array_keys($new_enabled_filtered);
    }
    
    // Find media types that were removed (present in current but not in new)
    $removed_media_types = array_diff($current_enabled, $new_enabled);
    
    if (!empty($removed_media_types)) {
      if (\Drupal::hasService('logger.factory')) {
        $logger = \Drupal::logger('media_attributes_manager');
        $logger->info('Media types removed from EXIF processing: @types', [
          '@types' => implode(', ', $removed_media_types)
        ]);
      }
      
      // Ask user if they want to remove fields
      $remove_fields = $form_state->getValue('remove_fields_on_disable');
      
      if ($remove_fields) {
        // Use queue system for field removal
        $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');
        
        // Get all EXIF fields that might exist on these media types
        $fields_to_remove = [];
        $field_manager = \Drupal::service('entity_field.manager');
        
        foreach ($removed_media_types as $media_type_id) {
          $field_definitions = $field_manager->getFieldDefinitions('media', $media_type_id);
          
          foreach ($field_definitions as $field_name => $field_definition) {
            if (strpos($field_name, 'field_exif_') === 0) {
              $fields_to_remove[] = $field_name;
            }
          }
        }
        
        if (!empty($fields_to_remove)) {
          // Remove duplicates
          $fields_to_remove = array_unique($fields_to_remove);
          
          try {
            $removal_queue_manager->queueFieldRemovalTasks([], $fields_to_remove, $removed_media_types);
            
            $message = \Drupal::translation()->formatPlural(
              count($fields_to_remove),
              'Queued removal of 1 EXIF field from disabled media types. Fields will be removed in the background.',
              'Queued removal of @count EXIF fields from disabled media types. Fields will be removed in the background.'
            );
            $this->messenger()->addStatus($message);
            
          } catch (\Exception $e) {
            $this->messenger()->addError($this->t('Error queuing field removal: @error', [
              '@error' => $e->getMessage(),
            ]));
            
            if (\Drupal::hasService('logger.factory')) {
              $logger = \Drupal::logger('media_attributes_manager');
              $logger->error('Error queuing field removal: @error', [
                '@error' => $e->getMessage(),
              ]);
            }
          }
        }
      } else {
        // Just inform the user that fields still exist
        $type_labels = [];
        foreach ($removed_media_types as $type_id) {
          $media_type = \Drupal\media\Entity\MediaType::load($type_id);
          if ($media_type) {
            $type_labels[] = $media_type->label();
          }
        }
        
        if (!empty($type_labels)) {
          $this->messenger()->addWarning($this->t('EXIF fields still exist on the following media types but will no longer be automatically populated: @types. You can manually remove these fields if needed.', [
            '@types' => implode(', ', $type_labels)
          ]));
        }
      }
    }
  }

}
