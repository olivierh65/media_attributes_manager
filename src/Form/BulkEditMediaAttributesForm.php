<?php

namespace Drupal\media_attributes_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Url;
use Drupal\media_attributes_manager\Traits\CustomFieldsTrait;


/**
 * Formulaire pour l'édition en masse des attributs de médias.
 *
 * Ce formulaire gère deux cas :
 * 1. UN seul média sélectionné : affiche les valeurs actuelles comme défauts
 * 2. PLUSIEURS médias sélectionnés : logique intelligente selon le type de champ :
 *    - Champs texte : select avec toutes les valeurs uniques trouvées
 *    - Checkboxes : cochée si tous identiques, sinon décochée avec message
 *    - Entity reference : select avec toutes les entités référencées
 *
 * Logique de sauvegarde pour chaque champ :
 * - Si checkbox "Clear" cochée -> efface la valeur du champ
 * - Si le champ contient une valeur -> affecte cette valeur au média
 * - Si pas de valeur -> ne modifie pas le champ du média (garde la valeur existante)
 *
 * L'autocomplétion fonctionne grâce au script bulk-edit-autocomplete-fix.js
 * qui force l'initialisation des widgets dans les modales AJAX.
 */
class BulkEditMediaAttributesForm extends FormBase {
  use CustomFieldsTrait;

  public function getFormId() {
    return 'media_attributes_bulk_edit_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $media_data = []) {
    // Ajouter un wrapper pour AJAX
    $form['#prefix'] = '<div id="bulk-edit-form-wrapper">';
    $form['#suffix'] = '</div>';

    // Normaliser les media_data pour extraire seulement les IDs numériques
    $normalized_media_ids = [];
    $media_ids_by_bundle = []; // Nouveau: stocker les IDs par bundle

    foreach ($media_data as $item) {
      $media_id = NULL;
      $bundle = NULL;

      if (is_array($item) && isset($item['id'])) {
        $media_id = $item['id'];
        // Récupérer le bundle si disponible
        if (isset($item['bundle'])) {
          $bundle = $item['bundle'];
        }
      } elseif (is_numeric($item)) {
        $media_id = $item;
      }

      if ($media_id) {
        $normalized_media_ids[] = $media_id;

        // Si le bundle n'est pas disponible dans les données, on va le charger
        if (!$bundle) {
          $media_entity = \Drupal::entityTypeManager()->getStorage('media')->load($media_id);
          if ($media_entity) {
            $bundle = $media_entity->bundle();
          }
        }

        // Organiser par bundle si disponible
        if ($bundle) {
          if (!isset($media_ids_by_bundle[$bundle])) {
            $media_ids_by_bundle[$bundle] = [];
          }
          $media_ids_by_bundle[$bundle][] = $media_id;
        }
      }
    }

    // Stocker les media_ids dans le formulaire pour la soumission
    // 1. Conserver le champ global pour compatibilité ascendante
    $form['media_ids'] = [
      '#type' => 'hidden',
      '#default_value' => implode(',', $normalized_media_ids),
    ];

    // 2. Ajouter des champs spécifiques par bundle
    if (!empty($media_ids_by_bundle)) {
      $form['media_ids_by_bundle'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];

      foreach ($media_ids_by_bundle as $bundle => $ids) {
        $form['media_ids_by_bundle'][$bundle] = [
          '#type' => 'hidden',
          '#default_value' => implode(',', $ids),
        ];
      }
    }

    // Stocker également les données structurées des médias pour le JS
    $form['media_data'] = [
      '#type' => 'hidden',
      '#default_value' => json_encode($media_data),
    ];

    // Attacher les bibliothèques nécessaires pour l'autocomplétion dans les modales
    // bulk_edit_autocomplete_fix.js résout le problème d'autocomplétion à la première ouverture
    $form['#attached']['library'][] = 'media_attributes_manager/bulk_edit_autocomplete_fix';
    $form['#attached']['library'][] = 'core/drupal.autocomplete';
    $form['#attached']['library'][] = 'core/jquery.ui.autocomplete';
    // Attacher le gestionnaire de soumission AJAX du formulaire
    $form['#attached']['library'][] = 'media_attributes_manager/bulk_edit_form_submit';

    // Charger toutes les entités médias sélectionnées
    $media_entities = [];
    foreach ($normalized_media_ids as $media_id) {
      $media = \Drupal::entityTypeManager()->getStorage('media')->load($media_id);
      if ($media) {
        $media_entities[] = $media;
      }
    }

    if (empty($media_entities)) {
      $form['error_message'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('No valid media found.') . '</div>',
      ];
      return $form;
    }

    // Générer les sous-formulaires selon le nombre de médias sélectionnés
    if (!empty($media_entities)) {
      if (count($media_entities) === 1) {
        // CAS 1 : Un seul média - utiliser buildCustomFieldsForm avec les valeurs par défaut
        $custom_fields_form = $this->buildCustomFieldsForm($media_entities[0]);
        if (!empty($custom_fields_form)) {
          $form['custom_fields'] = [
            '#type' => 'details',
            '#title' => $this->t('Attributs personnalisés'),
            '#open' => TRUE,
          ] + $custom_fields_form;
        }
      } else {
        // CAS 2 : Plusieurs médias - utiliser buildCustomFieldsFormByBundle avec sélecteurs intelligents
        $form['custom_fields'] = $this->buildCustomFieldsFormByBundle($media_entities, function($bundle) {
          return $this->t('Attributs personnalisés : @type', ['@type' => $bundle]);
        });
      }
    }

    // Le champ description a été supprimé conformément à la demande

    // Ajouter les actions comme un container explicite
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update and close'),
      '#attributes' => [
        'class' => ['bulk-edit-submit-button'],
        'data-close-modal' => 'true',
      ],
      // Retirer les paramètres AJAX natifs car nous utilisons notre propre gestionnaire JS
      // qui intercepte le clic pour reformater les données
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Récupérer les valeurs du formulaire
    $values = $form_state->getValues();

    // Extraire les IDs des médias depuis le formulaire, d'abord de manière globale
    $media_ids_str = !empty($values['media_ids']) ? $values['media_ids'] : '';
    $media_ids = explode(',', $media_ids_str);
    $media_ids_by_bundle = [];

    // Récupérer les IDs structurés par bundle si disponibles
    if (!empty($values['media_ids_by_bundle'])) {
      foreach ($values['media_ids_by_bundle'] as $bundle => $ids_str) {
        if (!empty($ids_str)) {
          $media_ids_by_bundle[$bundle] = explode(',', $ids_str);
        }
      }
    }

    // Si nous n'avons pas d'IDs structurés par bundle mais des IDs globaux,
    // nous devons charger les entités pour déterminer leurs bundles
    if (empty($media_ids_by_bundle) && !empty($media_ids)) {
      $media_storage = \Drupal::entityTypeManager()->getStorage('media');
      $media_entities = $media_storage->loadMultiple($media_ids);

      foreach ($media_entities as $media) {
        $bundle = $media->bundle();
        if (!isset($media_ids_by_bundle[$bundle])) {
          $media_ids_by_bundle[$bundle] = [];
        }
        $media_ids_by_bundle[$bundle][] = $media->id();
      }
    }

    // Si nous n'avons toujours pas de données structurées, arrêter le traitement
    if (empty($media_ids) && empty($media_ids_by_bundle)) {
      \Drupal::messenger()->addError($this->t('No media IDs provided.'));
      return;
    }

    try {
      $media_storage = \Drupal::entityTypeManager()->getStorage('media');

      // Traiter par bundle si disponible
      if (!empty($media_ids_by_bundle)) {
        foreach ($media_ids_by_bundle as $bundle => $bundle_ids) {
          $media_entities = $media_storage->loadMultiple($bundle_ids);

          if (empty($media_entities)) {
            \Drupal::messenger()->addError($this->t('No valid media entities found for bundle @bundle.', [
              '@bundle' => $bundle
            ]));
            continue;
          }

          $this->processMediaEntitiesUpdate($media_entities, $values);
        }
      } else {
        // Fallback : traitement global si pas de structure par bundle
        $media_entities = $media_storage->loadMultiple($media_ids);

        if (empty($media_entities)) {
          \Drupal::messenger()->addError($this->t('No valid media entities found.'));
          return;
        }

        $this->processMediaEntitiesUpdate($media_entities, $values);
      }

      \Drupal::messenger()->addStatus($this->t('Media attributes updated successfully.'));
    } catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('An error occurred: @error', ['@error' => $e->getMessage()]));
    }

    // Force la reconstruction du formulaire pour les soumissions AJAX
    $form_state->setRebuild(TRUE);
  }

  /**
   * Traite la mise à jour d'un groupe d'entités média avec les valeurs du formulaire.
   *
   * @param array $media_entities
   *   Les entités média à mettre à jour.
   * @param array $values
   *   Les valeurs du formulaire.
   */
  protected function processMediaEntitiesUpdate(array $media_entities, array $values) {
    foreach ($media_entities as $media) {
      $bundle = $media->bundle();
      $updated = FALSE;

      // Le traitement du champ description a été supprimé

      // Traiter les champs personnalisés par bundle si présents
      if (!empty($values['custom_fields'])) {
        foreach ($values['custom_fields'] as $field_name => $value) {
          // Ignorer les champs de type _clear
          if (strpos($field_name, '_clear') !== FALSE) {
            continue;
          }

          $clear_field_name = $field_name . '_clear';

          if (!empty($values['custom_fields'][$clear_field_name])) {
            // Effacer le champ
            try {
              $media->{$field_name} = NULL;
              $updated = TRUE;
            } catch (\Exception $e) {
              \Drupal::logger('media_attributes_manager')->error('Error clearing field @field: @error', [
                '@field' => $field_name,
                '@error' => $e->getMessage(),
              ]);
            }
          } elseif (!empty($value)) {
            // Mettre à jour avec la nouvelle valeur
            try {
              $media->{$field_name} = $value;
              $updated = TRUE;
            } catch (\Exception $e) {
              \Drupal::logger('media_attributes_manager')->error('Error updating field @field: @error', [
                '@field' => $field_name,
                '@error' => $e->getMessage(),
              ]);
            }
          }
        }
      }

      if ($updated) {
        try {
          $media->save();
        } catch (\Exception $e) {
          \Drupal::logger('media_attributes_manager')->error('Error saving media @id: @error', [
            '@id' => $media->id(),
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }
  }

  /**
   * AJAX callback for the submit button.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response that ferme la modale ou met à jour le formulaire.
   */
  /**
   * AJAX callback pour les boutons de soumission.
   *
   * @param array $form
   *   Structure du formulaire.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   L'état du formulaire.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Réponse AJAX pour fermer la modale ou mettre à jour le formulaire.
   */
  public function ajaxSubmitHandler(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $values = $form_state->getValues();
    $triggering_element = $form_state->getTriggeringElement();

    // Récupérer les IDs de médias pour les passer à l'événement JavaScript
    $media_ids_str = !empty($values['media_ids']) ? $values['media_ids'] : '';
    $media_ids = explode(',', $media_ids_str);
    $updated_media_data = [];

    foreach ($media_ids as $media_id) {
      if (!empty($media_id)) {
        $updated_media_data[] = [
          'id' => $media_id,
          'type' => 'media'
        ];
      }
    }

    // Ajouter un script pour déclencher l'événement de mise à jour
    if (!empty($updated_media_data)) {
      $json_data = json_encode($updated_media_data);
      $script = "jQuery(document).trigger('mediaAttributesUpdated', [{$json_data}]);";
      $response->addCommand(new InvokeCommand('body', 'append', [
        '<script>' . $script . '</script>'
      ]));
    }

    // Il n'y a plus qu'un seul bouton, donc on ferme toujours la modale
    $response->addCommand(new CloseModalDialogCommand());

    // Ajouter un message de statut pour l'utilisateur
    $message = $this->t('Media attributes updated successfully.');
    $response->addCommand(new \Drupal\Core\Ajax\MessageCommand($message, NULL, ['type' => 'status']));

    return $response;
  }

}
