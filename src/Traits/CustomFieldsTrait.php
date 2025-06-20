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
   * Récupère les valeurs des champs personnalisés d'une entité.
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
   * Génère un sous-formulaire pour les champs personnalisés d'une entité.
   * 
   * Utilisé pour le cas d'un SEUL média sélectionné.
   * Les valeurs par défaut sont prises directement depuis l'entité.
   * Chaque champ est accompagné d'une checkbox "Clear" pour vider le champ.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entité à inspecter.
   * @param array $form
   *   (optionnel) Le tableau de formulaire à compléter.
   *
   * @return array
   *   Un tableau de sous-formulaire Drupal pour les champs custom.
   */
  public function buildCustomFieldsForm(EntityInterface $entity, array $form = []) {
    if (!$entity instanceof FieldableEntityInterface) {
      return $form;
    }
    $custom_fields = $this->getCustomFields($entity);
    foreach ($custom_fields as $field_name => $definition) {
      $type = $definition->getType();
      switch ($type) {
        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $form[$field_name] = [
            '#type' => 'textfield',
            '#title' => $definition->getLabel(),
            '#default_value' => $entity->get($field_name)->value ?? '',
          ];
          // Ajouter checkbox "Clear" pour vider ce champ
          $form[$field_name . '_clear'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Clear @field', ['@field' => $definition->getLabel()]),
            '#default_value' => FALSE,
          ];
          break;
        case 'boolean':
          $form[$field_name] = [
            '#type' => 'checkbox',
            '#title' => $definition->getLabel(),
            '#default_value' => (bool) ($entity->get($field_name)->value ?? FALSE),
          ];
          // Ajouter checkbox "Clear" pour vider ce champ
          $form[$field_name . '_clear'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Clear @field', ['@field' => $definition->getLabel()]),
            '#default_value' => FALSE,
          ];
          break;
        case 'integer':
        case 'float':
        case 'decimal':
          $form[$field_name] = [
            '#type' => 'number',
            '#title' => $definition->getLabel(),
            '#default_value' => $entity->get($field_name)->value ?? '',
          ];
          // Ajouter checkbox "Clear" pour vider ce champ
          $form[$field_name . '_clear'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Clear @field', ['@field' => $definition->getLabel()]),
            '#default_value' => FALSE,
          ];
          break;
        case 'list_string':
        case 'list_integer':
          $allowed_values = $definition->getFieldStorageDefinition()->getSetting('allowed_values');
          $form[$field_name] = [
            '#type' => 'select',
            '#title' => $definition->getLabel(),
            '#options' => $allowed_values,
            '#default_value' => $entity->get($field_name)->value ?? '',
            '#empty_option' => $this->t('- Select -'),
          ];
          // Ajouter checkbox "Clear" pour vider ce champ
          $form[$field_name . '_clear'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Clear @field', ['@field' => $definition->getLabel()]),
            '#default_value' => FALSE,
          ];
          break;
        case 'entity_reference':
          $target_type = $definition->getFieldStorageDefinition()->getSetting('target_type');
          $widget = [
            '#type' => 'entity_autocomplete',
            '#target_type' => $target_type,
            '#title' => $definition->getLabel(),
            '#default_value' => $entity->get($field_name)->entity ?? NULL,
          ];
          
          // IMPORTANT : Copier les paramètres du handler de sélection depuis la définition du champ
          // Ceci résout le problème d'autocomplétion qui ne fonctionnait pas dans les modales
          $handler_settings = $definition->getSetting('handler_settings') ?? [];
          $selection_handler = $definition->getSetting('handler') ?? 'default:' . $target_type;
          
          $widget['#selection_handler'] = $selection_handler;
          $widget['#selection_settings'] = $handler_settings;
          
          // Configuration spécifique pour les taxonomies
          if ($target_type === 'taxonomy_term') {
            $widget['#tags'] = TRUE;
            
            // Ajouter un attribut data pour identifier facilement le champ comme taxonomie
            $widget['#attributes']['data-taxonomy-field'] = 'true';
          }
          
          $form[$field_name] = $widget;
          // Ajouter checkbox "Clear" pour vider ce champ
          $form[$field_name . '_clear'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Clear @field', ['@field' => $definition->getLabel()]),
            '#default_value' => FALSE,
          ];
          break;
        default:
          $form[$field_name] = [
            '#type' => 'textfield',
            '#title' => $definition->getLabel(),
            '#default_value' => $entity->get($field_name)->value ?? '',
          ];
          // Ajouter checkbox "Clear" pour vider ce champ
          $form[$field_name . '_clear'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Clear @field', ['@field' => $definition->getLabel()]),
            '#default_value' => FALSE,
          ];
          break;
      }
    }
    return $form;
  }

  /**
   * Génère un sous-formulaire groupé par type d'entité (bundle) pour une liste d'entités.
   * 
   * Utilisé pour le cas de PLUSIEURS médias sélectionnés.
   * La logique est plus complexe :
   * - Si une seule valeur existe pour un champ, elle devient la valeur par défaut
   * - Si plusieurs valeurs existent, un select est proposé avec toutes les valeurs possibles
   * - Pour les checkboxes : si tous les médias ont la même valeur, on l'utilise par défaut,
   *   sinon on affiche "Valeurs différentes parmi la sélection"
   * - Chaque champ est accompagné d'une checkbox "Clear all" pour vider le champ sur tous les médias
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   Tableau d'entités (médias, noeuds, etc.).
   * @param callable|null $label_callback
   *   (optionnel) Callback pour générer le label du details, reçoit le bundle.
   *
   * @return array
   *   Tableau de sous-formulaires groupés par bundle.
   */
  public function buildCustomFieldsFormByBundle(array $entities, callable $label_callback = null) {
    $by_bundle = [];
    foreach ($entities as $entity) {
      if ($entity instanceof FieldableEntityInterface) {
        $bundle = $entity->bundle();
        $by_bundle[$bundle][] = $entity;
      }
    }
    $form = [];
    foreach ($by_bundle as $bundle => $entity_list) {
      $first = reset($entity_list);
      $custom_fields = $this->getCustomFields($first);
      $fields_form = [];
      foreach ($custom_fields as $field_name => $definition) {
        $type = $definition->getType();
        
        // Récupérer toutes les valeurs existantes pour ce champ sur les entités du bundle
        // Pour analyser s'il y a une valeur unique ou plusieurs valeurs différentes
        $values = [];
        foreach ($entity_list as $entity) {
          $field = $entity->get($field_name);
          switch ($type) {
            case 'entity_reference':
              foreach ($field as $ref_item) {
                if ($ref_item->entity) {
                  $values[] = $ref_item->entity->id();
                }
              }
              break;
            default:
              $values[] = $field->value;
              break;
          }
        }
        $values = array_unique(array_filter($values, function($v) { return $v !== null && $v !== ''; }));
        $field_container = [];

        if ($type === 'boolean') {
          // Pour les champs boolean, récupérer toutes les valeurs boolean
          $boolean_values = [];
          foreach ($entity_list as $entity) {
            $field_value = $entity->get($field_name)->value;
            $boolean_values[] = (bool) $field_value;
          }

          // Vérifier si toutes les valeurs sont identiques
          $unique_boolean_values = array_unique($boolean_values);
          if (count($unique_boolean_values) === 1) {
            // Toutes les valeurs sont identiques, utiliser cette valeur comme défaut
            $default_boolean_value = reset($unique_boolean_values);
            $field_container[$field_name] = [
              '#type' => 'checkbox',
              '#title' => $definition->getLabel(),
              '#default_value' => $default_boolean_value,
            ];
          } else {
            // Valeurs différentes, checkbox décochée avec message d'information
            $field_container[$field_name] = [
              '#type' => 'checkbox',
              '#title' => $definition->getLabel(),
              '#default_value' => FALSE,
              '#description' => $this->t('Valeurs différentes parmi la sélection'),
            ];
          }
        } elseif ($type === 'entity_reference') {
          // Gestion spéciale pour les champs entity_reference
          $target_type = $definition->getFieldStorageDefinition()->getSetting('target_type');
          $referenced_entities = [];
          foreach ($entity_list as $entity) {
            $field = $entity->get($field_name);
            foreach ($field as $ref_item) {
              if ($ref_item->entity) {
                $referenced_entities[$ref_item->entity->id()] = $ref_item->entity;
              }
            }
          }

          // Gestion spéciale pour les champs taxonomie
          if ($target_type === 'taxonomy_term') {
            // Créer l'autocomplete widget qui sera toujours présent
            $field_container[$field_name] = $this->buildCustomFieldWidget($definition, NULL);
            
            // Ajouter un champ caché pour stocker l'ID du terme de taxonomie
            $hidden_id = $field_name . '_tid';
            $field_container[$hidden_id] = [
              '#type' => 'hidden',
              '#attributes' => [
                'class' => ['taxonomy-term-id'],
                'data-for-field' => $field_name,
              ],
            ];
            
            // Si nous avons des entités référencées, ajouter un select supplémentaire pour faciliter la sélection
            if (count($referenced_entities) > 0) {
              // Ajouter une liste déroulante avec les termes existants
              $options = [];
              foreach ($referenced_entities as $id => $entity) {
                $options[$id] = $entity->label();
              }
              
              // Si une seule valeur, la définir comme défaut dans l'autocomplete et dans le champ caché
              if (count($referenced_entities) === 1) {
                $default_entity = reset($referenced_entities);
                $field_container[$field_name]['#default_value'] = $default_entity;
                $field_container[$hidden_id]['#default_value'] = $default_entity->id();
              } else {
                // Plusieurs valeurs, ajouter un select pour choisir parmi les valeurs existantes
                $select_id = $field_name . '_selector';
                $field_container[$select_id] = [
                  '#type' => 'select',
                  '#title' => $this->t('Valeurs existantes pour @field', ['@field' => $definition->getLabel()]),
                  '#options' => $options,
                  '#empty_option' => $this->t('- Sélectionner une valeur existante -'),
                  '#attributes' => [
                    'class' => ['taxonomy-term-selector'],
                    'data-target-field' => $field_name,
                    'data-target-id-field' => $hidden_id,
                    'data-field-name' => $select_id,
                    'data-field-type' => 'taxonomy_selector',
                    'data-bundle' => $bundle,
                  ],
                  '#weight' => -1, // Pour placer le sélecteur avant le champ autocomplete
                ];
                
                // Ajouter des attributs spécifiques pour identifier ce champ comme taxonomie
                $field_container[$field_name]['#attributes']['data-has-selector'] = 'true';
                $field_container[$field_name]['#attributes']['data-selector-id'] = $select_id;
                $field_container[$field_name]['#attributes']['data-hidden-id-field'] = $hidden_id;
              }
            }
          } else {
            // Pour les autres types d'entités (non-taxonomies)
            if (count($referenced_entities) === 1) {
              // Une seule entité référencée, utiliser entity_autocomplete avec défaut
              $default_entity = reset($referenced_entities);
              $field_container[$field_name] = $this->buildCustomFieldWidget($definition, $default_entity->id());
            } elseif (count($referenced_entities) > 1) {
              // Plusieurs entités référencées, créer un select avec les labels
              $options = [];
              foreach ($referenced_entities as $id => $entity) {
                $options[$id] = $entity->label();
              }
              $field_container[$field_name] = [
                '#type' => 'select',
                '#title' => $definition->getLabel(),
                '#options' => $options,
                '#empty_option' => $this->t('- Choisir -'),
                '#default_value' => NULL,
              ];
            } else {
              // Aucune entité référencée, entity_autocomplete vide
              $field_container[$field_name] = $this->buildCustomFieldWidget($definition, NULL);
            }
          }
        } elseif (count($values) === 1) {
          // Une seule valeur, champ classique avec valeur par défaut.
          $default_value = reset($values);
          $field_container[$field_name] = $this->buildCustomFieldWidget($definition, $default_value);
        } elseif (count($values) > 1) {
          // Plusieurs valeurs, select sans valeur par défaut.
          $options = array_combine($values, $values);
          $field_container[$field_name] = [
            '#type' => 'select',
            '#title' => $definition->getLabel(),
            '#options' => $options,
            '#empty_option' => $this->t('- Choisir -'),
            '#default_value' => NULL,
          ];
        } else {
          // Aucune valeur, champ classique vide.
          $field_container[$field_name] = $this->buildCustomFieldWidget($definition, NULL);
        }
        // Ajouter des attributs data pour faciliter le traitement dans JS
        $field_container[$field_name]['#attributes']['data-field-name'] = $field_name;
        $field_container[$field_name]['#attributes']['data-field-type'] = $type;
        $field_container[$field_name]['#attributes']['data-bundle'] = $bundle;
        
        // Ajout de la checkbox "clear all" pour ce champ.
        $field_container[$field_name . '_clear'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Clear all'),
          '#default_value' => 0,
          '#attributes' => [
            'data-field-name' => $field_name . '_clear',
            'data-related-field' => $field_name,
            'data-bundle' => $bundle,
          ],
        ];
        $fields_form += $field_container;
      }
      $label = $label_callback ? call_user_func($label_callback, $bundle) : $bundle;
      $form[$bundle] = [
        '#type' => 'details',
        '#title' => $label,
        '#open' => TRUE,
      ] + $fields_form;
    }
    return $form;
  }

  /**
   * Génère le widget de formulaire pour un champ custom selon son type et une valeur par défaut.
   * 
   * IMPORTANT : Cette méthode gère correctement les champs entity_reference
   * en chargeant les entités à partir des IDs pour éviter les erreurs 500.
   */
  protected function buildCustomFieldWidget($definition, $default_value) {
    $type = $definition->getType();
    $field_name = $definition->getName();
    
    // Créer un widget de base selon le type
    $widget = $this->createBaseWidget($type, $definition, $default_value);
    
    // Ajouter les attributs data- pour faciliter le traitement JS
    if (!isset($widget['#attributes'])) {
      $widget['#attributes'] = [];
    }
    $widget['#attributes']['data-field-name'] = $field_name;
    $widget['#attributes']['data-field-type'] = $type;
    
    return $widget;
  }
  
  /**
   * Crée un widget de base selon le type de champ.
   */
  private function createBaseWidget($type, $definition, $default_value) {
    switch ($type) {
      case 'string':
      case 'string_long':
      case 'text':
      case 'text_long':
      case 'text_with_summary':
        return [
          '#type' => 'textfield',
          '#title' => $definition->getLabel(),
          '#default_value' => $default_value ?? '',
        ];
      case 'boolean':
        return [
          '#type' => 'checkbox',
          '#title' => $definition->getLabel(),
          '#default_value' => (bool) ($default_value ?? FALSE),
        ];
      case 'integer':
      case 'float':
      case 'decimal':
        return [
          '#type' => 'number',
          '#title' => $definition->getLabel(),
          '#default_value' => $default_value ?? '',
        ];
      case 'list_string':
      case 'list_integer':
        $allowed_values = $definition->getFieldStorageDefinition()->getSetting('allowed_values');
        return [
          '#type' => 'select',
          '#title' => $definition->getLabel(),
          '#options' => $allowed_values,
          '#default_value' => $default_value ?? '',
          '#empty_option' => $this->t('- Select -'),
        ];
      case 'entity_reference':
        $target_type = $definition->getFieldStorageDefinition()->getSetting('target_type');
        $widget = [
          '#type' => 'entity_autocomplete',
          '#target_type' => $target_type,
          '#title' => $definition->getLabel(),
        ];
        
        // IMPORTANT : Copier les paramètres du handler de sélection depuis la définition du champ
        // Ceci résout le problème d'autocomplétion qui ne fonctionnait pas dans les modales
        $handler_settings = $definition->getSetting('handler_settings') ?? [];
        $selection_handler = $definition->getSetting('handler') ?? 'default:' . $target_type;
        
        $widget['#selection_handler'] = $selection_handler;
        $widget['#selection_settings'] = $handler_settings;

        // Pour les champs entity_reference, on doit charger l'entité si on a un ID
        // Les widgets entity_autocomplete attendent un objet entité, pas un ID
        $entity_default = NULL;
        if ($default_value) {
          try {
            $entity_default = \Drupal::entityTypeManager()->getStorage($target_type)->load($default_value);
          } catch (\Exception $e) {
            // Si le chargement échoue, on laisse NULL
            $entity_default = NULL;
          }
        }
        $widget['#default_value'] = $entity_default;
        
        // Configuration spécifique pour les taxonomies
        if ($target_type === 'taxonomy_term') {
          $widget['#tags'] = TRUE;
          
          // Ajouter un attribut data pour identifier facilement le champ comme taxonomie
          $widget['#attributes']['data-taxonomy-field'] = 'true';
          
          // Si nous avons une entité par défaut, stocker son ID
          if ($entity_default) {
            $widget['#attributes']['data-term-id'] = $entity_default->id();
          }
        }
        
        return $widget;
      default:
        return [
          '#type' => 'textfield',
          '#title' => $definition->getLabel(),
          '#default_value' => $default_value ?? '',
        ];
    }
  }
}
