<?php

/**
 * @file
 * Hooks provided by the Media Attributes Manager module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Respond to bulk EXIF data application.
 *
 * This hook is invoked after EXIF data has been applied to multiple media entities
 * in a bulk operation. It allows other modules to perform additional processing
 * when media entities have been updated with EXIF data.
 *
 * @param \Drupal\media\MediaInterface[] $media_entities
 *   An array of media entities that have been updated with EXIF data.
 *
 * @see \Drupal\media_attributes_manager\Service\ExifDataManager::applyExifData()
 */
function hook_media_attributes_manager_exif_bulk_applied(array $media_entities) {
  // Example: Update a custom search index
  foreach ($media_entities as $media) {
    // Custom processing for each updated media entity
    \Drupal::logger('my_module')->info('Media @id updated with EXIF data', [
      '@id' => $media->id(),
    ]);
  }
}

/**
 * @} End of "addtogroup hooks".
 */
