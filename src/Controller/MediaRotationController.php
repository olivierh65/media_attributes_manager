<?php

namespace Drupal\media_attributes_manager\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Contrôleur pour gérer la rotation des images de médias.
 */
class MediaRotationController extends ControllerBase {

  /**
   * Effectue la rotation d'une image médias de 90 degrés.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La requête HTTP contenant l'ID du média.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   La réponse JSON avec le statut de la rotation.
   */
  public function rotateImage(Request $request) {
    $media_id = $request->request->get('media_id');

    if (!$media_id) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Media ID not provided.'),
      ]);
    }

    try {
      $media = Media::load($media_id);

      if (!$media) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $this->t('Media not found.'),
        ]);
      }

      // Vérifie que l'utilisateur a la permission d'éditer ce média.
      if (!$media->access('update')) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $this->t('Access denied.'),
        ]);
      }

      // Récupère le champ d'image (généralement 'field_media_image').
      $image_field = $this->getImageFieldName($media);

      if (!$image_field || $media->get($image_field)->isEmpty()) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $this->t('No image field found on this media.'),
        ]);
      }

      // Récupère le fichier image.
      $file_entity = $media->get($image_field)->entity;

      if (!$file_entity) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $this->t('No file found in image field.'),
        ]);
      }

      // Effectue la rotation de l'image.
      $this->rotateImageFile($file_entity);

      // Marque le média comme modifié pour que Drupal détecte le changement.
      $media->setChangedTime(time());
      $media->save();

      return new JsonResponse([
        'success' => TRUE,
        'message' => $this->t('Image rotated successfully.'),
        'media_id' => $media_id,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('media_attributes_manager')->error('Error rotating image: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Error rotating image: @error', ['@error' => $e->getMessage()]),
      ]);
    }
  }

  /**
   * Obtient le nom du champ image pour un média.
   *
   * @param \Drupal\media\Entity\Media $media
   *   L'entité médias.
   *
   * @return string|null
   *   Le nom du champ image ou NULL si non trouvé.
   */
  private function getImageFieldName(Media $media) {
    $bundle = $media->bundle();
    $media_type = \Drupal::entityTypeManager()
      ->getStorage('media_type')
      ->load($bundle);

    if (!$media_type) {
      return NULL;
    }

    // Récupère le champ source du type de média.
    $source = $media_type->getSource();
    $source_field = $source->getSourceFieldDefinition($media_type);

    if ($source_field) {
      return $source_field->getName();
    }

    // Si la source n'est pas trouvée, cherche manuellement les champs image.
    foreach ($media->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() === 'image') {
        return $field_name;
      }
    }

    return NULL;
  }

  /**
   * Effectue la rotation physique d'une image.
   *
   * @param \Drupal\file\Entity\File $file_entity
   *   L'entité fichier à faire tourner.
   *
   * @throws \Exception
   */
  private function rotateImageFile($file_entity) {
    // Récupère le URI du fichier.
    $file_uri = $file_entity->getFileUri();

    // Récupère le chemin physique du fichier.
    $real_path = \Drupal::service('file_system')->realpath($file_uri);

    if (!$real_path || !file_exists($real_path)) {
      throw new \Exception('File does not exist: ' . $real_path);
    }

    // Utilise la bibliothèque d'image Drupal pour faire tourner l'image.
    $image = \Drupal::service('image.factory')->get($real_path);

    if (!$image->isValid()) {
      throw new \Exception('Invalid image file.');
    }

    // Fait tourner l'image de 90 degrés dans le sens horaire.
    $image->rotate(90);

    // Enregistre l'image modifiée.
    $image->save($real_path);

    // Invalide les caches de style d'image si nécessaire.
    \Drupal::service('cache_tags.invalidator')->invalidateTags([
      'image_style:medium',
      'image_style:large',
      'file:' . $file_entity->id(),
    ]);
  }

}
