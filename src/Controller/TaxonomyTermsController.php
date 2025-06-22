<?php

namespace Drupal\media_attributes_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contrôleur pour les opérations liées aux termes de taxonomie.
 */
class TaxonomyTermsController extends ControllerBase {

  /**
   * Le gestionnaire d'entités.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructeur.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Le gestionnaire d'entités.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Récupère les valeurs de taxonomie pour les médias sélectionnés.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La requête HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Réponse JSON avec les valeurs de taxonomie.
   */
  public function getTaxonomyValues(Request $request) {
    // Récupérer les paramètres de la requête
    $media_ids = $request->query->get('media_ids');
    $field_name = $request->query->get('field_name');

    // Validation des paramètres
    if (!$media_ids || !$field_name) {
      return new JsonResponse([
        'error' => 'Missing required parameters: media_ids and field_name are required.',
      ], 400);
    }

    // Convertir la chaîne media_ids en tableau
    if (is_string($media_ids)) {
      $media_ids = explode(',', $media_ids);
    }

    // Charger les médias
    $media_storage = $this->entityTypeManager->getStorage('media');
    $medias = $media_storage->loadMultiple($media_ids);

    // Collecter les valeurs de taxonomie uniques pour le champ spécifié
    $term_values = [];
    foreach ($medias as $media) {
      if ($media->hasField($field_name)) {
        $field = $media->get($field_name);
        
        foreach ($field as $item) {
          if ($item->entity && $item->entity->getEntityTypeId() === 'taxonomy_term') {
            $term = $item->entity;
            $term_id = $term->id();
            $term_name = $term->label();
            
            // Ajouter au tableau résultat s'il n'existe pas déjà
            if (!isset($term_values[$term_id])) {
              $term_values[$term_id] = [
                'id' => $term_id,
                'name' => $term_name,
              ];
            }
          }
        }
      }
    }

    // Convertir en tableau indexé pour JSON
    $result = array_values($term_values);
    
    // Trier par nom alphabétiquement
    usort($result, function ($a, $b) {
      return strcmp($a['name'], $b['name']);
    });

    return new JsonResponse([
      'field_name' => $field_name,
      'values' => $result,
    ]);
  }
}
