<?php

namespace Drupal\media_attributes_manager\Service;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for managing EXIF field creation queue.
 */
class ExifFieldCreationQueueManager {
  use StringTranslationTrait;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

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
   * Constructs a new ExifFieldCreationQueueManager object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    QueueFactory $queue_factory,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->logger = $logger_factory->get('media_attributes_manager');
    $this->messenger = $messenger;
  }  /**
   * Add EXIF field creation task to queue.
   *
   * @param array $selected_exif
   *   Array of selected EXIF field keys.
   * @param array $enabled_media_types
   *   Array of enabled media type IDs.
   * @param bool $auto_create_enabled
   *   Whether auto-create is enabled.
   */
  public function queueFieldCreation(array $selected_exif, array $enabled_media_types, bool $auto_create_enabled = TRUE) {
    $this->logger->info('QueueManager: Starting field creation queueing process');

    if (empty($selected_exif) || empty($enabled_media_types)) {
      $this->logger->warning('Cannot queue field creation: missing EXIF fields or media types. EXIF: @exif, Media types: @types', [
        '@exif' => count($selected_exif),
        '@types' => count($enabled_media_types)
      ]);
      return;
    }

    // Check if there are actually fields to create before queueing
    $exif_field_manager = \Drupal::service('media_attributes_manager.exif_field_manager');
    $fields_to_create = $exif_field_manager->countFieldsToCreate($selected_exif, $enabled_media_types, $auto_create_enabled);

    if ($fields_to_create === 0) {
      $this->logger->info('QueueManager: No new fields to create, skipping queue creation');
      $this->messenger->addStatus($this->t('All selected EXIF fields already exist for the chosen media types. No new fields need to be created.'));
      return;
    }

    $this->logger->info('QueueManager: @count fields need to be created, proceeding with queue creation', [
      '@count' => $fields_to_create
    ]);

    $this->logger->debug('QueueManager: Queueing field creation for EXIF fields: @fields', [
      '@fields' => implode(', ', $selected_exif)
    ]);

    $this->logger->debug('QueueManager: Queueing field creation for media types: @types', [
      '@types' => implode(', ', $enabled_media_types)
    ]);

    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');

    $data = [
      'selected_exif' => $selected_exif,
      'enabled_media_types' => $enabled_media_types,
      'auto_create_enabled' => $auto_create_enabled,
      'fields_to_create' => $fields_to_create,
      'queued_at' => time(),
    ];

    $this->logger->debug('QueueManager: Queue data prepared: @data', [
      '@data' => json_encode($data, JSON_PRETTY_PRINT)
    ]);

    $queue->createItem($data);
    $this->logger->info('QueueManager: Item added to queue successfully');

    // Store queue status for user feedback
    $queue_status = [
      'exif_count' => count($selected_exif),
      'media_types_count' => count($enabled_media_types),
      'fields_to_create' => $fields_to_create,
      'queued_at' => time(),
    ];

    $this->state->set('media_attributes_manager.field_creation_queued', $queue_status);

    $this->logger->debug('QueueManager: Queue status stored in state: @status', [
      '@status' => json_encode($queue_status, JSON_PRETTY_PRINT)
    ]);

    $this->logger->info('Queued EXIF field creation for @fields_count fields (@exif_count EXIF types and @types_count media types)', [
      '@fields_count' => $fields_to_create,
      '@exif_count' => count($selected_exif),
      '@types_count' => count($enabled_media_types),
    ]);

    // Show user message
    $this->messenger->addStatus($this->t('EXIF field creation has been queued (@count fields to create) and will be processed in the background. You will see a confirmation message when the fields are created.', [
      '@count' => $fields_to_create
    ]));
    $this->logger->info('QueueManager: Field creation queueing process completed');
  }

  /**
   * Check and display any pending field creation results.
   */
  public function checkAndDisplayResults() {
    // Check for successful field creation
    $success_data = $this->state->get('media_attributes_manager.field_creation_success');
    if ($success_data && isset($success_data['timestamp'])) {
      // Only show message if it's recent (within last 5 minutes)
      if (time() - $success_data['timestamp'] < 300) {
        $field_message = \Drupal::translation()->formatPlural(
          $success_data['fields_created'],
          'One EXIF field has been created automatically in the background.',
          '@count EXIF fields have been created automatically in the background.'
        );
        $this->messenger->addStatus($field_message);

        // Clear the success state
        $this->state->delete('media_attributes_manager.field_creation_success');
      }
    }

    // Check for queued field creation status
    $queued_data = $this->state->get('media_attributes_manager.field_creation_queued');
    if ($queued_data && isset($queued_data['queued_at'])) {
      // Show pending message if queue is recent (within last 2 minutes) and no success yet
      if (time() - $queued_data['queued_at'] < 120 && !$success_data) {
        $this->messenger->addWarning($this->t('EXIF field creation is currently processing in the background. Please refresh the page in a moment to see the results.'));
      } elseif (time() - $queued_data['queued_at'] >= 120) {
        // Clear old queue status
        $this->state->delete('media_attributes_manager.field_creation_queued');
      }
    }
  }

  /**
   * Get queue information.
   *
   * @return array
   *   Array with queue information.
   */
  public function getQueueInfo() {
    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');

    return [
      'items_in_queue' => $queue->numberOfItems(),
      'queue_name' => 'media_attributes_manager_field_creation',
    ];
  }

  /**
   * Process the queue immediately (for testing or manual processing).
   *
   * @param int $max_items
   *   Maximum number of items to process.
   *
   * @return int
   *   Number of items processed.
   */
  public function processQueue($max_items = 1) {
    $this->logger->info('QueueManager: Starting manual queue processing (max @max items)', [
      '@max' => $max_items
    ]);

    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('media_attributes_manager_field_creation');

    $initial_items = $queue->numberOfItems();
    $this->logger->info('QueueManager: Queue has @count items before processing', [
      '@count' => $initial_items
    ]);

    $processed = 0;
    $start_time = microtime(true);

    while ($processed < $max_items && ($item = $queue->claimItem())) {
      $this->logger->debug('QueueManager: Processing queue item @num of @max', [
        '@num' => $processed + 1,
        '@max' => $max_items
      ]);

      try {
        $item_start_time = microtime(true);
        $queue_worker->processItem($item->data);
        $item_end_time = microtime(true);
        $item_execution_time = round($item_end_time - $item_start_time, 2);

        $queue->deleteItem($item);
        $processed++;

        $this->logger->info('QueueManager: Successfully processed queue item in @time seconds', [
          '@time' => $item_execution_time
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('QueueManager: Error processing queue item: @error', [
          '@error' => $e->getMessage()
        ]);

        $this->logger->error('QueueManager: Exception trace: @trace', [
          '@trace' => $e->getTraceAsString()
        ]);

        $queue->releaseItem($item);
        break;
      }
    }

    $end_time = microtime(true);
    $total_execution_time = round($end_time - $start_time, 2);
    $remaining_items = $queue->numberOfItems();

    $this->logger->info('QueueManager: Manual processing completed - @processed items processed in @time seconds, @remaining remaining', [
      '@processed' => $processed,
      '@time' => $total_execution_time,
      '@remaining' => $remaining_items
    ]);

    // Clear queue status if no more items remain
    if ($remaining_items == 0) {
      $this->state->delete('media_attributes_manager.field_creation_queued');
      $this->logger->info('QueueManager: Field creation queue completed and status cleared');
    }

    return $processed;
  }

  /**
   * Check if field creation is currently in progress.
   *
   * @return bool
   *   TRUE if field creation tasks are active, FALSE otherwise.
   */
  public function isFieldCreationInProgress() {
    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');
    return $queue->numberOfItems() > 0;
  }

  /**
   * Get field creation progress information.
   *
   * @return array
   *   Array containing progress information.
   */
  public function getFieldCreationProgress() {
    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');
    $items_in_queue = $queue->numberOfItems();

    // Check if there are items that might be stuck
    $has_stuck_items = false;
    if ($items_in_queue > 0) {
      // Try to claim an item to see if it's accessible
      $test_item = $queue->claimItem(1);
      if ($test_item) {
        // Item is accessible, release it back
        $queue->releaseItem($test_item);
        $this->logger->debug('QueueManager: Queue has @count accessible items', ['@count' => $items_in_queue]);
      } else {
        // Items exist but can't be claimed - they might be stuck
        $has_stuck_items = true;
        $this->logger->warning('QueueManager: Queue reports @count items but none are accessible - possible stuck items', [
          '@count' => $items_in_queue
        ]);
      }
    }

    $queued_info = $this->state->get('media_attributes_manager.field_creation_queued', []);

    // Check if queued info is old (more than 10 minutes)
    $queued_info_expired = false;
    if (!empty($queued_info['queued_at'])) {
      $age_minutes = (time() - $queued_info['queued_at']) / 60;
      if ($age_minutes > 10) {
        $queued_info_expired = true;
        $this->logger->debug('QueueManager: Queued info is @age minutes old, considering expired', [
          '@age' => round($age_minutes, 1)
        ]);
      }
    }

    // Determine if field creation is actually in progress
    $in_progress = false;
    if ($items_in_queue > 0 && !$has_stuck_items) {
      $in_progress = true;
    } elseif (!empty($queued_info['queued_at']) && !$queued_info_expired) {
      // Recently queued but no accessible items - might be processing
      $in_progress = true;
    }

    return [
      'in_progress' => $in_progress,
      'items_in_queue' => $items_in_queue,
      'has_stuck_items' => $has_stuck_items,
      'queued_info' => $queued_info,
      'queued_info_expired' => $queued_info_expired,
    ];
  }

  /**
   * Clean stuck items from the queue.
   *
   * @return int
   *   Number of stuck items cleaned.
   */
  public function cleanStuckItems() {
    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');
    $initial_count = $queue->numberOfItems();

    if ($initial_count == 0) {
      return 0;
    }

    $this->logger->info('QueueManager: Attempting to clean stuck items from queue (@count total items)', [
      '@count' => $initial_count
    ]);

    // Try to claim and process or delete stuck items
    $cleaned = 0;
    $attempts = 0;
    $max_attempts = $initial_count + 5; // Safety limit

    while ($attempts < $max_attempts && $queue->numberOfItems() > 0) {
      $item = $queue->claimItem(1);
      $attempts++;

      if ($item) {
        // Item is accessible, check if it's old
        $age_minutes = (time() - $item->created) / 60;

        if ($age_minutes > 5) {
          // Item is old, delete it
          $queue->deleteItem($item);
          $cleaned++;
          $this->logger->info('QueueManager: Deleted old queue item (age: @age minutes)', [
            '@age' => round($age_minutes, 1)
          ]);
        } else {
          // Item is recent, release it back
          $queue->releaseItem($item);
          break;
        }
      } else {
        // Can't claim items but they exist - force delete the queue
        $this->logger->warning('QueueManager: Found inaccessible items, force deleting queue');
        $queue->deleteQueue();
        $cleaned = $initial_count;
        break;
      }
    }

    // Clear queued status if we cleaned items
    if ($cleaned > 0) {
      $this->state->delete('media_attributes_manager.field_creation_queued');
      $this->logger->info('QueueManager: Cleaned @count stuck items from queue', ['@count' => $cleaned]);
    }

    return $cleaned;
  }

}
