<?php

namespace Drupal\media_attributes_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\media_attributes_manager\Service\ExifFieldRemovalQueueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for EXIF field removal progress.
 */
class ExifFieldRemovalProgressController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The EXIF field removal queue manager.
   *
   * @var \Drupal\media_attributes_manager\Service\ExifFieldRemovalQueueManager
   */
  protected $removalQueueManager;

  /**
   * Constructs a new ExifFieldRemovalProgressController object.
   *
   * @param \Drupal\media_attributes_manager\Service\ExifFieldRemovalQueueManager $removal_queue_manager
   *   The EXIF field removal queue manager.
   */
  public function __construct(ExifFieldRemovalQueueManager $removal_queue_manager) {
    $this->removalQueueManager = $removal_queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_attributes_manager.exif_field_removal_queue_manager')
    );
  }

  /**
   * Get field removal progress status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with progress information.
   */
  public function getProgress() {
    $progress = $this->removalQueueManager->getFieldRemovalProgress();
    $queue_info = $this->removalQueueManager->getQueueInfo();

    $response_data = [
      'in_progress' => $progress['in_progress'],
      'items_in_queue' => $queue_info['number_of_items'],
      'total_tasks' => $progress['total_tasks'],
      'processed_tasks' => $progress['processed_tasks'],
      'progress_percentage' => $progress['progress_percentage'],
      'elapsed_time' => 0,
    ];

    if ($progress['start_time']) {
      $response_data['elapsed_time'] = time() - $progress['start_time'];
    }

    return new JsonResponse($response_data);
  }

  /**
   * Clear stuck field removal queue items.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with cleanup result.
   */
  public function clearStuckItems() {
    try {
      $cleared = $this->removalQueueManager->clearStuckItems();
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Cleared @count stuck field removal queue items.', ['@count' => $cleared]),
        'cleared_items' => $cleared,
      ]);
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Error clearing stuck queue items: @error', ['@error' => $e->getMessage()]),
        'error' => $e->getMessage(),
      ], 500);
    }
  }

}
