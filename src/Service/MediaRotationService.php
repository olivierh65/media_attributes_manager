<?php

namespace Drupal\media_attributes_manager\Service;

use Drupal\media\Entity\Media;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Service for rotating media images.
 */
class MediaRotationService {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a MediaRotationService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * Rotates a media image by 90 degrees clockwise.
   *
   * @param int $media_id
   *   The media ID to rotate.
   *
   * @return array
   *   Result array with 'success' and 'message' keys.
   */
  public function rotateImage($media_id) {
    try {
      // Load the media entity.
      $media = Media::load($media_id);
      if (!$media) {
        return [
          'success' => FALSE,
          'message' => 'Media not found',
        ];
      }

      // Check access.
      if (!$media->access('update')) {
        return [
          'success' => FALSE,
          'message' => 'Access denied',
        ];
      }

      // Get the image field name.
      $image_field = $this->getImageFieldName($media);
      if (!$image_field) {
        return [
          'success' => FALSE,
          'message' => 'No image field found on this media',
        ];
      }

      // Get the image file.
      if ($media->get($image_field)->isEmpty()) {
        return [
          'success' => FALSE,
          'message' => 'Image field is empty',
        ];
      }

      $image_item = $media->get($image_field)->first();
      $file = $image_item->entity;

      if (!$file) {
        return [
          'success' => FALSE,
          'message' => 'File entity not found',
        ];
      }

      // Perform the rotation.
      $this->rotateImageFile($file);

      // Mark the media as modified.
      $media->setChangedTime(\Drupal::time()->getCurrentTime());
      $media->save();

      $this->loggerFactory->get('media_attributes_manager')->info('Image rotated 90° for media @id', [
        '@id' => $media_id,
      ]);

      return [
        'success' => TRUE,
        'message' => 'Image rotated successfully',
        'media_id' => $media_id,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('media_attributes_manager')->error('Error rotating image: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'message' => 'Error rotating image: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Get the image field name from a media entity.
   *
   * @param \Drupal\media\Entity\Media $media
   *   The media entity.
   *
   * @return string|null
   *   The field name or NULL if not found.
   */
  private function getImageFieldName(Media $media) {
    $bundle = $media->bundle();
    $media_type = \Drupal::entityTypeManager()
      ->getStorage('media_type')
      ->load($bundle);

    if (!$media_type) {
      return NULL;
    }

    // Get the source field from the media type.
    $source = $media_type->getSource();
    $source_field = $source->getSourceFieldDefinition($media_type);

    if ($source_field) {
      return $source_field->getName();
    }

    // Fallback: search for any image field.
    foreach ($media->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() === 'image') {
        return $field_name;
      }
    }

    return NULL;
  }

  /**
   * Performs the physical rotation of an image file.
   *
   * @param \Drupal\file\Entity\File $file_entity
   *   The file entity to rotate.
   *
   * @throws \Exception
   */
  private function rotateImageFile($file_entity) {
    // Get the file URI.
    $file_uri = $file_entity->getFileUri();

    // Get the real path of the file.
    $real_path = \Drupal::service('file_system')->realpath($file_uri);

    if (!$real_path || !file_exists($real_path)) {
      throw new \Exception('File does not exist: ' . $real_path);
    }

    // Use Drupal's image factory to rotate the image.
    $image = \Drupal::service('image.factory')->get($file_uri);

    if (!$image->isValid()) {
      throw new \Exception('Invalid image file.');
    }

    // Rotate the image 90 degrees clockwise.
    $image->rotate(90);

    // Save the modified image.
    // $image->save($real_path);
    $image->save();

    foreach (ImageStyle::loadMultiple() as $style) {
      $style->flush($file_uri);
    }

    /* // Invalidate image style caches.
    \Drupal::service('cache_tags.invalidator')->invalidateTags([
    'image_style:medium',
    'image_style:large',
    'file:' . $file_entity->id(),
    ]); */
  }

}
