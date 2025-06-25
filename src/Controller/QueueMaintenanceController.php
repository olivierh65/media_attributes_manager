<?php

namespace Drupal\media_attributes_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media_attributes_manager\Service\ExifFieldCreationQueueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for queue maintenance operations.
 */
class QueueMaintenanceController extends ControllerBase {

  /**
   * The queue manager service.
   *
   * @var \Drupal\media_attributes_manager\Service\ExifFieldCreationQueueManager
   */
  protected $queueManager;

  /**
   * Constructs a QueueMaintenanceController object.
   *
   * @param \Drupal\media_attributes_manager\Service\ExifFieldCreationQueueManager $queue_manager
   *   The queue manager service.
   */
  public function __construct(ExifFieldCreationQueueManager $queue_manager) {
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_attributes_manager.exif_field_creation_queue_manager')
    );
  }

  /**
   * Clean stuck items from the EXIF field creation queue.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with cleanup results.
   */
  public function cleanStuckItems(Request $request) {
    try {
      $cleaned = $this->queueManager->cleanStuckItems();
      
      $logger = \Drupal::logger('media_attributes_manager');
      $logger->info('Manual cleanup of stuck queue items completed: @count items cleaned', [
        '@count' => $cleaned
      ]);
      
      return new JsonResponse([
        'success' => TRUE,
        'cleaned' => $cleaned,
        'message' => $this->t('Successfully cleaned @count stuck items from the queue.', [
          '@count' => $cleaned,
        ]),
      ]);
    }
    catch (\Exception $e) {
      $logger = \Drupal::logger('media_attributes_manager');
      $logger->error('Error during manual cleanup of stuck queue items: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Error cleaning stuck items: @error', [
          '@error' => $e->getMessage(),
        ]),
      ], 500);
    }
  }

}
