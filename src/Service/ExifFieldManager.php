<?php

namespace Drupal\media_attributes_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\MediaType;
use Drupal\media_attributes_manager\Traits\ExifFieldDefinitionsTrait;

/**
 * Service for managing EXIF-related fields on media entities.
 */
class ExifFieldManager {
  use StringTranslationTrait;
  use ExifFieldDefinitionsTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ExifFieldManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    ModuleHandlerInterface $module_handler,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger_factory->get('media_attributes_manager');
    $this->messenger = $messenger;
  }

  /**
   * Create fields for missing EXIF data mappings.
   *
   * @return int
   *   Number of fields created.
   */
  public function createExifFields() {
    $config = $this->configFactory->getEditable('media_attributes_manager.settings');

    $this->logger->info('Starting EXIF field creation process');

    // Check if auto-create is enabled and if fields need to be created
    if (!$config->get('auto_create_fields') || !$config->get('fields_to_create')) {
      $this->logger->info('EXIF field creation skipped: auto_create_fields=@auto, fields_to_create=@create', [
        '@auto' => $config->get('auto_create_fields') ? 'true' : 'false',
        '@create' => $config->get('fields_to_create') ? 'true' : 'false',
      ]);
      return 0;
    }

    // Get the selected EXIF data
    $selected_exif = [];
    $all_config = $config->get();

    // Debug: log the full configuration state
    $this->logger->debug('Full configuration state: @config', [
      '@config' => json_encode($all_config, JSON_PRETTY_PRINT)
    ]);

    foreach ($all_config as $key => $value) {
      if (strpos($key, 'exif_data_selection.') === 0 && $value === TRUE) {
        $exif_key = substr($key, strlen('exif_data_selection.'));
        $selected_exif[] = $exif_key;
        $this->logger->debug('Found selected EXIF field: @field', ['@field' => $exif_key]);
      }
    }

    $this->logger->info('Total selected EXIF fields: @count (@fields)', [
      '@count' => count($selected_exif),
      '@fields' => implode(', ', $selected_exif)
    ]);

    if (empty($selected_exif)) {
      $this->logger->warning('No EXIF data selected for extraction, aborting field creation');
      $this->messenger->addWarning($this->t('No EXIF data selected for extraction.'));
      $config->set('fields_to_create', FALSE)->save();
      return 0;
    }

    // Get selected media types for EXIF processing
    $enabled_media_types = $config->get('exif_enabled_media_types') ?: [];
    $this->logger->info('Enabled media types for EXIF processing: @types', [
      '@types' => empty($enabled_media_types) ? 'all' : implode(', ', $enabled_media_types)
    ]);

    // Get all media types
    $media_types = MediaType::loadMultiple();
    $fields_created = 0;
    $processed_types = 0;
    $skipped_types = 0;

    $this->logger->info('Processing @count media types for field creation', [
      '@count' => count($media_types)
    ]);

    foreach ($media_types as $type_id => $type) {
      $this->logger->debug('Processing media type: @type_id (source: @source)', [
        '@type_id' => $type_id,
        '@source' => $type->getSource()->getPluginId()
      ]);

      // Only process image media types
      if ($type->getSource()->getPluginId() != 'image') {
        $this->logger->debug('Skipping media type @type_id: not an image type', ['@type_id' => $type_id]);
        $skipped_types++;
        continue;
      }

      // Skip if this media type is not selected for EXIF processing
      if (!empty($enabled_media_types) && !in_array($type_id, $enabled_media_types)) {
        $this->logger->debug('Skipping media type @type_id: not enabled for EXIF processing', ['@type_id' => $type_id]);
        $skipped_types++;
        continue;
      }

      $processed_types++;
      $this->logger->info('Creating EXIF fields for media type: @type_id', ['@type_id' => $type_id]);

      // Get existing field definitions
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', $type_id);
      $existing_fields = array_keys($field_definitions);
      $this->logger->debug('Media type @type_id has @count existing fields', [
        '@type_id' => $type_id,
        '@count' => count($existing_fields)
      ]);

      // Define field type mapping for each EXIF data type
      $field_type_map = static::getExifFieldTypeMap();

      $type_fields_created = 0;
      $type_fields_skipped = 0;

      // Create fields for each selected EXIF data
      foreach ($selected_exif as $exif_key) {
        if (isset($field_type_map[$exif_key])) {
          $field_name = static::generateExifFieldName($exif_key);

          // Check if the field already exists
          if (!isset($field_definitions[$field_name])) {
            $this->logger->debug('Creating field @field_name for EXIF key @exif_key on media type @type_id', [
              '@field_name' => $field_name,
              '@exif_key' => $exif_key,
              '@type_id' => $type_id
            ]);

            // Create the field
            $created = $this->createField(
              $type_id,
              $field_name,
              $exif_key,
              $field_type_map[$exif_key]['type'],
              $field_type_map[$exif_key]['settings'] ?? []
            );

            if ($created) {
              $fields_created++;
              $type_fields_created++;
              $this->logger->info('Successfully created field @field_name on media type @type_id', [
                '@field_name' => $field_name,
                '@type_id' => $type_id
              ]);
            } else {
              $this->logger->error('Failed to create field @field_name on media type @type_id', [
                '@field_name' => $field_name,
                '@type_id' => $type_id
              ]);
            }
          } else {
            $type_fields_skipped++;
            $this->logger->debug('Field @field_name already exists on media type @type_id, skipping', [
              '@field_name' => $field_name,
              '@type_id' => $type_id
            ]);
          }
        } else {
          $this->logger->warning('No field type mapping found for EXIF key: @exif_key', [
            '@exif_key' => $exif_key
          ]);
        }
      }

      $this->logger->info('Media type @type_id processing complete: @created created, @skipped skipped', [
        '@type_id' => $type_id,
        '@created' => $type_fields_created,
        '@skipped' => $type_fields_skipped
      ]);
    }

    // Save the updated config
    $config->set('fields_to_create', FALSE)->save();
    $this->logger->info('EXIF field creation process completed');

    $this->logger->info('Final statistics: @total_created fields created, @processed types processed, @skipped types skipped', [
      '@total_created' => $fields_created,
      '@processed' => $processed_types,
      '@skipped' => $skipped_types
    ]);

    if ($fields_created > 0) {
      $this->messenger->addStatus($this->t('@count EXIF fields were created successfully.', [
        '@count' => $fields_created,
      ]));
    }

    return $fields_created;
  }

  /**
   * Create a field on a media type for an EXIF data key.
   *
   * @param string $type_id
   *   The media type ID.
   * @param string $field_name
   *   The field name to create.
   * @param string $exif_key
   *   The EXIF data key.
   * @param string $field_type
   *   The field type.
   * @param array $field_settings
   *   Additional field settings.
   *
   * @return bool
   *   TRUE if the field was created, FALSE otherwise.
   */
  protected function createField($type_id, $field_name, $exif_key, $field_type, array $field_settings = []) {
    $this->logger->debug('Starting field creation: @field_name (@field_type) for media type @type_id', [
      '@field_name' => $field_name,
      '@field_type' => $field_type,
      '@type_id' => $type_id
    ]);

    try {
      // Get the field labels from the map
      $field_labels = static::getExifFieldLabelMap();
      $label = isset($field_labels[$exif_key]) ? $this->t($field_labels[$exif_key]) : $this->t('EXIF @key', ['@key' => str_replace('_', ' ', $exif_key)]);

      $this->logger->debug('Field label determined: @label', ['@label' => $label]);

      // Check if the field storage already exists
      $field_storage = FieldStorageConfig::loadByName('media', $field_name);

      if (!$field_storage) {
        $this->logger->debug('Creating field storage for @field_name', ['@field_name' => $field_name]);

        // Create the field storage
        $storage_settings = [];

        // Special handling for entity reference fields
        if ($field_type == 'entity_reference') {
          $storage_settings['target_type'] = 'taxonomy_term';
          $this->logger->debug('Configured entity reference field to target taxonomy terms');
        }

        $field_storage_config = [
          'field_name' => $field_name,
          'entity_type' => 'media',
          'type' => $field_type,
          'cardinality' => 1,
          'settings' => $storage_settings,
        ];

        $this->logger->debug('Field storage configuration: @config', [
          '@config' => json_encode($field_storage_config, JSON_PRETTY_PRINT)
        ]);

        FieldStorageConfig::create($field_storage_config)->save();
        $this->logger->info('Field storage created successfully for @field_name', ['@field_name' => $field_name]);
      } else {
        $this->logger->debug('Field storage already exists for @field_name', ['@field_name' => $field_name]);
      }

      // Create the field instance
      $field_config = [
        'field_name' => $field_name,
        'entity_type' => 'media',
        'bundle' => $type_id,
        'label' => $label,
        'required' => FALSE,
        'settings' => $field_settings,
      ];

      $this->logger->debug('Field instance configuration: @config', [
        '@config' => json_encode($field_config, JSON_PRETTY_PRINT)
      ]);

      $field = FieldConfig::create($field_config);
      $field->save();
      $this->logger->info('Field instance created successfully for @field_name on @type_id', [
        '@field_name' => $field_name,
        '@type_id' => $type_id
      ]);

      // Configure form display
      try {
        $this->logger->debug('Configuring form display for @field_name', ['@field_name' => $field_name]);
        $form_display = $this->entityTypeManager->getStorage('entity_form_display')
          ->load('media.' . $type_id . '.default');
        if ($form_display) {
          $widget_type = $this->getFormWidgetType($field_type);
          $this->logger->debug('Using form widget type: @widget_type', ['@widget_type' => $widget_type]);

          $form_display->setComponent($field_name, [
            'type' => $widget_type,
            'weight' => 50,
          ]);
          $form_display->save();
          $this->logger->debug('Form display configured successfully for @field_name', ['@field_name' => $field_name]);
        } else {
          $this->logger->warning('Form display not found for media.@type_id.default', ['@type_id' => $type_id]);
        }
      } catch (\Exception $e) {
        $this->logger->warning('Could not configure form display for field @field: @error', [
          '@field' => $field_name,
          '@error' => $e->getMessage(),
        ]);
      }

      // Configure view display
      try {
        $this->logger->debug('Configuring view display for @field_name', ['@field_name' => $field_name]);
        $view_display = $this->entityTypeManager->getStorage('entity_view_display')
          ->load('media.' . $type_id . '.default');
        if ($view_display) {
          $widget_type = $this->getViewWidgetType($field_type);
          $this->logger->debug('Using view widget type: @widget_type', ['@widget_type' => $widget_type]);

          $view_display->setComponent($field_name, [
            'type' => $widget_type,
            'weight' => 50,
          ]);
          $view_display->save();
          $this->logger->debug('View display configured successfully for @field_name', ['@field_name' => $field_name]);
        } else {
          $this->logger->warning('View display not found for media.@type_id.default', ['@type_id' => $type_id]);
        }
      } catch (\Exception $e) {
        $this->logger->warning('Could not configure view display for field @field: @error', [
          '@field' => $field_name,
          '@error' => $e->getMessage(),
        ]);
      }

      $this->logger->info('Created EXIF field @field for media type @type', [
        '@field' => $field_name,
        '@type' => $type_id,
      ]);

      // Invalidate relevant caches so the new field is immediately available
      $this->logger->debug('Invalidating caches for new field @field_name', ['@field_name' => $field_name]);
      \Drupal::service('cache_tags.invalidator')->invalidateTags([
        'entity_field_info',
        'entity_types',
        'rendered',
        'form:' . $type_id,
      ]);

      // Clear the entity field manager cache
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
      $this->logger->debug('Cache invalidation completed for @field_name', ['@field_name' => $field_name]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create EXIF field @field: @error', [
        '@field' => $field_name,
        '@error' => $e->getMessage(),
      ]);

      $this->logger->error('Exception details: @details', [
        '@details' => $e->getTraceAsString()
      ]);

      $this->messenger->addError($this->t('Could not create field @field: @message', [
        '@field' => $field_name,
        '@message' => $e->getMessage(),
      ]));

      return FALSE;
    }
  }

  /**
   * Create EXIF fields on demand, bypassing the fields_to_create flag.
   *
   * This method can be called from the widget to ensure fields exist
   * before applying EXIF data.
   *
   * @return int
   *   Number of fields created.
   */
  public function createExifFieldsOnDemand() {
    $config = $this->configFactory->getEditable('media_attributes_manager.settings');

    // Check if auto-create is enabled
    if (!$config->get('auto_create_fields')) {
      return 0;
    }

    // Get the selected EXIF data
    $selected_exif = [];
    foreach ($config->get() as $key => $value) {
      if (strpos($key, 'exif_data_selection.') === 0 && $value === TRUE) {
        $exif_key = substr($key, strlen('exif_data_selection.'));
        $selected_exif[] = $exif_key;
      }
    }

    if (empty($selected_exif)) {
      $this->messenger->addWarning($this->t('No EXIF data selected for extraction.'));
      return 0;
    }

    // Get selected media types for EXIF processing
    $enabled_media_types = $config->get('exif_enabled_media_types') ?: [];

    // Get all media types
    $media_types = MediaType::loadMultiple();
    $fields_created = 0;

    foreach ($media_types as $type_id => $type) {
      // Only process image media types
      if ($type->getSource()->getPluginId() != 'image') {
        continue;
      }

      // Skip if this media type is not selected for EXIF processing
      if (!empty($enabled_media_types) && !in_array($type_id, $enabled_media_types)) {
        continue;
      }

      // Get existing field definitions
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', $type_id);

      // Define field type mapping for each EXIF data type
      $field_type_map = static::getExifFieldTypeMap();

      // Create fields for each selected EXIF data
      foreach ($selected_exif as $exif_key) {
        if (isset($field_type_map[$exif_key])) {
          $field_name = static::generateExifFieldName($exif_key);

          // Check if the field already exists
          if (!isset($field_definitions[$field_name])) {
            // Create the field
            $created = $this->createField(
              $type_id,
              $field_name,
              $exif_key,
              $field_type_map[$exif_key]['type'],
              $field_type_map[$exif_key]['settings'] ?? []
            );

            if ($created) {
              $fields_created++;
            }
          }
        }
      }
    }

    // Don't set fields_to_create to FALSE since this is on-demand creation

    return $fields_created;
  }

  /**
   * Create EXIF fields with explicit parameters from form submission.
   *
   * This method bypasses configuration loading issues by accepting
   * parameters directly from the form.
   *
   * @param array $selected_exif
   *   Array of selected EXIF field keys.
   * @param array $enabled_media_types
   *   Array of enabled media type IDs.
   * @param bool $auto_create_enabled
   *   Whether auto-create is enabled.
   *
   * @return int
   *   Number of fields created.
   */
  public function createExifFieldsFromForm(array $selected_exif, array $enabled_media_types, bool $auto_create_enabled) {
    if (!$auto_create_enabled) {
      return 0;
    }

    if (empty($selected_exif)) {
      $this->messenger->addWarning($this->t('No EXIF data selected for extraction.'));
      return 0;
    }

    $this->logger->debug('Creating EXIF fields from form with selected fields: @fields and media types: @types', [
      '@fields' => implode(', ', $selected_exif),
      '@types' => implode(', ', $enabled_media_types)
    ]);

    // Get all media types
    $media_types = MediaType::loadMultiple();
    $fields_created = 0;

    foreach ($media_types as $type_id => $type) {
      // Only process image media types
      if ($type->getSource()->getPluginId() != 'image') {
        continue;
      }

      // Skip if this media type is not selected for EXIF processing
      if (!empty($enabled_media_types) && !in_array($type_id, $enabled_media_types)) {
        continue;
      }

      // Get existing field definitions
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', $type_id);

      // Define field type mapping for each EXIF data type
      $field_type_map = static::getExifFieldTypeMap();

      // Create fields for each selected EXIF data
      foreach ($selected_exif as $exif_key) {
        if (isset($field_type_map[$exif_key])) {
          $field_name = static::generateExifFieldName($exif_key);

          // Check if the field already exists
          if (!isset($field_definitions[$field_name])) {
            $this->logger->debug('Creating field @field for media type @type', [
              '@field' => $field_name,
              '@type' => $type_id
            ]);

            // Create the field
            $created = $this->createField(
              $type_id,
              $field_name,
              $exif_key,
              $field_type_map[$exif_key]['type'],
              $field_type_map[$exif_key]['settings'] ?? []
            );

            if ($created) {
              $fields_created++;
            }
          } else {
            $this->logger->debug('Field @field already exists for media type @type', [
              '@field' => $field_name,
              '@type' => $type_id
            ]);
          }
        }
      }
    }

    $this->logger->info('Created @count EXIF fields from form submission', [
      '@count' => $fields_created
    ]);

    return $fields_created;
  }

  /**
   * Get the form widget type for a given field type.
   *
   * @param string $field_type
   *   The field type.
   *
   * @return string
   *   The form widget type.
   */
  protected function getFormWidgetType($field_type) {
    $map = [
      'string' => 'string_textfield',
      'text' => 'text_textfield',
      'text_long' => 'text_textarea',
      'integer' => 'number',
      'decimal' => 'number',
      'datetime' => 'datetime_default',
      'entity_reference' => 'entity_reference_autocomplete',
    ];

    return $map[$field_type] ?? 'string_textfield';
  }

  /**
   * Get the view widget type for a given field type.
   *
   * @param string $field_type
   *   The field type.
   *
   * @return string
   *   The view widget type.
   */
  protected function getViewWidgetType($field_type) {
    $map = [
      'string' => 'string',
      'text' => 'text_default',
      'text_long' => 'text_default',
      'integer' => 'number_integer',
      'decimal' => 'number_decimal',
      'datetime' => 'datetime_default',
      'entity_reference' => 'entity_reference_label',
    ];

    return $map[$field_type] ?? 'string';
  }

  /**
   * Count how many EXIF fields would be created for the given configuration.
   *
   * @param array $selected_exif
   *   Array of selected EXIF field keys.
   * @param array $enabled_media_types
   *   Array of enabled media type IDs.
   * @param bool $auto_create_enabled
   *   Whether auto-create is enabled.
   *
   * @return int
   *   Number of fields that would be created.
   */
  public function countFieldsToCreate(array $selected_exif, array $enabled_media_types, bool $auto_create_enabled) {
    if (empty($selected_exif)) {
      return 0;
    }

    $this->logger->debug('Counting EXIF fields to create with selected fields: @fields and media types: @types', [
      '@fields' => implode(', ', $selected_exif),
      '@types' => implode(', ', $enabled_media_types)
    ]);

    // Get all media types
    $media_types = MediaType::loadMultiple();
    $fields_to_create = 0;

    foreach ($media_types as $type_id => $type) {
      // Only process image media types
      if ($type->getSource()->getPluginId() != 'image') {
        continue;
      }

      // Skip if this media type is not selected for EXIF processing
      if (!empty($enabled_media_types) && !in_array($type_id, $enabled_media_types)) {
        continue;
      }

      // Get existing field definitions
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', $type_id);

      // Define field type mapping for each EXIF data type
      $field_type_map = static::getExifFieldTypeMap();

      // Count fields for each selected EXIF data
      foreach ($selected_exif as $exif_key) {
        if (isset($field_type_map[$exif_key])) {
          $field_name = static::generateExifFieldName($exif_key);

          // Check if the field already exists
          if (!isset($field_definitions[$field_name])) {
            $fields_to_create++;
            $this->logger->debug('Field @field would be created for media type @type', [
              '@field' => $field_name,
              '@type' => $type_id
            ]);
          } else {
            $this->logger->debug('Field @field already exists for media type @type', [
              '@field' => $field_name,
              '@type' => $type_id
            ]);
          }
        }
      }
    }

    $this->logger->info('Would create @count EXIF fields for the given configuration', [
      '@count' => $fields_to_create
    ]);

    return $fields_to_create;
  }

  /**
   * Remove EXIF fields from specified media types.
   *
   * @param array $media_type_ids
   *   Array of media type IDs to remove EXIF fields from.
   * @param bool $remove_storage
   *   Whether to remove field storage (only if no other bundles use it).
   *
   * @return int
   *   Number of fields removed.
   */
  public function removeExifFields(array $media_type_ids, bool $remove_storage = FALSE) {
    if (empty($media_type_ids)) {
      return 0;
    }

    $fields_removed = 0;
    $this->logger->info('Starting EXIF field removal for media types: @types', [
      '@types' => implode(', ', $media_type_ids)
    ]);

    // Get all possible EXIF field names
    $exif_fields = static::getExifFieldKeys();

    foreach ($media_type_ids as $media_type_id) {
      // Check if media type exists
      $media_type = MediaType::load($media_type_id);
      if (!$media_type) {
        $this->logger->warning('Media type @type not found', ['@type' => $media_type_id]);
        continue;
      }

      foreach ($exif_fields as $exif_key) {
        $field_name = static::generateExifFieldName($exif_key);
        
        try {
          // Check if field config exists for this bundle
          $field_config = FieldConfig::loadByName('media', $media_type_id, $field_name);
          if ($field_config) {
            $field_config->delete();
            $fields_removed++;
            
            $this->logger->info('Removed EXIF field @field from media type @type', [
              '@field' => $field_name,
              '@type' => $media_type_id,
            ]);

            // If remove_storage is enabled, check if we should remove the storage too
            if ($remove_storage) {
              $this->removeFieldStorageIfUnused($field_name);
            }
          }
        }
        catch (\Exception $e) {
          $this->logger->error('Error removing EXIF field @field from media type @type: @error', [
            '@field' => $field_name,
            '@type' => $media_type_id,
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }

    if ($fields_removed > 0) {
      // Clear field caches
      $this->entityFieldManager->clearCachedFieldDefinitions();
      
      $this->logger->info('Removed @count EXIF fields from media types', [
        '@count' => $fields_removed
      ]);
      
      $this->messenger->addStatus($this->t('Removed @count EXIF fields from selected media types.', [
        '@count' => $fields_removed,
      ]));
    } else {
      $this->logger->info('No EXIF fields found to remove from specified media types');
    }

    return $fields_removed;
  }

  /**
   * Remove field storage if it's not used by any bundle.
   *
   * @param string $field_name
   *   The field name.
   */
  protected function removeFieldStorageIfUnused(string $field_name) {
    try {
      $field_storage = FieldStorageConfig::loadByName('media', $field_name);
      if ($field_storage) {
        // Check if any other bundles are using this field
        $bundles = $field_storage->getBundles();
        if (empty($bundles)) {
          $field_storage->delete();
          $this->logger->info('Removed unused field storage for @field', [
            '@field' => $field_name,
          ]);
        } else {
          $this->logger->debug('Field storage @field still in use by bundles: @bundles', [
            '@field' => $field_name,
            '@bundles' => implode(', ', $bundles),
          ]);
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking/removing field storage for @field: @error', [
        '@field' => $field_name,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Check if a field exists on a media type.
   *
   * @param string $media_type_id
   *   The media type ID.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field exists, FALSE otherwise.
   */
  public function fieldExists($media_type_id, $field_name) {
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('media', $media_type_id);
    
    return isset($field_definitions[$field_name]);
  }

  /**
   * Remove an EXIF field from a media type.
   *
   * @param string $media_type_id
   *   The media type ID.
   * @param string $field_name
   *   The field name to remove.
   *
   * @return bool
   *   TRUE if the field was successfully removed, FALSE otherwise.
   */
  public function removeExifField($media_type_id, $field_name) {
    try {
      // Check if field exists
      if (!$this->fieldExists($media_type_id, $field_name)) {
        $this->logger->warning('Field @field does not exist on media type @type', [
          '@field' => $field_name,
          '@type' => $media_type_id
        ]);
        return TRUE; // Consider it successful if field doesn't exist
      }

      // Load the field config
      $field_storage_id = "media.$field_name";
      $field_config_id = "media.$media_type_id.$field_name";

      $field_config = \Drupal::entityTypeManager()
        ->getStorage('field_config')
        ->load($field_config_id);

      if ($field_config) {
        $this->logger->debug('Removing field config: @id', ['@id' => $field_config_id]);
        $field_config->delete();
      }

      // Check if this was the last instance of this field
      $remaining_instances = \Drupal::entityTypeManager()
        ->getStorage('field_config')
        ->loadByProperties(['field_name' => $field_name]);

      // If no more instances, remove the field storage
      if (empty($remaining_instances)) {
        $field_storage = \Drupal::entityTypeManager()
          ->getStorage('field_storage_config')
          ->load($field_storage_id);

        if ($field_storage) {
          $this->logger->debug('Removing field storage: @id', ['@id' => $field_storage_id]);
          $field_storage->delete();
        }
      }

      // Purge field data
      field_purge_batch(10);

      $this->logger->info('Successfully removed EXIF field @field from media type @type', [
        '@field' => $field_name,
        '@type' => $media_type_id
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Error removing EXIF field @field from media type @type: @error', [
        '@field' => $field_name,
        '@type' => $media_type_id,
        '@error' => $e->getMessage()
      ]);

      return FALSE;
    }
  }

}
