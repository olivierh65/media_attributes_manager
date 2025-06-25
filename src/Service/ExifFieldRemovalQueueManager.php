<?php

namespace Drupal\media_attributes_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for managing EXIF field removal queue.
 */
class ExifFieldRemovalQueueManager {
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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The EXIF field manager.
   *
   * @var \Drupal\media_attributes_manager\Service\ExifFieldManager
   */
  protected $exifFieldManager;

  /**
   * Constructs a new ExifFieldRemovalQueueManager object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\media_attributes_manager\Service\ExifFieldManager $exif_field_manager
   *   The EXIF field manager.
   */
  public function __construct(
    QueueFactory $queue_factory,
    StateInterface $state,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ExifFieldManager $exif_field_manager
  ) {
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('media_attributes_manager');
    $this->exifFieldManager = $exif_field_manager;
  }

  /**
   * Queue field removal tasks based on form values.
   *
   * @param array $form_values
   *   The form values containing field removal settings.
   * @param array $fields_to_remove
   *   Array of field names to remove.
   * @param array $media_types
   *   Array of media type IDs.
   */
  public function queueFieldRemovalTasks(array $form_values, array $fields_to_remove, array $media_types) {
    if (empty($fields_to_remove) || empty($media_types)) {
      $this->logger->info('No fields or media types specified for removal, skipping queue creation');
      return;
    }

    $queue = $this->queueFactory->get('exif_field_removal_queue');
    
    // Clear any existing items in the queue
    $queue->deleteQueue();
    $queue->createQueue();

    $total_tasks = 0;

    $this->logger->info('Starting to queue EXIF field removal tasks for @field_count fields and @type_count media types', [
      '@field_count' => count($fields_to_remove),
      '@type_count' => count($media_types)
    ]);

    foreach ($media_types as $media_type_id) {
      foreach ($fields_to_remove as $field_name) {
        // Check if field exists on this media type
        if ($this->exifFieldManager->fieldExists($media_type_id, $field_name)) {
          $task_data = [
            'media_type_id' => $media_type_id,
            'field_name' => $field_name,
            'timestamp' => time(),
          ];

          $queue->createItem($task_data);
          $total_tasks++;

          $this->logger->debug('Queued removal task for field @field on media type @type', [
            '@field' => $field_name,
            '@type' => $media_type_id
          ]);
        }
      }
    }

    // Mark field removal as in progress
    $this->state->set('media_attributes_manager.field_removal_in_progress', TRUE);
    $this->state->set('media_attributes_manager.field_removal_start_time', time());
    $this->state->set('media_attributes_manager.field_removal_total_tasks', $total_tasks);

    $this->logger->info('Queued @count field removal tasks', [
      '@count' => $total_tasks
    ]);
  }

  /**
   * Get information about the field removal queue.
   *
   * @return array
   *   Array containing queue information.
   */
  public function getQueueInfo() {
    $queue = $this->queueFactory->get('exif_field_removal_queue');
    
    return [
      'name' => 'exif_field_removal_queue',
      'number_of_items' => $queue->numberOfItems(),
      'created' => TRUE,
    ];
  }

  /**
   * Get field removal progress information.
   *
   * @return array
   *   Array containing progress information.
   */
  public function getFieldRemovalProgress() {
    $queue = $this->queueFactory->get('exif_field_removal_queue');
    $items_in_queue = $queue->numberOfItems();
    $in_progress = $this->state->get('media_attributes_manager.field_removal_in_progress', FALSE);
    $total_tasks = $this->state->get('media_attributes_manager.field_removal_total_tasks', 0);
    $start_time = $this->state->get('media_attributes_manager.field_removal_start_time', 0);

    // If queue is empty and was in progress, mark as complete
    if ($in_progress && $items_in_queue == 0 && $total_tasks > 0) {
      $this->state->delete('media_attributes_manager.field_removal_in_progress');
      $this->state->delete('media_attributes_manager.field_removal_start_time');
      $this->state->delete('media_attributes_manager.field_removal_total_tasks');
      $in_progress = FALSE;
    }

    return [
      'in_progress' => $in_progress,
      'items_in_queue' => $items_in_queue,
      'total_tasks' => $total_tasks,
      'processed_tasks' => max(0, $total_tasks - $items_in_queue),
      'start_time' => $start_time,
      'progress_percentage' => $total_tasks > 0 ? round((($total_tasks - $items_in_queue) / $total_tasks) * 100, 1) : 0,
    ];
  }

  /**
   * Process field removal queue manually.
   *
   * @param int $max_items
   *   Maximum number of items to process.
   *
   * @return int
   *   Number of items processed.
   */
  public function processQueue($max_items = 10) {
    $queue = $this->queueFactory->get('exif_field_removal_queue');
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('exif_field_removal_queue_worker');
    
    $processed = 0;
    
    while ($processed < $max_items && ($item = $queue->claimItem())) {
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
        
        $this->logger->debug('Processed field removal queue item: @field on @type', [
          '@field' => $item->data['field_name'],
          '@type' => $item->data['media_type_id']
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Error processing field removal queue item: @error', [
          '@error' => $e->getMessage()
        ]);
        
        // Release the item back to the queue for retry
        $queue->releaseItem($item);
        break;
      }
    }
    
    $this->logger->info('Processed @count field removal queue items', [
      '@count' => $processed
    ]);
    
    return $processed;
  }

  /**
   * Clear stuck field removal queue items.
   *
   * @return int
   *   Number of stuck items cleared.
   */
  public function clearStuckItems() {
    $queue = $this->queueFactory->get('exif_field_removal_queue');
    $original_count = $queue->numberOfItems();
    
    // Delete and recreate the queue to clear stuck items
    $queue->deleteQueue();
    $queue->createQueue();
    
    // Reset progress state
    $this->state->delete('media_attributes_manager.field_removal_in_progress');
    $this->state->delete('media_attributes_manager.field_removal_start_time');
    $this->state->delete('media_attributes_manager.field_removal_total_tasks');
    
    $this->logger->info('Cleared @count stuck field removal queue items', [
      '@count' => $original_count
    ]);
    
    return $original_count;
  }

  /**
   * Check if field removal is currently in progress.
   *
   * @return bool
   *   TRUE if field removal is in progress.
   */
  public function isRemovalInProgress() {
    return $this->state->get('media_attributes_manager.field_removal_in_progress', FALSE);
  }

}
