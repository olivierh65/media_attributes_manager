<?php

namespace Drupal\media_attributes_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;


class BulkEditModalController extends ControllerBase {

    /**
     * Affiche le formulaire d'édition en masse dans une modale
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   La requête HTTP.
     *
     * @return \Drupal\Core\Ajax\AjaxResponse
     *   La réponse AJAX contenant la modale.
     */
    public function modal(Request $request) {
        $json = $request->request->get('mediaData', '{}');
        $media_data = json_decode($json, true);

        // Génère le formulaire Drupal avec les données
        $form = \Drupal::formBuilder()->getForm('Drupal\media_attributes_manager\Form\BulkEditMediaAttributesForm', $media_data);

        // Crée la réponse AJAX pour ouvrir la modale
        $response = new AjaxResponse();
        $response->addCommand(new OpenModalDialogCommand(
            $this->t('Bulk Edit Media Attributes'),  // Titre de la modale
            $form,                                   // Contenu : le formulaire
            ['width' => '70%']                       // Options jQuery UI
        ));

        return $response;
    }

    /**
     * Traite le formulaire d'édition en masse.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   La requête HTTP.
     *
     * @return \Drupal\Core\Ajax\AjaxResponse
     *   La réponse AJAX.
     */
    public function submitForm(Request $request) {
        $response = new AjaxResponse();
        $response->addCommand(new CloseModalDialogCommand());

        try {
            // Récupérer et décoder les données d'entités
            $entitiesDataJson = $request->request->get('entitiesData');
            if (empty($entitiesDataJson)) {
                throw new \InvalidArgumentException('No entities data found in the request');
            }

            $entities_data = json_decode($entitiesDataJson, TRUE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON data: ' . json_last_error_msg());
            }

            // Journal de débogage pour voir les données brutes reçues
            \Drupal::logger('media_attributes_manager')->debug('Raw entities data: @data', [
                '@data' => json_encode($entities_data, JSON_PRETTY_PRINT)
            ]);

            // Vérifier la structure des données
            if (empty($entities_data['media']['types']) || !is_array($entities_data['media']['types'])) {
                throw new \InvalidArgumentException('Invalid data structure: missing or invalid media types');
            }

            $media_types = $entities_data['media']['types'];
            $processed_count = 0;

            \Drupal::logger('media_attributes_manager')->notice('Processing media types: @types', [
                '@types' => implode(', ', array_keys($media_types))
            ]);

            // Traiter chaque type de média
            foreach ($media_types as $media_bundle => $bundle_data) {
                // Vérifier que nous avons des IDs et des champs pour ce bundle
                if (!empty($bundle_data['ids']) && is_array($bundle_data['ids']) &&
                    isset($bundle_data['fields']) && is_array($bundle_data['fields'])) {

                    // Traiter ce bundle
                    $count = $this->updateMediaEntities(
                        $bundle_data['fields'],
                        $bundle_data['ids'],
                        $media_bundle
                    );

                    $processed_count += $count;
                }
            }

            if ($processed_count > 0) {
                $message = $this->formatPlural(
                    $processed_count,
                    '1 media item updated successfully.',
                    '@count media items updated successfully.'
                );

                \Drupal::messenger()->addStatus($message);
                $response->addCommand(new \Drupal\Core\Ajax\MessageCommand($message, NULL, ['type' => 'status']));
            } else {
                $message = $this->t('No media items were updated. Please check your selection.');
                \Drupal::messenger()->addWarning($message);
                $response->addCommand(new \Drupal\Core\Ajax\MessageCommand($message, NULL, ['type' => 'warning']));
            }

        } catch (\Exception $e) {
            $error_message = $this->t('Error processing form: @error', ['@error' => $e->getMessage()]);
            \Drupal::logger('media_attributes_manager')->error($error_message);
            \Drupal::messenger()->addError($error_message);
            $response->addCommand(new \Drupal\Core\Ajax\MessageCommand($error_message, NULL, ['type' => 'error']));
        }

        return $response;
    }    /**
     * Met à jour les entités médias avec les valeurs du formulaire.
     *
     * @param array $fields
     *   Les valeurs des champs à mettre à jour.
     * @param array $media_ids
     *   Les IDs des médias à mettre à jour.
     * @param string|null $media_bundle
     *   Le type de média (bundle) à filtrer.
     *
     * @return int
     *   Le nombre d'entités mises à jour avec succès.
     */
    protected function updateMediaEntities(array $fields, array $media_ids, $media_bundle = NULL) {
        $updated_count = 0;

        // Vérifier que nous avons des IDs à traiter
        if (empty($media_ids)) {
            \Drupal::logger('media_attributes_manager')->warning('No media IDs provided for bundle: @bundle', [
                '@bundle' => $media_bundle ?: 'unknown'
            ]);
            return $updated_count;
        }

        // Séparer les champs en deux catégories: champs normaux et drapeaux de nettoyage
        $fields_to_update = [];
        $clear_flags = [];

        foreach ($fields as $key => $value) {
            if (str_ends_with($key, '_metadata')) {
                // Ignorer les métadonnées
                continue;
            } else if (str_ends_with($key, '_clear')) {
                // Stocker les drapeaux de nettoyage séparément
                $clear_flags[$key] = $value;
            } else {
                // Stocker les champs normaux
                $fields_to_update[$key] = $value;
            }
        }

        // Journal de débogage pour voir les champs et drapeaux
        \Drupal::logger('media_attributes_manager')->debug('Fields to update: @fields, Clear flags: @flags', [
            '@fields' => json_encode(array_keys($fields_to_update)),
            '@flags' => json_encode($clear_flags)
        ]);

        // Si aucun champ à mettre à jour, sortir
        if (empty($fields_to_update)) {
            \Drupal::logger('media_attributes_manager')->warning('No valid fields to update for bundle: @bundle', [
                '@bundle' => $media_bundle ?: 'all bundles'
            ]);
            return $updated_count;
        }

        try {
            // Charger chaque média individuellement pour éviter de surcharger la mémoire
            $media_storage = $this->entityTypeManager()->getStorage('media');

            \Drupal::logger('media_attributes_manager')->notice('Processing @count media entities for bundle: @bundle', [
                '@count' => count($media_ids),
                '@bundle' => $media_bundle ?: 'all bundles'
            ]);

            // Traiter chaque média un par un
            foreach ($media_ids as $media_id) {
                // Charger l'entité média
                $media = $media_storage->load($media_id);

                // Vérifier que l'entité existe
                if (!$media) {
                    \Drupal::logger('media_attributes_manager')->warning('Media ID @id not found', [
                        '@id' => $media_id
                    ]);
                    continue;
                }

                // Vérifier la correspondance du bundle si spécifié
                if ($media_bundle && $media->bundle() !== $media_bundle) {
                    \Drupal::logger('media_attributes_manager')->debug('Skipping media @id (bundle mismatch: @actual ≠ @expected)', [
                        '@id' => $media_id,
                        '@actual' => $media->bundle(),
                        '@expected' => $media_bundle
                    ]);
                    continue;
                }

                $updated = FALSE;

                // Traiter chaque champ
                foreach ($fields_to_update as $field_name => $value) {
                    // Vérifier si le champ existe dans l'entité
                    if (!isset($media->{$field_name})) {
                        continue;
                    }

                    // Vérifier si ce champ doit être effacé
                    $clear_field_name = $field_name . '_clear';

                    // Gérer différents formats possibles de valeur de case à cocher (true/false, 1/0, "on"/undefined)
                    $clear_value = isset($fields[$clear_field_name]) ? $fields[$clear_field_name] : false;
                    $should_clear = ($clear_value === true || $clear_value === 1 || $clear_value === '1' || $clear_value === 'on' || $clear_value === 'true');

                    // Journal de débogage pour voir la valeur exacte reçue
                    \Drupal::logger('media_attributes_manager')->debug('Clear value for @field: @value (type: @type, should_clear: @should_clear)', [
                        '@field' => $field_name,
                        '@value' => is_bool($clear_value) ? ($clear_value ? 'true' : 'false') : $clear_value,
                        '@type' => gettype($clear_value),
                        '@should_clear' => $should_clear ? 'true' : 'false'
                    ]);

                    try {
                        if ($should_clear) {
                            // Effacer le champ
                            $media->{$field_name} = NULL;
                            $updated = TRUE;
                        } elseif (!empty($value)) {
                            // Mettre à jour avec la nouvelle valeur
                            $media->{$field_name} = $value;
                            $updated = TRUE;
                        }
                    } catch (\Exception $e) {
                        \Drupal::logger('media_attributes_manager')->error('Error updating field @field for media @id: @error', [
                            '@field' => $field_name,
                            '@id' => $media_id,
                            '@error' => $e->getMessage()
                        ]);
                    }
                }

                // Sauvegarder l'entité si des modifications ont été effectuées
                if ($updated) {
                    try {
                        $media->save();
                        $updated_count++;
                    } catch (\Exception $e) {
                        \Drupal::logger('media_attributes_manager')->error('Error saving media @id: @error', [
                            '@id' => $media_id,
                            '@error' => $e->getMessage()
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            \Drupal::logger('media_attributes_manager')->error('Error in updateMediaEntities: @error', [
                '@error' => $e->getMessage()
            ]);
        }

        return $updated_count;
    }

    // La fonction openModal() a été supprimée car elle était redondante avec modal()
    // et n'était pas utilisée dans le code JavaScript
}
