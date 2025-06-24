<?php

namespace Drupal\media_attributes_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Service for extracting and applying EXIF data to media entities.
 */
class ExifDataManager {
  use StringTranslationTrait;

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
   * Constructs a new ExifDataManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('media_attributes_manager');
    $this->messenger = $messenger;
  }

  /**
   * Apply EXIF data to media entities.
   *
   * @param array $media_ids
   *   Array of media entity IDs.
   *
   * @return int
   *   The number of media entities updated.
   */
  public function applyExifData(array $media_ids) {
    if (empty($media_ids)) {
      return 0;
    }

    // Load configuration.
    $config = $this->configFactory->get('media_attributes_manager.settings');
    if (!$config->get('enable_exif_feature')) {
      $this->messenger->addWarning($this->t('EXIF data extraction is disabled in the module settings.'));
      return 0;
    }

    // Load media entities.
    $media_entities = $this->entityTypeManager->getStorage('media')
      ->loadMultiple($media_ids);

    // Count of updated entities.
    $updated_count = 0;

    foreach ($media_entities as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }

      // Skip non-image media types or types without EXIF data.
      if ($media->getSource()->getPluginId() != 'image') {
        continue;
      }

      $media_type_id = $media->bundle();

      // Get the file entity from the media.
      $file = $this->getMediaFile($media);
      if (!$file) {
        continue;
      }

      // Extract EXIF data.
      $exif_data = $this->extractExifData($file);
      if (empty($exif_data)) {
        continue;
      }

      // Check if this media type is enabled for EXIF processing
      $enabled_media_types = $config->get('exif_enabled_media_types') ?: [];
      if (!empty($enabled_media_types) && !in_array($media_type_id, $enabled_media_types)) {
        continue;
      }

      $media_updated = FALSE;

      // Apply EXIF data directly to fields based on naming convention
      foreach ($exif_data as $exif_key => $exif_value) {
        // Skip empty values
        if (empty($exif_value)) {
          continue;
        }

        // Generate field name based on EXIF key (e.g., 'make' -> 'field_exif_make')
        $field_name = 'field_exif_' . $exif_key;

        if ($media->hasField($field_name)) {
          $field_definition = $media->getFieldDefinition($field_name);
          $field_type = $field_definition->getType();

          // Handle different field types.
          switch ($field_type) {
            case 'string':
            case 'string_long':
            case 'text':
            case 'text_long':
              $media->set($field_name, $exif_value);
              $media_updated = TRUE;
              break;

            case 'datetime':
              // Try to convert to a datetime format if it's a timestamp or date string.
              if (is_numeric($exif_value)) {
                $media->set($field_name, date('Y-m-d\TH:i:s', $exif_value));
                $media_updated = TRUE;
              }
              elseif (strtotime($exif_value) !== FALSE) {
                $media->set($field_name, date('Y-m-d\TH:i:s', strtotime($exif_value)));
                $media_updated = TRUE;
              }
              break;

            case 'entity_reference':
              // For taxonomy terms, try to find matching terms or create them.
              if ($field_definition->getSetting('target_type') == 'taxonomy_term') {
                $vid = $field_definition->getSetting('handler_settings')['target_bundles'] ?? NULL;
                if (!empty($vid) && is_array($vid)) {
                  $vid = reset($vid);

                  // Try to find existing term.
                  $terms = $this->entityTypeManager->getStorage('taxonomy_term')
                    ->loadByProperties([
                      'name' => $exif_value,
                      'vid' => $vid,
                    ]);

                  if (!empty($terms)) {
                    $term = reset($terms);
                    $media->set($field_name, ['target_id' => $term->id()]);
                    $media_updated = TRUE;
                  }
                }
              }
              break;

            case 'decimal':
            case 'float':
              // For numeric fields like GPS coordinates
              if (is_numeric($exif_value)) {
                $media->set($field_name, $exif_value);
                $media_updated = TRUE;
              }
              break;

            case 'integer':
              // For integer fields like dimensions, ISO
              if (is_numeric($exif_value)) {
                $media->set($field_name, (int) $exif_value);
                $media_updated = TRUE;
              }
              break;
          }
        }
      }

      // Save the media entity if it was updated.
      if ($media_updated) {
        try {
          // Set the changed timestamp to trigger proper update workflows
          $media->setChangedTime(\Drupal::time()->getRequestTime());
          
          // Mark the entity as needing a full update (this ensures all hooks are triggered)
          $media->enforceIsNew(FALSE);
          
          // Save the entity (this will trigger all appropriate hooks)
          $media->save();
          
          // Invalidate cache tags for this media entity
          \Drupal::service('cache_tags.invalidator')->invalidateTags($media->getCacheTags());
          
          // Trigger search index update if search API is available
          if (\Drupal::moduleHandler()->moduleExists('search_api')) {
            try {
              \Drupal::service('search_api.post_request_indexing')->registerItem('entity:media', $media->id());
            } catch (\Exception $search_exception) {
              // Log but don't fail if search indexing fails
              $this->logger->warning('Search indexing failed for media @id: @error', [
                '@id' => $media->id(),
                '@error' => $search_exception->getMessage(),
              ]);
            }
          }
          
          $updated_count++;
          
          $this->logger->info('Successfully applied EXIF data to media ID @id with full entity update', [
            '@id' => $media->id(),
          ]);
        }
        catch (\Exception $e) {
          $this->logger->error('Error applying EXIF data to media ID @id: @error', [
            '@id' => $media->id(),
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }

    // Trigger custom hooks for bulk EXIF update if any media were updated
    if ($updated_count > 0) {
      // Reload the updated media entities to ensure we have the latest data
      $updated_media = $this->entityTypeManager->getStorage('media')
        ->loadMultiple($media_ids);
      
      // Filter to only include actually updated media
      $actually_updated = [];
      foreach ($updated_media as $media) {
        if ($media instanceof MediaInterface) {
          $actually_updated[] = $media;
        }
      }
      
      // Trigger custom hook for modules that want to react to EXIF bulk updates
      \Drupal::moduleHandler()->invokeAll('media_attributes_manager_exif_bulk_applied', [$actually_updated]);
      
      $this->logger->info('Completed EXIF data application for @count media entities', [
        '@count' => $updated_count,
      ]);
    }

    return $updated_count;
  }

  /**
   * Get the file entity from a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity, or NULL if not found.
   */
  protected function getMediaFile(MediaInterface $media) {
    $source_field = $media->getSource()->getConfiguration()['source_field'];
    if (!$media->hasField($source_field)) {
      return NULL;
    }

    $file_id = $media->get($source_field)->target_id;
    if (empty($file_id)) {
      return NULL;
    }

    try {
      $file = $this->entityTypeManager->getStorage('file')->load($file_id);
      return $file instanceof FileInterface ? $file : NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading file for media ID @id: @error', [
        '@id' => $media->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Extract EXIF data from a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return array
   *   Associative array of EXIF data.
   */
  protected function extractExifData(FileInterface $file) {
    $exif_data = [];
    $file_path = \Drupal::service('file_system')->realpath($file->getFileUri());

    if (!file_exists($file_path) || !is_readable($file_path)) {
      $this->logger->warning('File does not exist or is not readable: @path', [
        '@path' => $file_path,
      ]);
      return $exif_data;
    }

    // Check if any EXIF data is selected before processing
    if (!$this->hasSelectedExifData()) {
      $this->logger->warning('No EXIF data selected for extraction.');
      $this->messenger->addWarning($this->t('No EXIF data selected for extraction.'));
      return $exif_data;
    }

    // Check if exif_read_data function exists.
    if (!function_exists('exif_read_data')) {
      $this->logger->warning('EXIF functions are not available. Ensure the PHP EXIF extension is installed.');
      $this->messenger->addWarning($this->t('PHP EXIF extension is not available. Please contact your system administrator.'));
      return $exif_data;
    }

    try {
      $mime_type = mime_content_type($file_path);
      if (!in_array($mime_type, ['image/jpeg', 'image/tiff'])) {
        // EXIF data is normally only available in JPEG and TIFF files.
        return $exif_data;
      }

      $exif = @exif_read_data($file_path, 'ANY_TAG', TRUE);
      if (!$exif) {
        return $exif_data;
      }

      // Get configuration to see which EXIF data was selected
      $config = $this->configFactory->get('media_attributes_manager.settings');

      // Extract data from IFD0 section
      $this->extractSelectedData($config, $exif_data, $exif, 'make', 'IFD0', 'Make');
      $this->extractSelectedData($config, $exif_data, $exif, 'model', 'IFD0', 'Model');
      $this->extractSelectedData($config, $exif_data, $exif, 'orientation', 'IFD0', 'Orientation');
      $this->extractSelectedData($config, $exif_data, $exif, 'software', 'IFD0', 'Software');
      $this->extractSelectedData($config, $exif_data, $exif, 'copyright', 'IFD0', 'Copyright');
      $this->extractSelectedData($config, $exif_data, $exif, 'artist', 'IFD0', 'Artist');

      // Extract data from EXIF section
      if ($config->get('exif_data_selection.datetime_original') && isset($exif['EXIF']['DateTimeOriginal'])) {
        $exif_data['datetime_original'] = $exif['EXIF']['DateTimeOriginal'];
      }

      if ($config->get('exif_data_selection.datetime_digitized') && isset($exif['EXIF']['DateTimeDigitized'])) {
        $exif_data['datetime_digitized'] = $exif['EXIF']['DateTimeDigitized'];
      }

      if ($config->get('exif_data_selection.exif_image_width') && isset($exif['EXIF']['ExifImageWidth'])) {
        $exif_data['exif_image_width'] = $exif['EXIF']['ExifImageWidth'];
      }

      if ($config->get('exif_data_selection.exif_image_length') && isset($exif['EXIF']['ExifImageLength'])) {
        $exif_data['exif_image_length'] = $exif['EXIF']['ExifImageLength'];
      }

      // Extract exposure time
      if ($config->get('exif_data_selection.exposure') && isset($exif['EXIF']['ExposureTime'])) {
        $exif_data['exposure'] = $this->formatExposureTime($exif['EXIF']['ExposureTime']);
      }

      // Extract aperture
      if ($config->get('exif_data_selection.aperture') && isset($exif['EXIF']['FNumber'])) {
        $exif_data['aperture'] = $this->formatAperture($exif['EXIF']['FNumber']);
      }

      // Extract ISO
      if ($config->get('exif_data_selection.iso') && isset($exif['EXIF']['ISOSpeedRatings'])) {
        $exif_data['iso'] = $exif['EXIF']['ISOSpeedRatings'];
      }

      // Extract focal length
      if ($config->get('exif_data_selection.focal_length') && isset($exif['EXIF']['FocalLength'])) {
        $exif_data['focal_length'] = $this->formatFocalLength($exif['EXIF']['FocalLength']);
      }

      // Extract GPS data if selected
      if (isset($exif['GPS'])) {
        // Extract GPS Latitude if selected
        if ($config->get('exif_data_selection.gps_latitude') &&
            isset($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'])) {
          $lat = $this->getGpsCoordinate($exif['GPS']['GPSLatitude']);
          $lat_ref = ($exif['GPS']['GPSLatitudeRef'] == 'N') ? 1 : -1;
          $exif_data['gps_latitude'] = number_format($lat * $lat_ref, 6);
        }

        // Extract GPS Longitude if selected
        if ($config->get('exif_data_selection.gps_longitude') &&
            isset($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'])) {
          $lng = $this->getGpsCoordinate($exif['GPS']['GPSLongitude']);
          $lng_ref = ($exif['GPS']['GPSLongitudeRef'] == 'E') ? 1 : -1;
          $exif_data['gps_longitude'] = number_format($lng * $lng_ref, 6);
        }

        // Extract GPS Altitude if selected
        if ($config->get('exif_data_selection.gps_altitude') &&
            isset($exif['GPS']['GPSAltitude'], $exif['GPS']['GPSAltitudeRef'])) {
          $altitude = $this->evalFraction($exif['GPS']['GPSAltitude']);
          $altitude_ref = $exif['GPS']['GPSAltitudeRef'] == 1 ? -1 : 1; // 1 is below sea level
          $exif_data['gps_altitude'] = $altitude * $altitude_ref . 'm';
        }

        // Extract GPS Date/Time if selected
        if ($config->get('exif_data_selection.gps_date') &&
            isset($exif['GPS']['GPSDateStamp'], $exif['GPS']['GPSTimeStamp'])) {
          $date = $exif['GPS']['GPSDateStamp'];
          $time = $exif['GPS']['GPSTimeStamp'];

          if (is_array($time) && count($time) === 3) {
            $hours = $this->evalFraction($time[0]);
            $minutes = $this->evalFraction($time[1]);
            $seconds = $this->evalFraction($time[2]);

            $exif_data['gps_date'] = $date . ' ' . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
          } else {
            $exif_data['gps_date'] = $date;
          }
        }

        // Extract formatted GPS coordinates if selected
        if ($config->get('exif_data_selection.gps_coordinates') &&
            isset($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLongitude'],
                  $exif['GPS']['GPSLatitudeRef'], $exif['GPS']['GPSLongitudeRef'])) {
          $exif_data['gps_coordinates'] = $this->formatGpsData($exif['GPS']);
        }
      }

      // Extract computed dimensions if selected
      if ($config->get('exif_data_selection.computed_height') && isset($exif['COMPUTED']['Height'])) {
        $exif_data['computed_height'] = $exif['COMPUTED']['Height'];
      }

      if ($config->get('exif_data_selection.computed_width') && isset($exif['COMPUTED']['Width'])) {
        $exif_data['computed_width'] = $exif['COMPUTED']['Width'];
      }

      // XMP data (requires additional parsing).
      // Title, Caption, Keywords are often stored in XMP data.
      // For simplicity in this example we're only handling basic EXIF tags,
      // but could be expanded to include XMP parsing.

      // Here we could add code to extract XMP metadata as well,
      // but that would require additional libraries or complex parsing.

    }
    catch (\Exception $e) {
      $this->logger->error('Error extracting EXIF data: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $exif_data;
  }

  /**
   * Helper method to extract selected EXIF data based on configuration.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   * @param array &$exif_data
   *   The array to store the extracted EXIF data.
   * @param array $exif
   *   The raw EXIF data array.
   * @param string $field_key
   *   The key to use in the exif_data array.
   * @param string $section
   *   The section in the EXIF data (e.g., 'IFD0', 'EXIF').
   * @param string $tag
   *   The EXIF tag name.
   */
  protected function extractSelectedData($config, array &$exif_data, array $exif, $field_key, $section, $tag) {
    if ($config->get("exif_data_selection.$field_key") && isset($exif[$section][$tag])) {
      $value = $exif[$section][$tag];
      if (is_string($value)) {
        $exif_data[$field_key] = trim($value);
      } else {
        $exif_data[$field_key] = $value;
      }
    }
  }

  /**
   * Format exposure time to a readable fraction.
   */
  protected function formatExposureTime($exposure) {
    if (is_string($exposure) && strpos($exposure, '/') !== FALSE) {
      return $exposure;
    }

    if (is_numeric($exposure) && $exposure > 0) {
      if ($exposure >= 1) {
        return (string) $exposure;
      }
      else {
        $fraction = $this->decimalToFraction($exposure);
        return "1/{$fraction[1]}";
      }
    }

    return $exposure;
  }

  /**
   * Format aperture (f-number).
   */
  protected function formatAperture($aperture) {
    if (is_string($aperture) && strpos($aperture, '/') !== FALSE) {
      list($num, $denom) = explode('/', $aperture);
      if ($denom > 0) {
        $value = $num / $denom;
        return 'f/' . number_format($value, 1);
      }
    }
    elseif (is_numeric($aperture)) {
      return 'f/' . number_format($aperture, 1);
    }

    return $aperture;
  }

  /**
   * Format focal length.
   */
  protected function formatFocalLength($focal) {
    if (is_string($focal) && strpos($focal, '/') !== FALSE) {
      list($num, $denom) = explode('/', $focal);
      if ($denom > 0) {
        $value = $num / $denom;
        return number_format($value, 0) . 'mm';
      }
    }
    elseif (is_numeric($focal)) {
      return number_format($focal, 0) . 'mm';
    }

    return $focal;
  }

  /**
   * Format GPS data.
   */
  protected function formatGpsData($gps) {
    if (isset($gps['GPSLatitude'], $gps['GPSLongitude'], $gps['GPSLatitudeRef'], $gps['GPSLongitudeRef'])) {
      $lat = $this->getGpsCoordinate($gps['GPSLatitude']);
      $lng = $this->getGpsCoordinate($gps['GPSLongitude']);

      $lat_ref = ($gps['GPSLatitudeRef'] == 'N') ? 1 : -1;
      $lng_ref = ($gps['GPSLongitudeRef'] == 'E') ? 1 : -1;

      return number_format($lat * $lat_ref, 6) . ',' . number_format($lng * $lng_ref, 6);
    }

    return NULL;
  }

  /**
   * Convert GPS coordinate to decimal.
   */
  protected function getGpsCoordinate($coordinate) {
    if (is_array($coordinate) && count($coordinate) == 3) {
      $degrees = $this->evalFraction($coordinate[0]);
      $minutes = $this->evalFraction($coordinate[1]);
      $seconds = $this->evalFraction($coordinate[2]);

      return $degrees + ($minutes / 60) + ($seconds / 3600);
    }

    return 0;
  }

  /**
   * Evaluate a fraction from EXIF data.
   */
  protected function evalFraction($fraction) {
    if (is_string($fraction) && strpos($fraction, '/') !== FALSE) {
      list($num, $denom) = explode('/', $fraction);
      if ($denom > 0) {
        return $num / $denom;
      }
    }

    return (float) $fraction;
  }

  /**
   * Convert a decimal to a fraction for user-friendly exposure times.
   */
  protected function decimalToFraction($decimal) {
    if ($decimal == 0) {
      return [0, 1];
    }

    $precision = 0.00001;
    $numerator = 1;
    $denominator = round(1 / $decimal);

    return [$numerator, $denominator];
  }

  /**
   * Check if any EXIF data is selected for extraction.
   *
   * @return bool
   *   TRUE if at least one EXIF field is selected for extraction.
   */
  protected function hasSelectedExifData() {
    $config = $this->configFactory->get('media_attributes_manager.settings');

    $exif_fields = [
      'computed_height', 'computed_width',
      'make', 'model', 'orientation', 'software', 'copyright', 'artist',
      'datetime_original', 'datetime_digitized', 'exif_image_width', 'exif_image_length',
      'exposure', 'aperture', 'iso', 'focal_length',
      'gps_latitude', 'gps_longitude', 'gps_altitude', 'gps_date', 'gps_coordinates',
    ];

    foreach ($exif_fields as $field) {
      if ($config->get("exif_data_selection.$field")) {
        $this->logger->debug('Found selected EXIF field: @field', ['@field' => $field]);
        return TRUE;
      }
    }

    // Debug: log all exif_data_selection values
    $all_selections = $config->get('exif_data_selection');
    $this->logger->debug('All EXIF selections: @selections', [
      '@selections' => json_encode($all_selections, JSON_PRETTY_PRINT)
    ]);

    return FALSE;
  }

}
