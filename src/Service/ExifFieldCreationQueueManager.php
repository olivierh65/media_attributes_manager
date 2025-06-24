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
  }

  /**
   * Add EXIF field creation task to queue.
   *
   * @param array $selected_exif
   *   Array of selected EXIF field keys.
   * @param array $enabled_media_types
   *   Array of enabled media type IDs.
   */
  public function queueFieldCreation(array $selected_exif, array $enabled_media_types) {
    if (empty($selected_exif) || empty($enabled_media_types)) {
      $this->logger->warning('Cannot queue field creation: missing EXIF fields or media types');
      return;
    }

    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');
    
    $data = [
      'selected_exif' => $selected_exif,
      'enabled_media_types' => $enabled_media_types,
      'queued_at' => time(),
    ];

    $queue->createItem($data);

    // Store queue status for user feedback
    $this->state->set('media_attributes_manager.field_creation_queued', [
      'exif_count' => count($selected_exif),
      'media_types_count' => count($enabled_media_types),
      'queued_at' => time(),
    ]);

    $this->logger->info('Queued EXIF field creation for @exif_count fields and @types_count media types', [
      '@exif_count' => count($selected_exif),
      '@types_count' => count($enabled_media_types),
    ]);

    // Show user message
    $this->messenger->addStatus($this->t('EXIF field creation has been queued and will be processed in the background. You will see a confirmation message when the fields are created.'));
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
    $queue = $this->queueFactory->get('media_attributes_manager_field_creation');
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('media_attributes_manager_field_creation');
    
    $processed = 0;
    while ($processed < $max_items && ($item = $queue->claimItem())) {
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Exception $e) {
        $this->logger->error('Error processing queue item: @error', ['@error' => $e->getMessage()]);
        $queue->releaseItem($item);
        break;
      }
    }

    return $processed;
  }

}
