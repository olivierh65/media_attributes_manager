<?php

namespace Drupal\media_attributes_manager\Traits;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Trait pour gérer les mises à jour de widget avec AJAX.
 *
 * Ce trait factiorise le pattern commun utilisé par toutes les actions
 * (Bulk Remove, Sort, Apply EXIF, etc.) pour mettre à jour l'entité,
 * les valeurs utilisateur et déclencher le rafraîchissement AJAX.
 */
trait WidgetUpdateTrait {

  /**
   * Met à jour un champ d'entité et les valeurs utilisateur avec le nouveau contenu.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   L'état du formulaire.
   * @param string $field_name
   *   Le nom du champ à mettre à jour.
   * @param array $new_values
   *   Les nouvelles valeurs du champ (format Drupal field values).
   * @param string $operation_type
   *   Le type d'opération (pour les logs et storage).
   * @param array $storage_data
   *   Données additionnelles à stocker pour l'AJAX callback.
   *
   * @return bool
   *   TRUE si la mise à jour a réussi, FALSE sinon.
   */
  protected static function updateFieldAndUserInput(FormStateInterface $form_state, $field_name, array $new_values, $operation_type, array $storage_data = []) {
    // Récupérer l'entité
    $form_object = $form_state->getFormObject();
    $entity = NULL;
    if ($form_object && method_exists($form_object, 'getEntity')) {
      $entity = $form_object->getEntity();
    }

    if (!$entity || !$entity->hasField($field_name)) {
      \Drupal::logger('media_attributes_manager')->error('Cannot access entity or field @field for @operation', [
        '@field' => $field_name,
        '@operation' => $operation_type
      ]);
      return FALSE;
    }

    // Mettre à jour le champ de l'entité - PATTERN ÉPROUVÉ
    $entity->set($field_name, $new_values);
    \Drupal::logger('media_attributes_manager')->debug('Updated entity field @field with @count items for @operation', [
      '@field' => $field_name,
      '@count' => count($new_values),
      '@operation' => $operation_type
    ]);

    // Construire la chaîne target_id pour les user_input
    $target_ids = [];
    foreach ($new_values as $value) {
      if (isset($value['target_id'])) {
        $target_ids[] = 'media:' . $value['target_id'];
      }
    }
    $target_ids_string = implode(' ', $target_ids);

    // Mettre à jour les valeurs dans l'entrée utilisateur - PATTERN ÉPROUVÉ
    $user_input = $form_state->getUserInput();

    // Format 1: Chaîne dans target_id avec des IDs séparés par des espaces
    if (isset($user_input[$field_name]['target_id'])) {
      $user_input[$field_name]['target_id'] = $target_ids_string;
    }

    // Format 2: Format avec current/target_id
    if (isset($user_input[$field_name]['current']['target_id'])) {
      $user_input[$field_name]['current']['target_id'] = $target_ids_string;
    }

    // Format 3: widget/target_id
    if (isset($user_input[$field_name]['widget']['target_id'])) {
      $user_input[$field_name]['widget']['target_id'] = $target_ids_string;
    }

    // Format 4: widget/current/target_id
    if (isset($user_input[$field_name]['widget']['current']['target_id'])) {
      $user_input[$field_name]['widget']['current']['target_id'] = $target_ids_string;
    }

    // Mettre à jour l'entrée utilisateur - PATTERN ÉPROUVÉ
    $form_state->setUserInput($user_input);

    // Stocker les informations pour le traitement AJAX - PATTERN ÉPROUVÉ
    $storage = $form_state->getStorage();
    $storage['field_name'] = $field_name;

    // Ajouter les données spécifiques à l'opération
    foreach ($storage_data as $key => $value) {
      $storage[$key] = $value;
    }

    $form_state->setStorage($storage);

    // Forcer le rebuild du formulaire pour AJAX - PATTERN ÉPROUVÉ
    $form_state->setRebuild(TRUE);

    \Drupal::logger('media_attributes_manager')->debug('Widget update completed for @operation. Target IDs: @ids', [
      '@operation' => $operation_type,
      '@ids' => $target_ids_string
    ]);

    return TRUE;
  }

  /**
   * Identifie le nom du champ à partir de l'élément déclencheur.
   *
   * @param array $triggering_element
   *   L'élément qui a déclenché l'action.
   *
   * @return string|null
   *   Le nom du champ ou NULL si non trouvé.
   */
  protected static function getFieldNameFromTrigger(array $triggering_element) {
    $field_name = '';

    // Méthode 1: Depuis l'attribut data-field-name
    if (!empty($triggering_element['#attributes']['data-field-name'])) {
      $field_name = $triggering_element['#attributes']['data-field-name'];
      \Drupal::logger('media_attributes_manager')->debug('Field name from button attribute: @field', [
        '@field' => $field_name
      ]);
      return $field_name;
    }

    // Méthode 2: Analyser les parents du bouton
    if (!empty($triggering_element['#array_parents'])) {
      foreach ($triggering_element['#array_parents'] as $parent) {
        if (strpos($parent, 'field_') === 0) {
          $field_name = $parent;
          \Drupal::logger('media_attributes_manager')->debug('Field name from array parents: @field', [
            '@field' => $field_name
          ]);
          return $field_name;
        }
      }
    }

    // Méthode 3: Déduire du nom du bouton
    if (!empty($triggering_element['#name'])) {
      $button_name = $triggering_element['#name'];
      $patterns = [
        'bulk_remove_' => 'bulk_remove_',
        'apply_exif_' => 'apply_exif_',
        'sort_button_' => 'sort_button_',
      ];

      foreach ($patterns as $pattern => $prefix) {
        if (strpos($button_name, $prefix) === 0) {
          $field_name = substr($button_name, strlen($prefix));
          \Drupal::logger('media_attributes_manager')->debug('Field name from button name pattern @pattern: @field', [
            '@pattern' => $pattern,
            '@field' => $field_name
          ]);
          return $field_name;
        }
      }
    }

    \Drupal::logger('media_attributes_manager')->warning('Could not identify field name from triggering element');
    return NULL;
  }

  /**
   * Affiche un message de confirmation pour une opération sur les médias.
   *
   * @param string $operation_type
   *   Le type d'opération.
   * @param int $count
   *   Le nombre d'éléments affectés.
   * @param bool $is_error
   *   TRUE si c'est un message d'erreur.
   */
  protected static function showOperationMessage($operation_type, $count, $is_error = FALSE) {
    $messages = [
      'remove' => [
        'singular' => 'One media item has been removed.',
        'plural' => '@count media items have been removed.',
      ],
      'sort' => [
        'singular' => 'One media item has been sorted.',
        'plural' => '@count media items have been sorted.',
      ],
      'exif' => [
        'singular' => 'EXIF data has been applied to one media item.',
        'plural' => 'EXIF data has been applied to @count media items.',
      ],
    ];

    if (isset($messages[$operation_type])) {
      $message = \Drupal::translation()->formatPlural(
        $count,
        $messages[$operation_type]['singular'],
        $messages[$operation_type]['plural']
      );

      if ($is_error) {
        \Drupal::messenger()->addError($message);
      } else {
        \Drupal::messenger()->addStatus($message);
      }
    }
  }

}
