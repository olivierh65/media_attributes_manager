<?php

namespace Drupal\media_attributes_manager\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\media_attributes_manager\Service\ExifFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Processes EXIF field creation tasks.
 *
 * @QueueWorker(
 *   id = "media_attributes_manager_field_creation",
 *   title = @Translation("EXIF Field Creation"),
 *   cron = {"time" = 60}
 * )
 */
class ExifFieldCreationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The EXIF field manager service.
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
   * Constructs a new ExifFieldCreationQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\media_attributes_manager\Service\ExifFieldManager $exif_field_manager
   *   The EXIF field manager service.
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
    if (!isset($data['selected_exif']) || !isset($data['enabled_media_types'])) {
      $this->logger->error('Invalid queue data for EXIF field creation: @data', [
        '@data' => json_encode($data),
      ]);
      return;
    }

    $this->logger->info('Processing EXIF field creation for @count EXIF fields and @types media types', [
      '@count' => count($data['selected_exif']),
      '@types' => count($data['enabled_media_types']),
    ]);

    try {
      $fields_created = $this->exifFieldManager->createExifFieldsFromForm(
        $data['selected_exif'],
        $data['enabled_media_types'],
        TRUE
      );

      if ($fields_created > 0) {
        $this->logger->info('Successfully created @count EXIF field(s)', [
          '@count' => $fields_created,
        ]);

        // Store success message for display on next page load
        $state = \Drupal::state();
        $state->set('media_attributes_manager.field_creation_success', [
          'fields_created' => $fields_created,
          'timestamp' => time(),
        ]);
      } else {
        $this->logger->info('No new EXIF fields were created (fields may already exist)');
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error during EXIF field creation: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
