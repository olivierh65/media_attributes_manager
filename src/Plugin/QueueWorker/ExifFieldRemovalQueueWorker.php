<?php

namespace Drupal\media_attributes_manager\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\media_attributes_manager\Service\ExifFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for removing EXIF fields.
 *
 * @QueueWorker(
 *   id = "exif_field_removal_queue_worker",
 *   title = @Translation("EXIF Field Removal Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class ExifFieldRemovalQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The EXIF field manager.
   *
   * @var \Drupal\media_attributes_manager\Service\ExifFieldManager
   */
  protected $exifFieldManager;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new ExifFieldRemovalQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\media_attributes_manager\Service\ExifFieldManager $exif_field_manager
   *   The EXIF field manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ExifFieldManager $exif_field_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->exifFieldManager = $exif_field_manager;
    $this->logger = $logger_factory->get('media_attributes_manager');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('media_attributes_manager.exif_field_manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $media_type_id = $data['media_type_id'];
    $field_name = $data['field_name'];

    $this->logger->info('Processing field removal: @field from media type @type', [
      '@field' => $field_name,
      '@type' => $media_type_id
    ]);

    try {
      // Check if field still exists before attempting removal
      if (!$this->exifFieldManager->fieldExists($media_type_id, $field_name)) {
        $this->logger->info('Field @field no longer exists on media type @type, skipping removal', [
          '@field' => $field_name,
          '@type' => $media_type_id
        ]);
        return;
      }

      // Remove the field
      $success = $this->exifFieldManager->removeExifField($media_type_id, $field_name);

      if ($success) {
        $this->logger->info('Successfully removed field @field from media type @type', [
          '@field' => $field_name,
          '@type' => $media_type_id
        ]);
      } else {
        throw new \Exception("Failed to remove field $field_name from media type $media_type_id");
      }

    } catch (\Exception $e) {
      $this->logger->error('Error removing field @field from media type @type: @error', [
        '@field' => $field_name,
        '@type' => $media_type_id,
        '@error' => $e->getMessage()
      ]);
      
      // Re-throw to ensure queue item is marked as failed
      throw $e;
    }
  }

}
