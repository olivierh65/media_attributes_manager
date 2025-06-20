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

            // Journal détaillé pour chaque champ
            \Drupal::logger('media_attributes_manager')->debug('Analyse complète des données reçues:');
            foreach ($entities_data['media']['types'] as $bundle => $bundle_data) {
                \Drupal::logger('media_attributes_manager')->debug('Bundle: @bundle, IDs: @ids', [
                    '@bundle' => $bundle,
                    '@ids' => implode(', ', $bundle_data['ids'] ?? [])
                ]);

                if (!empty($bundle_data['fields'])) {
                    foreach ($bundle_data['fields'] as $field_name => $value) {
                        \Drupal::logger('media_attributes_manager')->debug('  Champ: @field, Valeur: @value, Type: @type', [
                            '@field' => $field_name,
                            '@value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                            '@type' => gettype($value)
                        ]);

                        // Vérification spécifique pour les champs TID
                        if (strpos($field_name, '_tid') !== false) {
                            $parent_field = str_replace('_tid', '', $field_name);
                            \Drupal::logger('media_attributes_manager')->debug('  --> Ce champ est un ID taxonomique pour: @parent_field', [
                                '@parent_field' => $parent_field
                            ]);
                        }
                    }
                } else {
                    \Drupal::logger('media_attributes_manager')->debug('  Aucun champ trouvé pour ce bundle');
                }
            }

            // Journal détaillé pour les valeurs de taxonomie
            foreach ($entities_data['media']['types'] as $bundle => $bundle_data) {
                if (!empty($bundle_data['fields'])) {
                    foreach ($bundle_data['fields'] as $field_name => $value) {
                        // Vérifier si c'est un champ de taxonomie potentiel
                        if (strpos($field_name, 'field_') === 0 && !strpos($field_name, '_clear') && !strpos($field_name, '_tid')) {
                            // Vérifier si nous avons un champ _tid correspondant
                            $tid_field = $field_name . '_tid';
                            if (isset($bundle_data['fields'][$tid_field])) {
                                \Drupal::logger('media_attributes_manager')->debug('Champ taxonomie trouvé: @field, Valeur: @value, TID: @tid', [
                                    '@field' => $field_name,
                                    '@value' => $value,
                                    '@tid' => $bundle_data['fields'][$tid_field]
                                ]);

                                // Remplacer la valeur par l'ID du terme
                                if (!empty($bundle_data['fields'][$tid_field])) {
                                    $entities_data['media']['types'][$bundle]['fields'][$field_name] = $bundle_data['fields'][$tid_field];
                                    \Drupal::logger('media_attributes_manager')->debug('Valeur remplacée par l\'ID du terme: @tid', [
                                        '@tid' => $bundle_data['fields'][$tid_field]
                                    ]);
                                }
                            } else {
                                \Drupal::logger('media_attributes_manager')->debug('Champ potentiel de taxonomie sans TID associé: @field, Valeur: @value', [
                                    '@field' => $field_name,
                                    '@value' => $value
                                ]);
                            }
                        }
                    }
                }
            }

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
                            // Déterminer le type de champ
                            try {
                                $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('media', $media->bundle());
                                if (isset($field_definitions[$field_name])) {
                                    $field_definition = $field_definitions[$field_name];
                                    $field_type = $field_definition->getType();
                                    $target_type = ($field_type === 'entity_reference') ?
                                        $field_definition->getFieldStorageDefinition()->getSetting('target_type') : NULL;
                                } else {
                                    // Si la définition n'est pas trouvée, continuer sans traitement spécial
                                    $field_type = NULL;
                                    $target_type = NULL;
                                }
                            } catch (\Exception $e) {
                                // En cas d'erreur, continuer sans traitement spécial
                                \Drupal::logger('media_attributes_manager')->warning('Error getting field definition for @field: @error', [
                                    '@field' => $field_name,
                                    '@error' => $e->getMessage()
                                ]);
                                $field_type = NULL;
                                $target_type = NULL;
                            }

                            if ($field_type === 'entity_reference' && $target_type === 'taxonomy_term') {
                                // D'abord vérifier si un champ caché avec l'ID est disponible
                                $tid_field_name = $field_name . '_tid';
                                $term_id = NULL;

                                \Drupal::logger('media_attributes_manager')->debug('Recherche du champ d\'ID caché @tid_field pour @field', [
                                    '@tid_field' => $tid_field_name,
                                    '@field' => $field_name
                                ]);

                                // Vérifier tous les champs pour trouver le champ d'ID caché
                                foreach ($fields as $key => $val) {
                                    \Drupal::logger('media_attributes_manager')->debug('  Examen du champ @key = @val', [
                                        '@key' => $key,
                                        '@val' => $val
                                    ]);
                                    
                                    // Chercher tous les champs potentiels contenant un ID de terme
                                    if (strpos($key, $field_name) !== false && (
                                        strpos($key, '_tid') !== false || 
                                        strpos($key, 'taxonomy_term_id') !== false)) {
                                        \Drupal::logger('media_attributes_manager')->debug('  Champ potentiel d\'ID trouvé: @key = @val', [
                                            '@key' => $key,
                                            '@val' => $val
                                        ]);
                                    }
                                }

                                // Vérifier d'abord le format standard _tid
                                if (isset($fields[$tid_field_name]) && !empty($fields[$tid_field_name])) {
                                    // Utiliser l'ID du champ caché s'il existe
                                    $term_id = $fields[$tid_field_name];
                                    \Drupal::logger('media_attributes_manager')->debug('Found hidden term ID field @tid_field with value @term_id for field @field', [
                                        '@tid_field' => $tid_field_name,
                                        '@term_id' => $term_id,
                                        '@field' => $field_name
                                    ]);
                                } 
                                // Vérifier ensuite le format alternatif pour le champ caché
                                else {
                                    // Format alternatif: taxonomy_term_id_BUNDLE_FIELDNAME
                                    $simple_hidden_name = 'taxonomy_term_id_' . $media_bundle . '_' . str_replace(['[', ']'], '_', $field_name);
                                    if (isset($fields[$simple_hidden_name]) && !empty($fields[$simple_hidden_name])) {
                                        $term_id = $fields[$simple_hidden_name];
                                        \Drupal::logger('media_attributes_manager')->debug('Found alternative hidden term ID field @field with value @term_id', [
                                            '@field' => $simple_hidden_name,
                                            '@term_id' => $term_id
                                        ]);
                                    }
                                }

                                // Si on n'a pas trouvé d'ID dans le champ caché, essayer d'extraire de la valeur principale
                                if (!$term_id) {
                                    // Si value est déjà un nombre, c'est déjà un ID
                                    if (is_numeric($value)) {
                                        $term_id = $value;
                                    }
                                    // Sinon, essayer d'extraire l'ID du format "Label (id)"
                                    else if (preg_match('/\(([0-9]+)\)$/', $value, $matches)) {
                                        $term_id = $matches[1];
                                    }
                                }

                                // Si on a un ID de terme, l'utiliser
                                if ($term_id) {
                                    // Vérifier que le terme existe
                                    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
                                    if ($term) {
                                        $media->{$field_name} = $term_id;
                                        $updated = TRUE;
                                        \Drupal::logger('media_attributes_manager')->debug('Updated taxonomy field @field for media @id with term ID @term_id', [
                                            '@field' => $field_name,
                                            '@id' => $media->id(),
                                            '@term_id' => $term_id
                                        ]);
                                    } else {
                                        \Drupal::logger('media_attributes_manager')->warning('Taxonomy term with ID @id not found for field @field', [
                                            '@id' => $term_id,
                                            '@field' => $field_name
                                        ]);
                                    }
                                } else {
                                    // Sinon, utiliser le label pour chercher le terme
                                    // Ce cas est peu probable avec notre implémentation améliorée
                                    \Drupal::logger('media_attributes_manager')->warning('Could not find or extract term ID for field @field, value: @value', [
                                        '@field' => $field_name,
                                        '@value' => $value
                                    ]);
                                    $media->{$field_name} = $value;
                                    $updated = TRUE;
                                }
                            } else {
                                // Pour les autres types de champs, utiliser la valeur telle quelle
                                $media->{$field_name} = $value;
                                $updated = TRUE;
                            }
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
