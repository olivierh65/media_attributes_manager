<?php

namespace Drupal\media_attributes_manager\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media_attributes_manager\Service\ExifDataManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for EXIF progress tracking.
 */
class ExifProgressController extends ControllerBase {

  /**
   * The EXIF data manager service.
   *
   * @var \Drupal\media_attributes_manager\Service\ExifDataManager
   */
  protected $exifDataManager;

  /**
   * Constructs a ExifProgressController object.
   *
   * @param \Drupal\media_attributes_manager\Service\ExifDataManager $exif_data_manager
   *   The EXIF data manager service.
   */
  public function __construct(ExifDataManager $exif_data_manager) {
    $this->exifDataManager = $exif_data_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_attributes_manager.exif_data_manager')
    );
  }

  /**
   * Processes EXIF data application with progress tracking.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with progress information.
   */
  public function processExif(Request $request) {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    
    if (!$data || empty($data['media_ids'])) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'No media IDs provided'
      ], 400);
    }

    $media_ids = $data['media_ids'];
    $session_id = $data['session_id'] ?? uniqid('exif_', TRUE);
    
    // Initialize progress tracking in temp store
    $temp_store = $this->tempStoreFactory()->get('media_attributes_manager_progress');
    $progress_data = [
      'current' => 0,
      'total' => count($media_ids),
      'completed' => FALSE,
      'updated_count' => 0,
      'errors' => [],
      'start_time' => time(),
    ];
    $temp_store->set($session_id, $progress_data);

    // Start background processing
    $this->processExifInBackground($media_ids, $session_id);
    
    return new JsonResponse([
      'success' => TRUE,
      'session_id' => $session_id,
      'total' => count($media_ids)
    ]);
  }

  /**
   * Gets progress status for an EXIF processing session.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $session_id
   *   The session ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with current progress.
   */
  public function getProgress(Request $request, $session_id) {
    $temp_store = $this->tempStoreFactory()->get('media_attributes_manager_progress');
    $progress_data = $temp_store->get($session_id);
    
    if (!$progress_data) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Session not found'
      ], 404);
    }
    
    $percent = $progress_data['total'] > 0 ? 
      round(($progress_data['current'] / $progress_data['total']) * 100) : 0;
    
    return new JsonResponse([
      'success' => TRUE,
      'current' => $progress_data['current'],
      'total' => $progress_data['total'],
      'percent' => $percent,
      'completed' => $progress_data['completed'],
      'updated_count' => $progress_data['updated_count'],
      'errors' => $progress_data['errors']
    ]);
  }

  /**
   * Processes EXIF data in background with progress updates.
   *
   * @param array $media_ids
   *   Array of media IDs to process.
   * @param string $session_id
   *   The session ID for progress tracking.
   */
  protected function processExifInBackground(array $media_ids, string $session_id) {
    $temp_store = $this->tempStoreFactory()->get('media_attributes_manager_progress');
    
    // Progress callback that updates the temp store
    $progress_callback = function ($current, $total, $media_id, $updated) use ($temp_store, $session_id) {
      $progress_data = $temp_store->get($session_id) ?: [];
      $progress_data['current'] = $current;
      
      if ($updated) {
        $progress_data['updated_count'] = ($progress_data['updated_count'] ?? 0) + 1;
      }
      
      // Mark as completed if we've processed all items
      if ($current >= $total) {
        $progress_data['completed'] = TRUE;
        $progress_data['end_time'] = time();
      }
      
      $temp_store->set($session_id, $progress_data);
    };

    try {
      // Check and create fields automatically if enabled
      $config = $this->config('media_attributes_manager.settings');
      if ($config->get('auto_create_fields')) {
        $field_manager = \Drupal::service('media_attributes_manager.exif_field_manager');
        $fields_created = $field_manager->createExifFieldsOnDemand();
        
        if ($fields_created > 0) {
          \Drupal::logger('media_attributes_manager')->info('Auto-created @count EXIF fields for progress session @session', [
            '@count' => $fields_created,
            '@session' => $session_id
          ]);
        }
      }

      // Apply EXIF data with progress tracking
      $updated_count = $this->exifDataManager->applyExifDataWithProgress($media_ids, $progress_callback);
      
      // Final update to temp store
      $progress_data = $temp_store->get($session_id) ?: [];
      $progress_data['updated_count'] = $updated_count;
      $progress_data['completed'] = TRUE;
      $progress_data['end_time'] = time();
      $temp_store->set($session_id, $progress_data);
      
      \Drupal::logger('media_attributes_manager')->info('EXIF progress session @session completed: @updated of @total media items updated', [
        '@session' => $session_id,
        '@updated' => $updated_count,
        '@total' => count($media_ids)
      ]);
      
    } catch (\Exception $e) {
      // Log error and mark session as failed
      \Drupal::logger('media_attributes_manager')->error('Error in EXIF progress session @session: @message', [
        '@session' => $session_id,
        '@message' => $e->getMessage()
      ]);
      
      $progress_data = $temp_store->get($session_id) ?: [];
      $progress_data['completed'] = TRUE;
      $progress_data['errors'][] = $e->getMessage();
      $progress_data['end_time'] = time();
      $temp_store->set($session_id, $progress_data);
    }
  }

  /**
   * Gets the temporary store factory.
   *
   * @return \Drupal\Core\TempStore\PrivateTempStoreFactory
   *   The temporary store factory.
   */
  protected function tempStoreFactory() {
    return \Drupal::service('tempstore.private');
  }

}
