<?php

namespace Drupal\media_attributes_manager\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media_attributes_manager\Service\ExifDataManager;
use Drupal\media_attributes_manager\Service\ExifFieldManager;
use Drupal\media_attributes_manager\Service\ExifFieldCreationQueueManager;
use Drupal\media\Entity\MediaType;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;
use Drupal\media_attributes_manager\Traits\ExifFieldDefinitionsTrait;

/**
 * Drush commands for Media Attributes Manager.
 */
class MediaAttributesCommands extends DrushCommands {
  use ExifFieldDefinitionsTrait;

  /**
   * Get the EXIF data manager service.
   *
   * @return \Drupal\media_attributes_manager\Service\ExifDataManager
   */
  protected function exifDataManager() {
    return \Drupal::service('media_attributes_manager.exif_data_manager');
  }

  /**
   * Get the EXIF field manager service.
   *
   * @return \Drupal\media_attributes_manager\Service\ExifFieldManager
   */
  protected function exifFieldManager() {
    return \Drupal::service('media_attributes_manager.exif_field_manager');
  }

  /**
   * Get the queue manager service.
   *
   * @return \Drupal\media_attributes_manager\Service\ExifFieldCreationQueueManager
   */
  protected function queueManager() {
    return \Drupal::service('media_attributes_manager.exif_field_creation_queue_manager');
  }

  /**
   * Get the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected function entityTypeManager() {
    return \Drupal::entityTypeManager();
  }

  /**
   * Create EXIF fields for media types.
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option media-types
   *   Comma-separated list of media type IDs to process. If not specified, all image media types will be processed.
   * @option exif-fields
   *   Comma-separated list of EXIF fields to create. If not specified, all configured fields will be created.
   * @option force
   *   Force creation even if auto-create is disabled.
   *
   * @command media-attributes:create-fields
   * @aliases ma:cf
   * @usage media-attributes:create-fields
   *   Create EXIF fields for all configured media types and EXIF data.
   * @usage media-attributes:create-fields --media-types=photo,gallery
   *   Create EXIF fields only for 'photo' and 'gallery' media types.
   * @usage media-attributes:create-fields --exif-fields=make,model,gps_latitude
   *   Create only specified EXIF fields.
   * @usage media-attributes:create-fields --force
   *   Force field creation even if auto-create is disabled.
   */
  public function createFields(array $options = [
    'media-types' => NULL,
    'exif-fields' => NULL,
    'force' => FALSE,
  ]) {
    $this->output()->writeln('Starting EXIF field creation process...');

    $config = \Drupal::config('media_attributes_manager.settings');

    // Check if auto-create is enabled unless forced
    if (!$options['force'] && !$config->get('auto_create_fields')) {
      $this->logger()->error('Auto-create fields is disabled. Use --force to override.');
      return;
    }

    // Get selected EXIF fields
    $selected_exif = [];
    if ($options['exif-fields']) {
      $selected_exif = StringUtils::csvToArray($options['exif-fields']);
    } else {
      // Get from configuration
      foreach ($config->get() as $key => $value) {
        if (strpos($key, 'exif_data_selection.') === 0 && $value === TRUE) {
          $exif_key = substr($key, strlen('exif_data_selection.'));
          $selected_exif[] = $exif_key;
        }
      }
    }

    if (empty($selected_exif)) {
      $this->logger()->error('No EXIF fields specified or configured.');
      return;
    }

    // Get selected media types
    $enabled_media_types = [];
    if ($options['media-types']) {
      $enabled_media_types = StringUtils::csvToArray($options['media-types']);
    } else {
      $enabled_media_types = $config->get('exif_enabled_media_types') ?: [];
    }

    $this->output()->writeln(sprintf('EXIF fields to create: %s', implode(', ', $selected_exif)));
    $this->output()->writeln(sprintf('Media types: %s', empty($enabled_media_types) ? 'all image types' : implode(', ', $enabled_media_types)));

    // Count fields to create
    $fields_to_create = $this->exifFieldManager()->countFieldsToCreate($selected_exif, $enabled_media_types, TRUE);

    if ($fields_to_create === 0) {
      $this->output()->writeln('No new fields need to be created. All specified fields already exist.');
      return;
    }

    $this->output()->writeln(sprintf('Will create %d new EXIF fields.', $fields_to_create));

    if (!$this->io()->confirm('Do you want to continue?')) {
      $this->output()->writeln('Operation cancelled.');
      return;
    }

    // Create fields
    $start_time = microtime(true);
    $fields_created = $this->exifFieldManager()->createExifFieldsFromForm($selected_exif, $enabled_media_types, TRUE);
    $end_time = microtime(true);

    $execution_time = round($end_time - $start_time, 2);

    if ($fields_created > 0) {
      $this->logger()->success(sprintf('Successfully created %d EXIF fields in %s seconds.', $fields_created, $execution_time));
    } else {
      $this->logger()->warning('No fields were created.');
    }
  }

  /**
   * Apply EXIF data to media entities.
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option media-types
   *   Comma-separated list of media type IDs to process. If not specified, all image media types will be processed.
   * @option batch-size
   *   Number of media entities to process in each batch (default: 50).
   * @option limit
   *   Maximum number of media entities to process (default: no limit).
   * @option media-ids
   *   Comma-separated list of specific media IDs to process.
   * @option debug
   *   Enable debug output to show configuration and detailed processing information.
   *
   * @command media-attributes:apply-exif
   * @aliases ma:ae
   * @usage media-attributes:apply-exif
   *   Apply EXIF data to all image media entities.
   * @usage media-attributes:apply-exif --media-types=photo,gallery
   *   Apply EXIF data only to 'photo' and 'gallery' media types.
   * @usage media-attributes:apply-exif --batch-size=20 --limit=100
   *   Process 100 media entities in batches of 20.
   * @usage media-attributes:apply-exif --media-ids=123,456,789
   *   Apply EXIF data to specific media entities.
   * @usage media-attributes:apply-exif --debug
   *   Apply EXIF data with debug information.
   */
  public function applyExif(array $options = [
    'media-types' => NULL,
    'batch-size' => 50,
    'limit' => NULL,
    'media-ids' => NULL,
    'debug' => FALSE,
  ]) {
    $this->output()->writeln('Starting EXIF data application process...');

    $config = \Drupal::config('media_attributes_manager.settings');
    if (!$config->get('enable_exif_feature')) {
      $this->logger()->error('EXIF feature is disabled in configuration.');
      return;
    }

    // Debug: Show configuration
    if ($options['debug']) {
      $this->output()->writeln('=== DEBUG: Configuration ===');
      $this->output()->writeln('EXIF feature enabled: ' . ($config->get('enable_exif_feature') ? 'Yes' : 'No'));

      $exif_fields = static::getExifFieldKeys();

      $selected_fields = [];
      foreach ($exif_fields as $field) {
        if ($config->get("exif_data_selection.$field")) {
          $selected_fields[] = $field;
        }
      }

      $this->output()->writeln('Selected EXIF fields: ' . (empty($selected_fields) ? 'NONE' : implode(', ', $selected_fields)));
      $this->output()->writeln('Enabled media types: ' . implode(', ', $config->get('exif_enabled_media_types') ?: []));
      $this->output()->writeln('===========================');
    }

    $batch_size = (int) $options['batch-size'];
    $limit = $options['limit'] ? (int) $options['limit'] : NULL;

    // Get media entities to process
    if ($options['media-ids']) {
      $media_ids = StringUtils::csvToArray($options['media-ids']);
      $media_ids = array_map('intval', $media_ids);
      $total_count = count($media_ids);
    } else {
      $media_ids = $this->getMediaIds($options['media-types'], $limit);
      $total_count = count($media_ids);
    }

    if (empty($media_ids)) {
      $this->output()->writeln('No media entities found to process.');
      return;
    }

    $this->output()->writeln(sprintf('Found %d media entities to process.', $total_count));

    if (!$this->io()->confirm('Do you want to continue?')) {
      $this->output()->writeln('Operation cancelled.');
      return;
    }

    // Process in batches with progress bar
    $processed = 0;
    $updated = 0;
    $start_time = microtime(true);

    $progress = $this->io()->createProgressBar($total_count);
    $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
    $progress->start();

    $batches = array_chunk($media_ids, $batch_size);
    $warnings_count = 0;

    foreach ($batches as $batch) {
      $batch_updated = $this->exifDataManager()->applyExifDataWithProgress(
        $batch,
        function($current, $total, $media_id, $entity_updated) use ($progress) {
          $progress->advance(1);
        }
      );
      $updated += $batch_updated;
      $processed += count($batch);

      // Count warnings without displaying them during progress
      if ($batch_updated == 0) {
        $warnings_count += count($batch);
      }

      // Add some output for large batches (but don't break progress bar)
      if ($batch_size >= 10) {
        $progress->clear();
        $this->output()->writeln(sprintf(' Processed batch: %d updated out of %d processed', $batch_updated, count($batch)));
        $progress->display();
      }
    }

    $progress->finish();
    $this->output()->writeln('');

    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);

    $this->logger()->success(sprintf(
      'EXIF data application completed: %d entities updated out of %d processed in %s seconds.',
      $updated,
      $processed,
      $execution_time
    ));

    // Display warning summary after completion
    if ($warnings_count > 0) {
      $this->output()->writeln(sprintf('⚠ Warning: %d entities had no EXIF data or configuration issues', $warnings_count));
    }
  }

  /**
   * Show EXIF field creation queue status.
   *
   * @command media-attributes:queue-status
   * @aliases ma:qs
   * @usage media-attributes:queue-status
   *   Show the status of EXIF field creation queue.
   */
  public function queueStatus() {
    $queue_info = $this->queueManager()->getQueueInfo();
    $progress = $this->queueManager()->getFieldCreationProgress();

    $this->output()->writeln('EXIF Field Creation Queue Status:');
    $this->output()->writeln(sprintf('Items in queue: %d', $queue_info['items_in_queue']));
    $this->output()->writeln(sprintf('In progress: %s', $progress['in_progress'] ? 'Yes' : 'No'));

    if ($progress['has_stuck_items']) {
      $this->output()->writeln('⚠ Warning: Queue may have stuck items');
    }

    if (!empty($progress['queued_info']['queued_at'])) {
      $queued_time = date('Y-m-d H:i:s', $progress['queued_info']['queued_at']);
      $this->output()->writeln(sprintf('Last queued: %s', $queued_time));
    }
  }

  /**
   * Process EXIF field creation queue.
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option max-items
   *   Maximum number of queue items to process (default: 10).
   *
   * @command media-attributes:process-queue
   * @aliases ma:pq
   * @usage media-attributes:process-queue
   *   Process EXIF field creation queue.
   * @usage media-attributes:process-queue --max-items=5
   *   Process maximum 5 queue items.
   */
  public function processQueue(array $options = ['max-items' => 10]) {
    $max_items = (int) $options['max-items'];

    $this->output()->writeln('Processing EXIF field creation queue...');

    $processed = $this->queueManager()->processQueue($max_items);

    if ($processed > 0) {
      $this->logger()->success(sprintf('Processed %d queue items.', $processed));
    } else {
      $this->output()->writeln('No queue items were processed.');
    }
  }

  /**
   * Clean stuck items from EXIF field creation queue.
   *
   * @command media-attributes:clean-queue
   * @aliases ma:cq
   * @usage media-attributes:clean-queue
   *   Clean stuck items from EXIF field creation queue.
   */
  public function cleanQueue() {
    $this->output()->writeln('Cleaning stuck items from EXIF field creation queue...');

    $cleaned = $this->queueManager()->cleanStuckItems();

    if ($cleaned > 0) {
      $this->logger()->success(sprintf('Cleaned %d stuck items from queue.', $cleaned));
    } else {
      $this->output()->writeln('No stuck items found in queue.');
    }
  }

  /**
   * Get media entity IDs to process.
   *
   * @param string|null $media_types
   *   Comma-separated media type IDs.
   * @param int|null $limit
   *   Maximum number of entities to return.
   *
   * @return array
   *   Array of media entity IDs.
   */
  protected function getMediaIds($media_types = NULL, $limit = NULL) {
    $query = $this->entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE);

    if ($media_types) {
      $types = StringUtils::csvToArray($media_types);
      $query->condition('bundle', $types, 'IN');
    } else {
      // Only get image media types
      $image_types = $this->getImageMediaTypes();
      if (!empty($image_types)) {
        $query->condition('bundle', $image_types, 'IN');
      }
    }

    if ($limit) {
      $query->range(0, $limit);
    }

    $query->sort('created', 'DESC');

    return $query->execute();
  }

  /**
   * Get image media type IDs.
   *
   * @return array
   *   Array of media type IDs that use image source.
   */
  protected function getImageMediaTypes() {
    $media_types = MediaType::loadMultiple();
    $image_types = [];

    foreach ($media_types as $type_id => $type) {
      $source = $type->getSource();
      if ($source && $source->getPluginId() === 'image') {
        $image_types[] = $type_id;
      }
    }

    return $image_types;
  }

  /**
   * Configure EXIF data selection.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @option fields
   *   Comma-separated list of EXIF fields to enable. If not specified, enables basic fields.
   * @option all
   *   Enable all available EXIF fields.
   * @option disable
   *   Disable all EXIF fields.
   *
   * @command media-attributes:configure-exif
   * @aliases ma:ce
   * @usage media-attributes:configure-exif
   *   Enable basic EXIF fields (make, model, datetime_original, gps_coordinates).
   * @usage media-attributes:configure-exif --all
   *   Enable all available EXIF fields.
   * @usage media-attributes:configure-exif --fields=make,model,orientation,iso
   *   Enable specific EXIF fields.
   * @usage media-attributes:configure-exif --disable
   *   Disable all EXIF fields.
   */
  public function configureExif(array $options = [
    'fields' => NULL,
    'all' => FALSE,
    'disable' => FALSE,
  ]) {
    $config = \Drupal::configFactory()->getEditable('media_attributes_manager.settings');

    $exif_fields = static::getExifFieldKeys();

    if ($options['disable']) {
      // Disable all fields
      foreach ($exif_fields as $field) {
        $config->set("exif_data_selection.$field", FALSE);
      }
      $config->save();
      $this->output()->writeln('All EXIF fields have been disabled.');
      return;
    }

    if ($options['all']) {
      // Enable all fields
      foreach ($exif_fields as $field) {
        $config->set("exif_data_selection.$field", TRUE);
      }
      $config->save();
      $this->output()->writeln('All EXIF fields have been enabled.');
      return;
    }

    if ($options['fields']) {
      // Enable specific fields
      $selected_fields = StringUtils::csvToArray($options['fields']);

      // First disable all
      foreach ($exif_fields as $field) {
        $config->set("exif_data_selection.$field", FALSE);
      }

      // Then enable selected
      foreach ($selected_fields as $field) {
        if (in_array($field, $exif_fields)) {
          $config->set("exif_data_selection.$field", TRUE);
        } else {
          $this->output()->writeln("Warning: '$field' is not a valid EXIF field.");
        }
      }

      $config->save();
      $this->output()->writeln(sprintf('Enabled EXIF fields: %s', implode(', ', $selected_fields)));
      return;
    }

    // Default: enable basic fields
    $basic_fields = ['make', 'model', 'datetime_original', 'gps_coordinates', 'orientation', 'iso'];

    foreach ($exif_fields as $field) {
      $config->set("exif_data_selection.$field", in_array($field, $basic_fields));
    }

    $config->save();
    $this->output()->writeln(sprintf('Enabled basic EXIF fields: %s', implode(', ', $basic_fields)));
  }

  /**
   * Remove EXIF fields from media types.
   *
   * @param string $media_types
   *   Comma-separated list of media type IDs.
   *
   * @command ma:remove-fields
   * @aliases ma:rf
   * @option remove-storage Remove field storage if unused
   * @usage drush ma:remove-fields image,document
   *   Remove EXIF fields from image and document media types.
   */
  public function removeExifFields(string $media_types, array $options = ['remove-storage' => FALSE]) {
    $media_type_ids = array_map('trim', explode(',', $media_types));
    $remove_storage = $options['remove-storage'];

    if (empty($media_type_ids)) {
      $this->output()->writeln('<error>Please provide at least one media type ID.</error>');
      return;
    }

    $this->output()->writeln('<info>Removing EXIF fields from media types: ' . implode(', ', $media_type_ids) . '</info>');

    $exif_field_manager = $this->exifFieldManager();
    $fields_removed = $exif_field_manager->removeExifFields($media_type_ids, $remove_storage);

    if ($fields_removed > 0) {
      $this->output()->writeln('<info>Successfully removed ' . $fields_removed . ' EXIF fields.</info>');
    } else {
      $this->output()->writeln('<comment>No EXIF fields found to remove.</comment>');
    }
  }

  /**
   * Queue EXIF field removal for media types (background processing).
   *
   * @param array $options
   *   An associative array of options.
   *
   * @option media-types
   *   Comma-separated list of media type IDs to remove fields from.
   * @option all-exif-fields
   *   Remove all EXIF fields from the specified media types.
   * @option field-names
   *   Comma-separated list of specific field names to remove.
   * @option dry-run
   *   Show what would be removed without actually queuing the tasks.
   *
   * @command media-attributes:queue-remove-fields
   * @aliases ma:qrf
   * @usage media-attributes:queue-remove-fields --media-types=photo,gallery --all-exif-fields
   *   Queue removal of all EXIF fields from 'photo' and 'gallery' media types.
   * @usage media-attributes:queue-remove-fields --media-types=photo --field-names=field_exif_make,field_exif_model
   *   Queue removal of specific EXIF fields from 'photo' media type.
   * @usage media-attributes:queue-remove-fields --media-types=photo --all-exif-fields --dry-run
   *   Show what EXIF fields would be removed without actually queuing.
   */
  public function queueRemoveFields(array $options = [
    'media-types' => NULL,
    'all-exif-fields' => FALSE,
    'field-names' => NULL,
    'dry-run' => FALSE,
  ]) {
    if (empty($options['media-types'])) {
      $this->output()->writeln('<error>Please specify media types with --media-types option.</error>');
      return;
    }

    $media_type_ids = array_map('trim', explode(',', $options['media-types']));
    $fields_to_remove = [];

    // Determine which fields to remove
    if ($options['all-exif-fields']) {
      // Get all EXIF fields from the specified media types
      $field_manager = \Drupal::service('entity_field.manager');

      foreach ($media_type_ids as $media_type_id) {
        $field_definitions = $field_manager->getFieldDefinitions('media', $media_type_id);

        foreach ($field_definitions as $field_name => $field_definition) {
          if (strpos($field_name, 'field_exif_') === 0) {
            $fields_to_remove[] = $field_name;
          }
        }
      }

      // Remove duplicates
      $fields_to_remove = array_unique($fields_to_remove);

    } elseif (!empty($options['field-names'])) {
      $fields_to_remove = array_map('trim', explode(',', $options['field-names']));
    } else {
      $this->output()->writeln('<error>Please specify either --all-exif-fields or --field-names option.</error>');
      return;
    }

    if (empty($fields_to_remove)) {
      $this->output()->writeln('<comment>No EXIF fields found to remove.</comment>');
      return;
    }

    $this->output()->writeln('<info>Fields to remove: ' . implode(', ', $fields_to_remove) . '</info>');
    $this->output()->writeln('<info>Media types: ' . implode(', ', $media_type_ids) . '</info>');

    if ($options['dry-run']) {
      $this->output()->writeln('<comment>DRY RUN: No actual removal tasks will be queued.</comment>');
      return;
    }

    try {
      $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');
      $removal_queue_manager->queueFieldRemovalTasks([], $fields_to_remove, $media_type_ids);

      $this->output()->writeln('<info>Successfully queued field removal tasks. Fields will be removed in the background.</info>');
      $this->output()->writeln('<comment>Use "drush ma:removal-status" to check progress.</comment>');

    } catch (\Exception $e) {
      $this->output()->writeln('<error>Error queuing field removal: ' . $e->getMessage() . '</error>');
      $this->logger()->error('Error queuing field removal: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Show EXIF field removal queue status.
   *
   * @command media-attributes:removal-status
   * @aliases ma:rs
   * @usage media-attributes:removal-status
   *   Show the current status of field removal queue.
   */
  public function removalStatus() {
    $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');

    $queue_info = $removal_queue_manager->getQueueInfo();
    $progress = $removal_queue_manager->getFieldRemovalProgress();

    $this->output()->writeln('=== EXIF Field Removal Queue Status ===');
    $this->output()->writeln('Queue name: ' . $queue_info['name']);
    $this->output()->writeln('Items in queue: ' . $queue_info['number_of_items']);
    $this->output()->writeln('Removal in progress: ' . ($progress['in_progress'] ? 'Yes' : 'No'));

    if ($progress['in_progress']) {
      $this->output()->writeln('Total tasks: ' . $progress['total_tasks']);
      $this->output()->writeln('Processed tasks: ' . $progress['processed_tasks']);
      $this->output()->writeln('Progress: ' . $progress['progress_percentage'] . '%');

      if ($progress['start_time']) {
        $elapsed = time() - $progress['start_time'];
        $this->output()->writeln('Elapsed time: ' . gmdate('H:i:s', $elapsed));
      }
    }
  }

  /**
   * Process EXIF field removal queue manually.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @option max-items
   *   Maximum number of queue items to process (default: 10).
   *
   * @command media-attributes:process-removal-queue
   * @aliases ma:prq
   * @usage media-attributes:process-removal-queue
   *   Process up to 10 field removal queue items.
   * @usage media-attributes:process-removal-queue --max-items=50
   *   Process up to 50 field removal queue items.
   */
  public function processRemovalQueue(array $options = ['max-items' => 10]) {
    $max_items = (int) $options['max-items'];

    if ($max_items <= 0) {
      $this->output()->writeln('<error>Max items must be greater than 0.</error>');
      return;
    }

    $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');

    $this->output()->writeln('<info>Processing field removal queue (max: ' . $max_items . ' items)...</info>');

    $processed = $removal_queue_manager->processQueue($max_items);

    if ($processed > 0) {
      $this->output()->writeln('<info>Successfully processed ' . $processed . ' field removal tasks.</info>');
    } else {
      $this->output()->writeln('<comment>No field removal tasks found in queue.</comment>');
    }

    // Show updated status
    $this->removalStatus();
  }

  /**
   * Clear stuck field removal queue items.
   *
   * @command media-attributes:clear-stuck-removal-queue
   * @aliases ma:csrq
   * @usage media-attributes:clear-stuck-removal-queue
   *   Clear all items from the field removal queue and reset progress.
   */
  public function clearStuckRemovalQueue() {
    $removal_queue_manager = \Drupal::service('media_attributes_manager.exif_field_removal_queue_manager');

    $cleared = $removal_queue_manager->clearStuckItems();

    if ($cleared > 0) {
      $this->output()->writeln('<info>Cleared ' . $cleared . ' stuck field removal queue items.</info>');
    } else {
      $this->output()->writeln('<comment>No stuck field removal queue items found.</comment>');
    }
  }

}
