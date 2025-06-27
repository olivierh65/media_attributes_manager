<?php

namespace Drupal\media_attributes_manager\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Trait pour gérer les champs personnalisés d'une entité.
 *
 * Ce trait gère l'affichage et la manipulation des champs custom dans le formulaire
 * de bulk edit des médias. Il filtre les champs à afficher et gère les cas de
 * sélection unique vs multiple.
 */
trait CustomFieldsTrait {
  /**
   * Récupère les champs personnalisés d'une entité.
   *
   * Cette méthode filtre les champs pour ne montrer que :
   * - Les champs custom (non-base fields)
   * - Les champs spéciaux autorisés (description, alt text)
   * Mais PAS les champs media principaux (types: image, file, video_file, etc.)
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entité à inspecter.
   *
   * @return array
   *   Tableau associatif des champs custom (machine_name => definition).
   */
  public function getCustomFields(EntityInterface $entity) {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $fields = $entity->getFieldDefinitions();
    $custom_fields = [];

    // Types de champs à exclure (champs media principaux)
    // Ces types définissent le contenu principal du média et ne doivent pas être modifiés en masse
    $excluded_field_types = ['image', 'file', 'video_file', 'audio_file', 'document'];

    // Champs spéciaux à inclure même s'ils ne sont pas custom
    // Ces champs sont utiles à modifier en masse (métadonnées)
    $included_special_fields = [
      'field_media_image_alt_text',     // Alt text pour les images
      'field_media_image_title',        // Title pour les images
      'field_am_photo_description',     // Description personnalisée
    ];

    foreach ($fields as $field_name => $definition) {
      // Vérifier les critères d'inclusion/exclusion
      $is_custom = !$definition->getFieldStorageDefinition()->isBaseField();
      $is_special_included = in_array($field_name, $included_special_fields);
      $is_excluded = in_array($definition->getType(), $excluded_field_types);

      // Inclure si c'est un champ custom ou spécial autorisé, mais pas s'il est exclu
      if (($is_custom || $is_special_included) && !$is_excluded) {
        $custom_fields[$field_name] = $definition;
      }
    }
    return $custom_fields;
  }

  /**
   * R
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entité à inspecter.
   *
   * @return array
   *   Tableau associatif des valeurs (machine_name => valeur).
   */
  public function getCustomFieldValues(EntityInterface $entity) {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $custom_fields = $this->getCustomFields($entity);
    $values = [];
    foreach (array_keys($custom_fields) as $field_name) {
      $field = $entity->get($field_name);
      $definition = $custom_fields[$field_name];
      // Selon le type de champ, récupère la valeur différemment.
      switch ($definition->getType()) {
        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $value = $field->value;
          break;
        case 'boolean':
          $value = (bool) $field->value;
          break;
        case 'integer':
        case 'float':
        case 'decimal':
          $value = $field->value;
          break;
        case 'list_string':
        case 'list_integer':
          $value = $field->value;
          break;
        case 'entity_reference':
          $referenced = [];
          foreach ($field as $ref_item) {
            if ($ref_item->entity) {
              if ($ref_item->entity->getEntityTypeId() === 'taxonomy_term') {
                $referenced[] = $ref_item->entity->label();
              } else {
                $referenced[] = $ref_item->entity->id();
              }
            }
          }
          $value = $referenced;
          break;
        default:
          $value = $field->value ?? NULL;
          break;
      }
      $values[$field_name] = $value;
    }
    return $values;
  }


  /**
   * Obtient le libellé d'un bundle.
   *
   * @param string $bundle
   *   L'ID du bundle.
   *
   * @return string
   *   Le libellé du bundle.
   */
  protected function getBundleLabel($bundle) {
    // Remplacer les underscores par des espaces et capitaliser les mots
    return ucwords(str_replace('_', ' ', $bundle));
  }

  /**
   * Génère un sous-formulaire groupé par type d'entité (bundle) pour une liste d'entités.
   *
   * Cette méthode peut traiter un seul média ou plusieurs.
   * La logique varie :
   * - Pour UNE SEULE entité : les valeurs par défaut sont prises directement de l'entité
   * - Pour PLUSIEURS entités :
   *   - Si une seule valeur existe pour un champ, elle devient la valeur par défaut
   *   - Si plusieurs valeurs existent, un select est proposé avec toutes les valeurs possibles
   *   - Pour les checkboxes : si tous les médias ont la même valeur, on l'utilise par défaut
   * - Chaque champ est accompagné d'une checkbox "Clear" pour vider le champ
   *
   * @param \Drupal\Core\Entity\EntityInterface|\Drupal\Core\Entity\EntityInterface[] $entities
   *   Une entité ou un tableau d'entités.
   * @param callable|null $label_callback
   *   (optionnel) Callback pour générer le label du details, reçoit le bundle.
   * @param array $form
   *   (optionnel) Le tableau de formulaire à compléter.
   *
   * @return array
   *   Tableau de sous-formulaires groupés par bundle.
   */
  public function buildCustomFieldsFormByBundle($entities, callable $label_callback = null, array $form = []) {
    // Normaliser en tableau même si une seule entité est passée
    $entity_array = is_array($entities) ? $entities : [$entities];
    if (empty($entities)) {
      return [];
    }

    $form = [];
    $entities_by_bundle = [];

    // Regrouper les entités par bundle
    foreach ($entities as $entity) {
      if ($entity instanceof FieldableEntityInterface) {
        $bundle = $entity->bundle();
        if (!isset($entities_by_bundle[$bundle])) {
          $entities_by_bundle[$bundle] = [];
        }
        $entities_by_bundle[$bundle][] = $entity;
      }
    }

    // Pour chaque bundle, créer un conteneur avec les champs
    foreach ($entities_by_bundle as $bundle => $bundle_entities) {
      // Créer un élément details pour ce bundle
      $label = $bundle;
      if ($label_callback) {
        $label = $label_callback($bundle);
      } else {
        $label = $this->getBundleLabel($bundle);
      }

      // Créer un conteneur de type details pour ce bundle
      $form[$bundle] = [
        '#type' => 'details',
        '#title' => $label . ' <span>(' . $bundle . ')</span>',
        '#open' => TRUE,
        '#attributes' => [
          'class' => ['field-checkbox-wrapper'],
          'data-drupal-selector' => 'edit-' . $bundle,
          'id' => 'edit-' . $bundle . '--' . $this->generateRandomId(),
        ],
      ];

      // Récupérer les définitions de champs du premier média de ce bundle
      // (ils ont tous les mêmes champs puisqu'ils sont du même bundle)
      $first_entity = reset($bundle_entities);
      $field_definitions = $this->getCustomFields($first_entity);

      // Collecter toutes les valeurs possibles par champ
      $field_values_by_field = [];
      foreach ($bundle_entities as $entity) {
        $entity_values = $this->extractCustomFieldsValues($entity);
        foreach ($entity_values as $field_name => $value) {
          if (!isset($field_values_by_field[$field_name])) {
            $field_values_by_field[$field_name] = [];
          }

          // Convertir en chaîne pour éviter les doublons
          if (is_array($value)) {
            // Pour les références d'entités
            foreach ($value as $item) {
              if ($item) {
                $field_values_by_field[$field_name][] = (string) $item;
              }
            }
          } elseif ($value !== null) {
            $field_values_by_field[$field_name][] = (string) $value;
          }
        }
      }

      // Éliminer les doublons
      foreach ($field_values_by_field as $field_name => $values) {
        $field_values_by_field[$field_name] = array_unique($values);
      }

      // Construire les champs du formulaire
      foreach ($field_definitions as $field_name => $definition) {
        $type = $definition->getType();
        $values = $field_values_by_field[$field_name] ?? [];      // regroupe checkbox et champ principal dans un conteneur
        $form[$bundle][$field_name . '_group'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['field-checkbox-field-group'],
            'data-drupal-selector' => 'edit-' . $field_name . '-group',
            'id' => 'edit-' . $field_name . '-group--' . $this->generateRandomId(),
          ],
        ];
        // Ajouter la checkbox "Clear" en premier (à gauche)
        // Désactiver la checkbox Clear pour les champs EXIF
        $is_exif_field = $this->isExifField($field_name);
        $form[$bundle][$field_name . '_group'][$field_name . '_clear'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Clear'),
          '#default_value' => FALSE,
          '#disabled' => $is_exif_field,
          '#attributes' => [
            'class' => ['compact-checkbox', $is_exif_field ? 'exif-clear-disabled' : ''],
            'data-field-name' => $field_name . '_clear',
            'data-related-field' => $field_name,
            'data-bundle' => $bundle,
          ],
          '#prefix' => '<div class="field-checkbox">',
          '#suffix' => '</div>',
          '#title_display' => 'after',
        ];

        if ($is_exif_field) {
          $form[$bundle][$field_name . '_group'][$field_name . '_clear']['#description'] = $this->t('Cannot clear EXIF fields.');
        }

        // Créer un conteneur pour le champ principal
        $field_wrapper_class = 'field-container';

        // Spécial pour les taxonomies
        if (
          $type === 'entity_reference' &&
          $definition->getFieldStorageDefinition()->getSetting('target_type') === 'taxonomy_term'
        ) {
          $field_wrapper_class = 'taxonomy-field-container';
        }

        // Créer le conteneur pour le champ
        $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => [$field_wrapper_class, 'compact-field-row'],
            'data-drupal-selector' => 'edit-' . $field_name . '-wrapper',
            'id' => 'edit-' . $field_name . '-wrapper--' . $this->generateRandomId(),
          ],
        ];

        // Créer un champ de formulaire adapté au type et aux valeurs multiples
        // Les champs EXIF sont en lecture seule car ils seront écrasés lors de la prochaine extraction EXIF
        $is_exif_field = $this->isExifField($field_name);
        
        switch ($type) {
          case 'string':
          case 'string_long':
          case 'text':
          case 'text_long':
          case 'text_with_summary':
            if ($is_exif_field) {
              // Champ EXIF en lecture seule
              $single_value = !empty($values) ? reset($values) : '';
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                '#type' => 'textfield',
                '#title' => $definition->getLabel() . ' ' . $this->t('(EXIF - Read Only)'),
                '#default_value' => $single_value,
                '#disabled' => TRUE,
                '#attributes' => [
                  'class' => ['compact-field', 'exif-field-readonly'],
                  'data-field-name' => $field_name,
                  'data-field-type' => $type,
                  'data-bundle' => $bundle,
                  'readonly' => 'readonly',
                ],
                '#prefix' => '<div class="field-main exif-readonly">',
                '#suffix' => '</div>',
                '#title_display' => 'before',
                '#description' => $this->t('This field contains EXIF data and cannot be edited manually. Any changes will be overwritten when EXIF data is re-extracted.'),
              ];
            } else {
              // Champ normal éditable
              // Si plusieurs valeurs, créer un select2 avec options et possibilité de saisie libre
              if (count($values) > 1) {
                $options = array_combine($values, $values);
                $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                  '#type' => 'select2',
                  '#title' => $definition->getLabel(),
                  '#options' => $options,
                  '#empty_option' => $this->t('- Select -'),
                  '#attributes' => [
                    'class' => ['compact-field', 'bulk-edit-select2'],
                    'data-field-name' => $field_name,
                    'data-field-type' => $type,
                    'data-bundle' => $bundle,
                  ],
                  '#select2' => [
                    'placeholder' => $this->t('Select or type a new value'),
                    'allowClear' => TRUE,
                    'tags' => TRUE,
                    'width' => '100%',
                    'dropdownAutoWidth' => TRUE,
                     // voir autres parametres dans le fichier js
                  ],
                  '#prefix' => '<div class="field-main">',
                  '#suffix' => '</div>',
                  '#title_display' => 'before',
                ];
              } else {
                // Sinon un simple champ texte
                $single_value = !empty($values) ? reset($values) : '';
                $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                  '#type' => 'textfield',
                  '#title' => $definition->getLabel(),
                  '#default_value' => $single_value,
                  '#attributes' => [
                    'class' => ['compact-field'],
                    'data-field-name' => $field_name,
                    'data-field-type' => $type,
                    'data-bundle' => $bundle,
                  ],
                  '#prefix' => '<div class="field-main">',
                  '#suffix' => '</div>',
                  '#title_display' => 'before',
                ];
              }
            }
            break;

          case 'boolean':
            // Pour les champs booléens, on utilise la valeur commune si tous identiques
            $default_value = null;
            if (count($values) === count($bundle_entities)) {
              // Tous les médias ont une valeur pour ce champ
              $unique_values = array_unique($values);
              if (count($unique_values) === 1) {
                // Tous ont la même valeur
                $default_value = (bool) reset($unique_values);
              }
            }

            if ($is_exif_field) {
              // Champ EXIF booléen en lecture seule
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                '#type' => 'checkbox',
                '#title' => $definition->getLabel() . ' ' . $this->t('(EXIF - Read Only)'),
                '#default_value' => $default_value ?? FALSE,
                '#disabled' => TRUE,
                '#attributes' => [
                  'class' => ['compact-field', 'exif-field-readonly'],
                  'data-field-name' => $field_name,
                  'data-field-type' => $type,
                  'data-bundle' => $bundle,
                ],
                '#prefix' => '<div class="field-main exif-readonly">',
                '#suffix' => '</div>',
                '#title_display' => 'before',
                '#description' => $this->t('This field contains EXIF data and cannot be edited manually.'),
              ];
            } else {
              // Champ booléen normal éditable
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                '#type' => 'checkbox',
                '#title' => $definition->getLabel(),
                '#default_value' => $default_value ?? FALSE,
                '#attributes' => [
                  'class' => ['compact-field'],
                  'data-field-name' => $field_name,
                  'data-field-type' => $type,
                  'data-bundle' => $bundle,
                ],
                '#prefix' => '<div class="field-main">',
                '#suffix' => '</div>',
                '#title_display' => 'before',
              ];
            }
            break;
          case 'entity_reference':
            $target_type = $definition->getFieldStorageDefinition()->getSetting('target_type');

            // Configuration spécifique pour les taxonomies
            if ($target_type === 'taxonomy_term') {
              // Ajouter un champ caché pour stocker l'ID du terme
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name . '_tid'] = [
                '#type' => 'hidden',
                '#attributes' => [
                  'class' => ['taxonomy-term-id'],
                  'data-for-field' => $field_name,
                ],
              ];

              // Ajouter un select pour les valeurs disponibles de taxonomie
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name . '_values_selector'] = [
                '#type' => 'select',
                '#title' => $this->t('Available values'),
                '#title_display' => 'invisible',
                '#options' => ['' => $this->t('- Select a value -')],
                '#empty_option' => $this->t('- Select a value -'),
                '#attributes' => [
                  'class' => ['taxonomy-values-selector', 'form-select'],
                  'data-for-field' => $field_name,
                ],
                '#prefix' => '<div class="taxonomy-values-selector-wrapper">',
                '#suffix' => '</div>',
                '#wrapper_attributes' => [
                  'class' => ['taxonomy-values-selector-container'],
                ],
              ];
            }

            // Création du widget autocomplete
            $widget = [
              '#type' => 'entity_autocomplete',
              '#target_type' => $target_type,
              '#title' => $definition->getLabel(),
              '#attributes' => [
                'class' => ['compact-field'],
                'data-field-name' => $field_name,
                'data-field-type' => $type,
                'data-bundle' => $bundle,
              ],
              '#prefix' => '<div class="field-main">',
              '#suffix' => '</div>',
              '#title_display' => 'before',
            ];

            // IMPORTANT : Copier les paramètres du handler de sélection depuis la définition du champ
            $handler_settings = $definition->getSetting('handler_settings') ?? [];
            $selection_handler = $definition->getSetting('handler') ?? 'default:' . $target_type;

            $widget['#selection_handler'] = $selection_handler;
            $widget['#selection_settings'] = $handler_settings;

            // Configuration supplémentaire pour les taxonomies
            if ($target_type === 'taxonomy_term') {
              $widget['#tags'] = TRUE;
              $widget['#attributes']['data-taxonomy-field'] = 'true';
            }

            // Ajouter le widget au conteneur
            $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = $widget;
            break;

          default:
            // Pour tous les autres types, utiliser un champ texte simple
            $single_value = !empty($values) ? reset($values) : '';
            
            if ($is_exif_field) {
              // Champ EXIF en lecture seule
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                '#type' => 'textfield',
                '#title' => $definition->getLabel() . ' ' . $this->t('(EXIF - Read Only)'),
                '#default_value' => $single_value,
                '#disabled' => TRUE,
                '#attributes' => [
                  'class' => ['compact-field', 'exif-field-readonly'],
                  'data-field-name' => $field_name,
                  'data-field-type' => $type,
                  'data-bundle' => $bundle,
                  'readonly' => 'readonly',
                ],
                '#prefix' => '<div class="field-main exif-readonly">',
                '#suffix' => '</div>',
                '#title_display' => 'before',
                '#description' => $this->t('This field contains EXIF data and cannot be edited manually. Any changes will be overwritten when EXIF data is re-extracted.'),
              ];
            } else {
              // Champ normal éditable
              $form[$bundle][$field_name . '_group'][$field_name . '_wrapper'][$field_name] = [
                '#type' => 'textfield',
                '#title' => $definition->getLabel(),
                '#default_value' => $single_value,
                '#attributes' => [
                  'class' => ['compact-field'],
                  'data-field-name' => $field_name,
                  'data-field-type' => $type,
                  'data-bundle' => $bundle,
                ],
                '#prefix' => '<div class="field-main">',
                '#suffix' => '</div>',
                '#title_display' => 'before',
              ];
            }
            break;
        }
      }
    }

    return $form;
  }

  /**
   * Génère un sous-formulaire pour les champs personnalisés d'une ou plusieurs entités.
   *
   * Cette méthode unifiée gère à la fois:
   * - Le cas d'une SEULE entité (valeurs par défaut depuis l'entité)
   * - Le cas de PLUSIEURS entités (logique intelligente pour les valeurs communes)
   *
   * @param \Drupal\Core\Entity\EntityInterface|\Drupal\Core\Entity\EntityInterface[] $entities
   *   Une entité ou un tableau d'entités.
   * @param callable|null $label_callback
   *   (optionnel) Callback pour générer le label du details, reçoit le bundle.
   * @param array $form
   *   (optionnel) Le tableau de formulaire à compléter.
   *
   * @return array
   *   Un tableau de sous-formulaires pour les champs custom.
   */
  public function buildUnifiedCustomFieldsForm($entities, callable $label_callback = null, array $form = []) {
    // Normaliser en tableau même si une seule entité est passée
    $entity_array = is_array($entities) ? $entities : [$entities];

    if (empty($entity_array)) {
      return $form;
    }

    // Ajouter une classe CSS pour le styling
    $form['#attributes']['class'][] = 'media-attributes-bulk-edit-form';

    // Déterminer si nous traitons un seul élément ou plusieurs
    $single_entity = (count($entity_array) === 1);

    // Si c'est une entité unique, on peut la traiter directement
    if ($single_entity) {
      return $this->buildCustomFieldsFormByBundle($entity_array, $label_callback, $form);
      // return $this->buildCustomFieldsForm($entity_array[0], $form);
    }

    // Sinon on utilise la méthode existante pour plusieurs entités
    return $this->buildCustomFieldsFormByBundle($entity_array, $label_callback, $form);
  }

  /**
   * Génère un identifiant aléatoire pour les attributs HTML.
   *
   * @return string
   *   Identifiant aléatoire.
   */
  protected function generateRandomId() {
    return substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'), 0, 10);
  }

  protected function extractCustomFieldsValues(EntityInterface $entity) {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }

    $values = [];
    $custom_fields = $this->getCustomFields($entity);

    foreach ($custom_fields as $field_name => $definition) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        $values[$field_name] = NULL;
        continue;
      }

      // Selon le type de champ, extraction différente
      $type = $definition->getType();
      switch ($type) {
        case 'boolean':
          $value = (bool) $field->value;
          break;
        case 'entity_reference':
          $referenced = [];
          foreach ($field as $ref_item) {
            if ($ref_item->entity) {
              if (method_exists($ref_item->entity, 'label')) {
                $referenced[] = $ref_item->entity->label();
              } else {
                $referenced[] = $ref_item->entity->id();
              }
            }
          }
          $value = $referenced;
          break;
        default:
          $value = $field->value ?? NULL;
          break;
      }
      $values[$field_name] = $value;
    }
    return $values;
  }

  /**
   * Vérifie si un champ est un champ EXIF.
   *
   * @param string $field_name
   *   Le nom machine du champ.
   *
   * @return bool
   *   TRUE si c'est un champ EXIF, FALSE sinon.
   */
  protected function isExifField($field_name) {
    // Les champs EXIF commencent par 'field_exif_'
    return strpos($field_name, 'field_exif_') === 0;
  }

}
