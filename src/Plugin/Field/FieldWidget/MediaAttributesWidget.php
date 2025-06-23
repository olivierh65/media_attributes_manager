<?php

namespace Drupal\media_attributes_manager\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldDefinition;
use Drupal\entity_browser\Plugin\Field\FieldWidget\EntityReferenceBrowserWidget;
use Drupal\entity_browser\Plugin\Field\FieldWidget\FileBrowserWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldWidget;
use Drupal\Core\Render\Element;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Component\Utility\NestedArray;
use Drupal\media_attributes_manager\Traits\CustomFieldsTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of 'media_attributes_widget' widget.
 *
 * @FieldWidget(
 *   id = "media_attributes_widget",
 *   label = @Translation("Media Attributes Widget"),
 *   field_types = {
 *     "entity_reference"
 *   },
 *   multiple_values = TRUE
 * )
 */

class MediaAttributesWidget extends EntityReferenceBrowserWidget {
  use CustomFieldsTrait;

  /**
   * Profondeur du bouton de suppression dans l'arborescence du formulaire.
   *
   * Cette variable est définie dans la classe parente EntityReferenceBrowserWidget,
   * mais nous la redéfinissons ici pour assurer qu'elle existe dans notre classe.
   *
   * @var int
   */
  protected static $deleteDepth = 3;

  protected $field_definition;

  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    // Appelle la méthode parent pour obtenir l'élément de formulaire de base.
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Récupère le FieldDefinition
    $this->field_definition = $items->getFieldDefinition();

    // Parcourt les médias déjà sélectionnés
    foreach ($items as $item_key => $item) {
      if (!empty($item->target_id)) {
        $media = \Drupal::entityTypeManager()->getStorage('media')->load($item->target_id);
        if ($media) {
          foreach ($media->getFieldDefinitions() as $field_name => $definition) {
            // Ignore les champs de base (comme 'mid', 'bundle', etc.)
            if ($media->hasField($field_name) && !$definition->getFieldStorageDefinition()->isBaseField()) {
              $field = $media->get($field_name);
              $value = NULL;

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

              // Ajoute dans $element['current']['items'][$item_key]['#values'][$field_name]
              $element['current']['items'][$item_key]['#values'][$field_name] = [
                'label' => $definition->getLabel(),
                'type' => $definition->getType(),
                'value' => $value,
              ];

              // Ajoute également les valeurs comme attributs data pour le tooltip
              // Adaptez la clé pour qu'elle soit utilisable comme attribut HTML
              $safe_field_name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $field_name));

              // Format et convertit la valeur pour un attribut HTML
              $attr_value = $value;
              if (is_array($value)) {
                $attr_value = implode(', ', $value);
              }
              elseif (is_bool($value)) {
                $attr_value = $value ? 'true' : 'false';
              }

              // Limite la longueur pour éviter des attributs trop grands
              $attr_value = is_scalar($attr_value) ? substr((string) $attr_value, 0, 255) : '';

              // Ajoute l'attribut data-*
              $element['current']['items'][$item_key]['#attributes']['data-media-attr-' . $safe_field_name] = (string) $attr_value;

              // Ajoute également l'étiquette du champ
              $element['current']['items'][$item_key]['#attributes']['data-media-label-' . $safe_field_name] = (string) $definition->getLabel();
            }
          }
        }
      }
    }
    return $element;
  }

  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {

    $element = parent::form($items, $form, $form_state, $get_delta);

    // Ajoute un champ caché pour la sélection côté JS
    $element['media_selected'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['media-selected-hidden'],
      ],
      '#default_value' => '',
    ];

    $this->setMediaDirectoriesModalConfig($items, $element);

    // Ajout d'un div pour la fenêtre modale.
    // On ajoute un div avec l'ID 'drupal-modal' pour que le JS puisse l'utiliser.
    $element['#prefix'] = '<div id="drupal-modal" class="hidden"></div>' . ($element['#prefix'] ?? '');

    // Ajoute notre librairie pour forcer l'initialisation du sortable
    $element['#attached']['library'][] = 'media_attributes_manager/sortable';

    // Amélioration du support AJAX pour la suppression des médias
    $element['#attached']['library'][] = 'core/drupal.ajax';
    $element['#attached']['library'][] = 'core/drupal.dialog.ajax';

    // Ajoute le CSS du widget Media Directories UI si besoin.
    if ($this->getSetting('entity_browser') === 'media_directories_modal') {
      $element['#attached']['library'][] = 'media_directories_ui/widget';
    }

    // Ajoute la librairie JS d'Entity Browser pour le drag & drop.
    $element['#attached']['library'][] = 'entity_browser/entity_browser.entity_reference';


    // Ajoute nos styles personnalisés
    $element['#attached']['library'][] = 'media_attributes_manager/widget';

    //Ajoute JS de selection multiple (shift + click)
    $element['#attached']['library'][] = 'media_attributes_manager/selection';

    // Attach the bulk edit handler library for the "Bulk Edit" button
    $element['#attached']['library'][] = 'media_attributes_manager/bulk_edit_handler';

$form['#attached']['library'][] = 'media_attributes_manager/masonry_grid';

    // Ajoute le process callback pour ajouter les boutons d'édition en masse.
    if (isset($element['widget']['entity_browser'])) {
      if (!isset($element['widget']['entity_browser']['#process']) || !is_array($element['widget']['entity_browser']['#process'])) {
        $element['widget']['entity_browser']['#process'] = [];
      }
      // Évite d'ajouter plusieurs fois le même callback
      $already = false;
      foreach ($element['widget']['entity_browser']['#process'] as $callback) {
        if (is_array($callback) && $callback[0] === static::class && $callback[1] === 'processBulkButtons') {
          $already = true;
          break;
        }
      }
      if (!$already) {
        $element['widget']['entity_browser']['#process'][] = [static::class, 'processBulkButtons'];
      }
    }



    return $element;
  }

  private function setMediaDirectoriesModalConfig(FieldItemListInterface $items, array &$element) {
    // Configuration spécifique pour le modal Media Directories.
    // et recuperer les fonctionnalites de Media Directories UI.

    $element['#attached']['library'][] = 'core/drupal.dialog.ajax';
$element['#attached']['library'][] = 'core/jquery.form';

    $widget = $this;
    if ($widget->getSetting('entity_browser') === 'media_directories_modal') {
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $items */

      $handler_settings = $items->getSetting('handler_settings');
      $target_bundles = $handler_settings['target_bundles'] ?? [];
      // Add bundle validation constraint to entity browser.
      $element['entity_browser']['#entity_browser_validators']['target_bundles'] = ['bundle' => $target_bundles];

      $current_items = $element['widget']['current']['items'];
      $cardinality = $element['entity_browser']['#cardinality'] ?? 1;

      $element['entity_browser']['#widget_context']['remaining'] = $cardinality - count($current_items);

      // Attach custom css to modify widget to look a bit more like media_library widget.
      $element['widget']['#attached']['library'][] = 'media_directories_ui/widget';

      $active_theme = \Drupal::theme()->getActiveTheme();

      switch ($active_theme->getName()) {
        case 'claro':
          $element['widget']['#attached']['library'][] = 'claro/media_library.theme';
          break;
        case 'gin':
          $element['widget']['#attached']['library'][] = 'gin/media_library';
          $element['widget']['#attached']['library'][] = 'media_directories_ui/widget.gin';
          break;
      }

      $element['widget']['#attributes']['class'][] = 'media-directories-widget';
      $element['widget']['current']['#attributes']['class'][] = 'media-library-selection';

      // Add additional attributes to use media library styles.
      foreach ($element['widget']['current']['items'] as &$item) {
        $item['#attributes']['class'][] = 'media-library-item';
        $item['#attributes']['class'][] = 'media-library-item--grid';
        $item['#attributes']['tabindex'] = '-1';
      }
    }
  }

  protected function displayCurrentSelection($details_id, array $field_parents, array $entities) {
    $render_array = parent::displayCurrentSelection($details_id, $field_parents, $entities);

    // Ajouter des identifiants uniques et cohérents pour cibler les widgets avec AJAX
    if (!isset($render_array['#attributes']['id'])) {
      $render_array['#attributes']['id'] = $details_id . '-selection-area';
    }
    if (!isset($render_array['#attributes']['class'])) {
      $render_array['#attributes']['class'] = [];
    }
    $render_array['#attributes']['class'][] = 'media-attributes-selection';
    $render_array['#attributes']['class'][] = 'ajax-rebuild-target';

    // Pour chaque bouton de suppression, s'assurer qu'il utilise notre rappel AJAX
    foreach ($render_array['items'] as $key => &$item) {
      if (isset($item['remove_button'])) {
        // Ajouter notre rappel AJAX pour actualiser correctement les widgets
        $item['remove_button']['#ajax'] = [
          'callback' => [static::class, 'updateWidgetCallback'],
          'wrapper' => $details_id,
          'effect' => 'fade',
          'progress' => [
            'type' => 'throbber',
            'message' => new TranslatableMarkup('Removing media...'),
          ],
        ];

        // Ajouter des attributs supplémentaires pour faciliter le targeting AJAX
        $item['remove_button']['#attributes']['class'][] = 'use-ajax';
        $item['remove_button']['#attributes']['data-wrapper'] = $details_id;
      }
    }

    // Assurez-vous que la valeur target_id est définie correctement dans le widget
    // Cette valeur sera utilisée par removeItemSubmit()
    $entity_ids = [];
    foreach ($entities as $entity) {
      $entity_ids[] = 'media:' . $entity->id();
    }
    $ids_string = implode(' ', $entity_ids);

    // Debug: Log structure pour comprendre comment les médias sont stockés
    \Drupal::logger('media_attributes_manager')->debug('Structure des IDs dans displayCurrentSelection: @ids', [
      '@ids' => $ids_string
    ]);

    // S'assurer que target_id est défini dans le render_array
    $render_array['target_id'] = [
      '#type' => 'hidden',
      '#value' => $ids_string,
      '#attributes' => ['data-entity-ids' => $ids_string],
    ];

    // Classes sur le conteneur principal
    $render_array['#attributes']['class'] = [
      'entities-list',
      'entity-type--media',
      'sortable',
      'media-library-selection',
    ];
    // vvvv Necessaire pour permettre le drag & drop vvvvv
    $render_array['#attributes']['data-entity-browser-entity-reference-list'] = TRUE;

    // recupere la taille de la vignette
    // On utilise l'image style 'medium' par défaut, mais on pourrait le rendre configurable
    // si besoin.
    $image_style = \Drupal::entityTypeManager()
      ->getStorage('image_style')
      ->load('medium');

    $width = NULL;
    $height = NULL;

    if ($image_style) {
      foreach ($image_style->getEffects() as $effect) {
        if (in_array($effect->getPluginId(), ['image_scale_and_crop', 'image_scale'])) {
          $data = $effect->getConfiguration()['data'];
          $width = $data['width'] ?? NULL;
          $height = $data['height'] ?? NULL;
          break;
        }
      }
    }    // regroupe les boutons d'action et ajoute la checkbox de sélection
    foreach ($render_array['items'] as $key => &$item) {
      $item['buttons']['edit_button'] = $item['edit_button'];
      $item['buttons']['remove_button'] = $item['remove_button'];

      // Ensure we have a valid details_id for the wrapper
      if (!$details_id && isset($render_array['#id'])) {
        $details_id = $render_array['#id'];
      }

      // Configuration AJAX optimisée pour le bouton de suppression
      $item['buttons']['remove_button']['#ajax'] = [
        'callback' => [static::class, 'updateWidgetCallback'],
        'wrapper' => $details_id,
        'effect' => 'fade',
        'progress' => ['type' => 'throbber'],
        'event' => 'click',
        'method' => 'replace',
      ];

      // Store the wrapper ID and other necessary data for better identification
      $item['buttons']['remove_button']['#attributes']['data-wrapper-id'] = $details_id;
      $item['buttons']['remove_button']['#attributes']['data-target-id'] = $item['#attributes']['data-entity-id'] ?? '';
      $item['buttons']['replace_button'] = $item['replace_button'];
      $item['buttons']['select_checkbox'] = [
        '#type' => 'checkbox',
        '#title' => '',
        '#attributes' => [
          'class' => ['media-item-select-checkbox', 'form-checkbox'],
          'data-row-id' => 0,
          'style' => 'margin:0;',
        ],
        '#weight' => -30,
      ];

      $item['buttons']['#theme'] = 'media_attributes_widget_actions';

      if (!isset($item['buttons']['edit_button']['#attributes']['class'])) {
        $item['buttons']['edit_button']['#attributes']['class'] = [];
      }

      $item['buttons']['edit_button']['#attributes']['class'] = array_merge(
        $item['buttons']['edit_button']['#attributes']['class'],
        [
          'media-library-item__edit',
          'icon-link',
        ]
      );

      if (!isset($item['buttons']['remove_button']['#attributes']['class'])) {
        $item['buttons']['remove_button']['#attributes']['class'] = [];
      }
      $item['buttons']['remove_button']['#attributes']['class'] = array_merge(
        $item['buttons']['remove_button']['#attributes']['class'],
        [
          'media-library-item__remove',
          'icon-link',
        ]
      );
      if (!isset($item['buttons']['replace_button']['#attributes']['class'])) {
        $item['buttons']['replace_button']['#attributes']['class'] = [];
      }
      $item['buttons']['replace_button']['#attributes']['class'] = array_merge(
        $item['buttons']['replace_button']['#attributes']['class'],
        [
          'media-library-item__replace',
          'icon-link',
        ]
      );

      unset($item['edit_button']);
      unset($item['remove_button']);
      unset($item['replace_button']);

      $item['display']['#theme'] = 'media_attributes_manager_item';

      if (is_array($item) && isset($item['display']['#media'])) {
        $media = $item['display']['#media'];

        // On s'assure que le media est bien chargé
        if (!$media instanceof \Drupal\media\Entity\Media) {
          continue;
        }

        // Récupère le bundle et le type de source du média
        $bundle = $media->bundle();
        $bundle_config = \Drupal::service('entity_type.bundle.info')->getBundleInfo('media');
        $media_type = \Drupal::entityTypeManager()->getStorage('media_type')->load($bundle);

        // Récupère les champs d'image ou de vidéo selon le type de média
        $image_field = $this->getImageFieldName($media, 'image');
        $video_field = $this->getImageFieldName($media, 'video');

        // Initialise les données à afficher
        // Récupérer TOUS les champs personnalisés et leurs valeurs via le trait
        $custom_fields = $this->getCustomFields($media);
        $custom_values = $this->getCustomFieldValues($media);

        // Préparer les données pour le template, en incluant tous les champs disponibles
        $values_for_tooltip = [];
        foreach ($custom_fields as $field_name => $definition) {
          if (isset($custom_values[$field_name])) {
            // Format spécifique pour le tooltip
            $value = $custom_values[$field_name];
            // Gère les cas spéciaux pour l'affichage
            if (is_array($value) && !empty($value)) {
              // Pour les références d'entités (ex: taxonomie), on affiche les labels
              $value = implode(', ', $value);
            }
            else if ($value === NULL) {
              $value = '';
            }
            else if (is_bool($value)) {
              $value = $value ? 'Oui' : 'Non';
            }

            $values_for_tooltip[$field_name] = [
              'label' => $definition->getLabel(),
              'type' => $definition->getType(),
              'value' => $value,
              'machine_name' => $field_name,
            ];
          }
        }

        // Fusionner avec toutes les autres valeurs déjà présentes
        if (isset($item['#values']) && is_array($item['#values'])) {
          foreach ($item['#values'] as $field_name => $field_data) {
            if (!isset($values_for_tooltip[$field_name])) {
              $values_for_tooltip[$field_name] = $field_data;
            }
          }
        }

        // Log de débogage pour vérifier les valeurs disponibles
        \Drupal::logger('media_attributes_manager')->debug('Champs personnalisés pour le média @id: @fields', [
          '@id' => $media->id(),
          '@fields' => print_r(array_keys($values_for_tooltip), TRUE)
        ]);

        $datas = [
          'media_id' => $media->id(),
          'media_url' => $media->toUrl()->toString(),
          'media_type' => $bundle,
          'media_base_type' => $media_type->getSource()->getPluginId() ?? NULL,
          'media_name' => $media->label(),
          'media_thumbnail_url' => '',
          'media_thumbnail_alt' => '',
          'media_thumbnail_title' => '',
          'media_thumbnail_width' => $width,
          'media_thumbnail_height' => $height,
          'media_video_url' => '',
          'media_title' => $media->label(),
          // Inclure toutes les valeurs des champs personnalisés pour le tooltip
          'values' => $values_for_tooltip,
        ];

        // Si c'est une image, on récupère la vignette
        if ($media_type && $media_type->getSource()->getPluginId() === 'image') {
          $image_field = $this->getImageFieldName($media, 'image');
          if ($image_field && !$media->get($image_field)->isEmpty()) {
            $image = $media->get($image_field)->entity;
            $image_item = $media->get($image_field)->first();
            $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load('medium');
            if ($image && $image_style) {
              $thumbnail_url = $image_style->buildUrl($image->getFileUri());
              foreach ($image_style->getEffects() as $effect) {
                if (in_array($effect->getPluginId(), ['image_scale_and_crop', 'image_scale'])) {
                  $data = $effect->getConfiguration()['data'];
                  $width = $data['width'] ?? NULL;
                  $height = $data['height'] ?? NULL;
                  break;
                }
              }
            }
            $thumbnail_alt = $image_item->get('alt')->getString();
            $thumbnail_title = $image_item->get('title')->getString();
          }
        } elseif ($media_type && $media_type->getSource()->getPluginId() === 'video_file') {
            // Récupère le nom du champ fichier vidéo (généralement field_media_video_file)
            $video_field = $this->getImageFieldName($media, 'file');

          // Récupère l'URL du fichier vidéo
          if ($media->hasField($video_field) && !$media->get($video_field)->isEmpty()) {
            $file = $media->get($video_field)->entity;
            if ($file) {
              $video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
            }
          }
          // TODO: A tester
          // Récupère la vignette si un champ image existe
          if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
            $image = $media->get('field_media_image')->entity;
            $image_item = $media->get('field_media_image')->first();
            $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load('medium');
            if ($image && $image_style) {
              $thumbnail_url = $image_style->buildUrl($image->getFileUri());
              foreach ($image_style->getEffects() as $effect) {
                if (in_array($effect->getPluginId(), ['image_scale_and_crop', 'image_scale'])) {
                  $data = $effect->getConfiguration()['data'];
                  break;
                }
              }
            }
            $thumbnail_alt = $image_item->get('alt')->getString();
            $thumbnail_title = $image_item->get('title')->getString();
          }
        }

        // Complète $datas
        $datas['media_video_url'] = $video_url ?? '';
        $datas['media_thumbnail_url'] = $thumbnail_url;
        $datas['media_thumbnail_alt'] = $thumbnail_alt;
        $datas['media_thumbnail_title'] = $thumbnail_title;
        $datas['media_thumbnail_width'] = $data['width'] ?? $width;
        $datas['media_thumbnail_height'] = $data['height'] ?? $height;

        // Remplace l'item par le render array thémé attendu par Drupal
        // Récupère les classes existantes et ajoute celles nécessaires
        $attributes = $item['#attributes'] ?? [];
        $attributes['class'] = array_unique(array_merge(
          $attributes['class'] ?? [],
          [
            'item-container',
            'rendered-entity',
            'media-library-item',
            'media-library-item--grid',
            'draggable',
          ]
        ));
        $attributes['data-entity-id'] = 'media:' . $datas['media_id'];
        $attributes['data-row-id'] = $key;

        $item['#attributes'] = $attributes;
        // maintenant recuperes les données du media
        $item['display']['#datas'] = $datas;

      }
    }


    return $render_array;
  }


  private function getImageFieldName($media, $type) {
    foreach ($media->getFieldDefinitions() as $field_name => $definition) {
      if (
        $media->hasField($field_name)
        && !$definition->getFieldStorageDefinition()->isBaseField()
        && $definition->getType() === $type
      ) {
        return $field_name;
      }
    }
    return NULL;
  }

  /**
   * Submit handler pour Bulk Remove.
   */
  public static function bulkRemoveSelected(array &$form, FormStateInterface $form_state) {
    // À compléter : supprimer les médias sélectionnés.
    // Vous pouvez utiliser $form_state->getUserInput() pour récupérer les IDs sélectionnés.
    // Puis, retirez-les de $form_state ou du field item list.
    \Drupal::messenger()->addMessage(\Drupal::translation()->translate('Bulk remove not yet implemented.'));
  }

  /**
   * Traite les actions de suppression d'éléments média.
   *
   * Override de la méthode parente pour corriger le bug de suppression.
   */
  public static function removeItemSubmit(&$form, FormStateInterface $form_state) {

   /*  parent::removeItemSubmit($form, $form_state);
    return; */

    try {
      $triggering_element = $form_state->getTriggeringElement();
      if (!empty($triggering_element['#attributes']['data-entity-id'])) {
        // Format expected: "media:123"
        $id = $triggering_element['#attributes']['data-entity-id'];
        $media_id = preg_replace('/^media:/', '', $id);

        // Log relevant info without using print_r on large objects
        \Drupal::logger('media_attributes_manager')->debug('Removing media ID: @id', ['@id' => $media_id]);

        // Trouver le nom du champ
        $field_name = '';
        foreach ($triggering_element['#array_parents'] as $part) {
          if (strpos($part, 'field_') === 0) {
            $field_name = $part;
            break;
          }
        }
        \Drupal::logger('media_attributes_manager')->debug('Field name found: @field', ['@field' => $field_name]);

        // Debug: Log the user input structure to understand the format
        $user_input = $form_state->getUserInput();
        if (isset($user_input[$field_name])) {
          $debug_input = $user_input[$field_name];
          // Convert possibly large arrays to readable format without full dump
          if (isset($debug_input['target_id'])) {
            \Drupal::logger('media_attributes_manager')->debug('User input format: target_id directly in field_name - Value: @value', [
              '@value' => $debug_input['target_id']
            ]);
          }
          if (isset($debug_input['current']['target_id'])) {
            \Drupal::logger('media_attributes_manager')->debug('User input format: target_id in current subarray - Value: @value', [
              '@value' => $debug_input['current']['target_id']
            ]);
          }

          // Log the structure of the array
          $structure = [];
          foreach ($debug_input as $key => $value) {
            if (is_array($value)) {
              $structure[$key] = array_keys($value);
            } else {
              $structure[$key] = gettype($value);
            }
          }
          \Drupal::logger('media_attributes_manager')->debug('User input structure: @structure', [
            '@structure' => json_encode($structure)
          ]);
        } else {
          \Drupal::logger('media_attributes_manager')->debug('User input does not contain field_name key');
        }

        // Récupérer l'entité
        if (!empty($field_name)) {
          $form_object = $form_state->getFormObject();
          if (method_exists($form_object, 'getEntity')) {
            $entity = $form_object->getEntity();

            if ($entity && $entity->hasField($field_name)) {
              // Récupération des valeurs actuelles
              $current_values = $entity->get($field_name)->getValue();
              \Drupal::logger('media_attributes_manager')->debug('Current values: @values', ['@values' => json_encode($current_values)]);

              // Filtrer pour supprimer le média
              $new_values = [];
              foreach ($current_values as $delta => $value) {
                if (!isset($value['target_id']) || $value['target_id'] != $media_id) {
                  $new_values[] = $value;
                }
              }

              // Mise à jour du champ
              $entity->set($field_name, $new_values);
              \Drupal::logger('media_attributes_manager')->debug('Updated entity field. New values: @values', ['@values' => json_encode($new_values)]);

              // Mise à jour de l'état du formulaire
              $parents = array_slice($triggering_element['#array_parents'], 0, -static::$deleteDepth);
              $field_state = NestedArray::getValue($form_state->getStorage(), $parents);
              if (!empty($field_state)) {
                // Mettre à jour l'état du champ
                NestedArray::setValue($form_state->getStorage(), $parents, $new_values);
              }

              // Mise à jour des valeurs de formulaire pour les champs target_id
              $values = $form_state->getValues();
              if (isset($values[$field_name])) {
                // Log de la structure initiale
                \Drupal::logger('media_attributes_manager')->debug('Form values structure for @field: @structure', [
                  '@field' => $field_name,
                  '@structure' => json_encode(array_keys($values[$field_name]))
                ]);

                // Cas 1: Format 'target_id' avec chaîne de caractères
                if (isset($values[$field_name]['target_id']) && is_string($values[$field_name]['target_id'])) {
                  $target_ids = explode(' ', $values[$field_name]['target_id']);
                  $filtered_ids = [];
                  foreach ($target_ids as $target_id) {
                    $id_value = preg_replace('/^media:/', '', $target_id);
                    if ($id_value != $media_id) {
                      $filtered_ids[] = $target_id;
                    }
                  }
                  $values[$field_name]['target_id'] = implode(' ', $filtered_ids);
                }

                // Cas 2: Format avec valeurs indexées numériquement
                if (isset($values[$field_name]['target_id']) && is_array($values[$field_name]['target_id'])) {
                  foreach ($values[$field_name]['target_id'] as $delta => $tid) {
                    if ($tid == $media_id) {
                      unset($values[$field_name]['target_id'][$delta]);
                    }
                  }
                }

                // Nettoyer les valeurs pour éliminer les espaces vides dans les arrays
                if (isset($values[$field_name]['target_id']) && is_array($values[$field_name]['target_id'])) {
                  $values[$field_name]['target_id'] = array_values($values[$field_name]['target_id']);
                }

                // Mettre à jour les valeurs dans le form_state
                $form_state->setValues($values);

                // Mettre à jour l'entité directement pour refléter les changements
                $form_object = $form_state->getFormObject();
                if (method_exists($form_object, 'getEntity')) {
                  $entity = $form_object->getEntity();
                  if ($entity && $entity->hasField($field_name)) {
                    $field_items = $entity->get($field_name);

                    // Recréer le champ avec les valeurs mises à jour
                    $field_items->setValue([]);

                    \Drupal::logger('media_attributes_manager')->debug('Resetting field @field values directly on entity', [
                      '@field' => $field_name
                    ]);

                    // Extraire les IDs des médias restants depuis les valeurs user input
                    $remaining_ids = [];

                    // Récupérer depuis target_id si présent
                    if (isset($user_input[$field_name]['target_id']) && !empty($user_input[$field_name]['target_id'])) {
                      $target_ids = explode(' ', $user_input[$field_name]['target_id']);
                      foreach ($target_ids as $target_id) {
                        $clean_id = preg_replace('/^media:/', '', $target_id);
                        if (!empty($clean_id) && is_numeric($clean_id)) {
                          $remaining_ids[] = $clean_id;
                        }
                      }
                    }

                    // Ajouter les médias restants
                    if (!empty($remaining_ids)) {
                      foreach ($remaining_ids as $id) {
                        $field_items->appendItem(['target_id' => $id]);
                      }
                      \Drupal::logger('media_attributes_manager')->debug('Appended @count items to field entity: @ids', [
                        '@count' => count($remaining_ids),
                        '@ids' => implode(',', $remaining_ids)
                      ]);
                    }
                  }
                }
              }

              // Mise à jour de l'entrée utilisateur
              $user_input = $form_state->getUserInput();

              // Mise à jour de target_id dans le format de chaîne avec espaces
              if (isset($user_input[$field_name]['target_id'])) {
                $target_ids = explode(' ', $user_input[$field_name]['target_id']);
                $filtered_ids = [];
                foreach ($target_ids as $target_id) {
                  $id_value = preg_replace('/^media:/', '', $target_id);
                  if ($id_value != $media_id) {
                    $filtered_ids[] = $target_id;
                  }
                }
                $user_input[$field_name]['target_id'] = implode(' ', $filtered_ids);
                \Drupal::logger('media_attributes_manager')->debug('Updated target_id field: @value', [
                  '@value' => $user_input[$field_name]['target_id']
                ]);
              }

              // Mise à jour dans current/target_id si présent
              if (isset($user_input[$field_name]['current']['target_id'])) {
                $target_ids = explode(' ', $user_input[$field_name]['current']['target_id']);
                $filtered_ids = [];
                foreach ($target_ids as $target_id) {
                  $id_value = preg_replace('/^media:/', '', $target_id);
                  if ($id_value != $media_id) {
                    $filtered_ids[] = $target_id;
                  }
                }
                $user_input[$field_name]['current']['target_id'] = implode(' ', $filtered_ids);
                \Drupal::logger('media_attributes_manager')->debug('Updated current/target_id field: @value', [
                  '@value' => $user_input[$field_name]['current']['target_id']
                ]);
              }

              // Essayer également le format traditionnel avec index numérique
              foreach ($user_input[$field_name] as $delta => $value) {
                if (is_numeric($delta) && isset($value['target_id']) && $value['target_id'] == $media_id) {
                  unset($user_input[$field_name][$delta]);
                  \Drupal::logger('media_attributes_manager')->debug('Removed indexed item @delta', ['@delta' => $delta]);
                }
              }

              // Mettre à jour l'entrée utilisateur
              $form_state->setUserInput($user_input);

              // Recherche de l'ID de wrapper le plus approprié
              $wrapper_id = '';

              // Le wrapper du champ pour la mise à jour AJAX peut être dans différents formats
              if (isset($triggering_element['#ajax']['wrapper'])) {
                $wrapper_id = $triggering_element['#ajax']['wrapper'];
              }

              // Si on n'a pas de wrapper mais qu'on a le nom du champ
              if (empty($wrapper_id) && !empty($field_name)) {
                // Essayer différentes conventions de nommage pour le wrapper
                $potential_wrappers = [
                  'edit-' . str_replace('_', '-', $field_name) . '-wrapper',
                  str_replace('_', '-', $field_name) . '--widget-wrapper',
                  'edit-' . str_replace('_', '-', $field_name),
                ];

                foreach ($potential_wrappers as $potential_id) {
                  // On ne peut pas vérifier l'existence du wrapper ici,
                  // mais on peut utiliser le premier format qui correspond aux conventions
                  $wrapper_id = $potential_id;
                  break;
                }
              }

              if (!empty($wrapper_id)) {
                \Drupal::logger('media_attributes_manager')->debug('Using wrapper ID for AJAX update: @wrapper', [
                  '@wrapper' => $wrapper_id
                ]);

                // Stocker le wrapper dans le storage du formulaire pour être utilisé par updateWidgetCallback
                $storage = $form_state->getStorage();
                $storage['ajax_update_wrapper'] = $wrapper_id;
                $storage['media_removed'] = $media_id;
                $storage['field_name'] = $field_name;
                $form_state->setStorage($storage);
              }

              // Assurez-vous que le déclencheur contient les bonnes propriétés pour l'AJAX
              if ($triggering_element) {
                $ajax_settings = [
                  'callback' => [static::class, 'updateWidgetCallback'],
                  'wrapper' => $wrapper_id,
                  'effect' => 'fade',
                  'progress' => ['type' => 'throbber', 'message' => new TranslatableMarkup('Updating media list...')],
                ];

                if (empty($triggering_element['#ajax'])) {
                  $triggering_element['#ajax'] = $ajax_settings;
                } else {
                  foreach ($ajax_settings as $key => $value) {
                    $triggering_element['#ajax'][$key] = $value;
                  }
                }
              }

              // Forcer le rebuild complet du formulaire pour AJAX
              $form_state->setRebuild(TRUE);

              // Essayer de réindexer les éléments restants dans les tableaux pour éviter
              // des trous dans les indices qui peuvent causer des problèmes d'affichage
              if (method_exists($form_state, 'cleanValues')) {
                // Cette méthode est interne à Drupal mais peut aider à nettoyer les valeurs
                $form_state->cleanValues();
              }

              // Définir un message explicite mais plus discret
              \Drupal::messenger()->addStatus(new TranslatableMarkup('Media item removed.'));

              // Invalider les caches essentiels pour forcer le rafraîchissement du DOM
              \Drupal::service('cache_tags.invalidator')->invalidateTags([
                'media:' . $media_id,
                'rendered',
                'form:' . $form['#form_id']
              ]);

              // Log un message final pour confirmer que la suppression est terminée
              \Drupal::logger('media_attributes_manager')->debug('Media removal complete. ID: @id', [
                '@id' => $media_id
              ]);

              return;
            }
          }
        }

        // Approche standard avec la classe parente
        // mais indique au formulaire qu'il doit être reconstruit
        $form_state->setRebuild(TRUE);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_attributes_manager')->error('Error in removeItemSubmit: @error', ['@error' => $e->getMessage()]);
      \Drupal::messenger()->addMessage(new TranslatableMarkup('An error occurred while removing the media. Please try saving the form.'));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Fonction utilitaire pour trouver les valeurs target_id dans un tableau.
   */
  protected static function findTargetIdInArray($array, $path = []) {
    if (!is_array($array)) {
      return NULL;
    }

    if (isset($array['target_id']) && is_string($array['target_id']) && !empty($array['target_id'])) {
      \Drupal::logger('media_attributes_manager')->debug('Found target_id at path @path: @value', [
        '@path' => implode('->', $path),
        '@value' => $array['target_id'],
      ]);
      return $array['target_id'];
    }

    foreach ($array as $key => $value) {
      if (is_array($value)) {
        $result = static::findTargetIdInArray($value, array_merge($path, [$key]));
        if ($result) {
          return $result;
        }
      }
    }

    return NULL;
  }

  /**
   * Processe les boutons d'édition et de suppression en masse.
   */
  public static function processBulkButtons(&$element, FormStateInterface $form_state, &$complete_form) {
    // Cas classique avec 'actions'
    if (isset($element['actions'])) {
      $element['actions']['bulk_buttons_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bulk-buttons-wrapper']],
        'bulk_edit' => [
          '#type' => 'button',
          '#value' => new TranslatableMarkup('Bulk Edit'),
          '#attributes' => [
            'class' => ['bulk-edit-button'],
            'type' => 'button',
            'data-bulk-edit' => 'true', // Attribut pour identifier le bouton
          ],
        ],
        'bulk_remove' => [
          '#type' => 'button',
          '#value' => new TranslatableMarkup('Bulk Remove'),
          '#attributes' => [
            'class' => ['bulk-remove-button'],
            'type' => 'button',
          ],
          '#submit' => [[static::class, 'bulkRemoveSelected']],
          '#limit_validation_errors' => [],
        ],
      ];
    }
    // Cas où les boutons sont au même niveau que open_modal
    elseif (isset($element['entity_browser']['open_modal'])) {
      $element['entity_browser']['bulk_buttons_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bulk-buttons-wrapper']],
        'bulk_edit' => [
          '#type' => 'button',
          '#value' => new TranslatableMarkup('Bulk Edit'),
          '#attributes' => [
            'class' => ['bulk-edit-button'],
            'type' => 'button',
          ],
        ],
        'bulk_remove' => [
          '#type' => 'button',
          '#value' => new TranslatableMarkup('Bulk Remove'),
          '#attributes' => [
            'class' => ['bulk-remove-button'],
            'type' => 'button',
          ],
          '#submit' => ['::bulkRemoveSelected'],
          '#limit_validation_errors' => [],
        ],
      ];
    }

    return $element;
  }

  /**
   * Méthode de rappel AJAX pour la mise à jour du widget.
   */
  public static function updateWidgetCallback(array &$form, FormStateInterface $form_state) {
    // Récupère l'élément déclencheur
    $triggering_element = $form_state->getTriggeringElement();

    // Récupère l'ID du média qui a été supprimé
    $removed_media_id = NULL;
    $storage = $form_state->getStorage();
    if (isset($storage['media_removed'])) {
      $removed_media_id = $storage['media_removed'];
      \Drupal::logger('media_attributes_manager')->debug('Located removed media ID in storage: @id', [
        '@id' => $removed_media_id
      ]);
    }

    // Si on n'a pas trouvé l'ID dans le storage, essayer de l'extraire du triggering_element
    if (!$removed_media_id && !empty($triggering_element['#attributes']['data-entity-id'])) {
      $id = $triggering_element['#attributes']['data-entity-id'];
      $removed_media_id = preg_replace('/^media:/', '', $id);
      \Drupal::logger('media_attributes_manager')->debug('Extracted removed media ID from trigger: @id', [
        '@id' => $removed_media_id
      ]);
    }

    // Find the field name from the trigger's parents
    $field_name = '';
    if (isset($triggering_element['#array_parents'])) {
      foreach ($triggering_element['#array_parents'] as $parent) {
        if (strpos($parent, 'field_') === 0) {
          $field_name = $parent;
          break;
        }
      }
    }

    // Si on n'a pas trouvé le field_name dans les parents, essayer de l'obtenir du storage
    if (empty($field_name) && isset($storage['field_name'])) {
      $field_name = $storage['field_name'];
      \Drupal::logger('media_attributes_manager')->debug('Using field name from storage: @name', [
        '@name' => $field_name
      ]);
    }

    // Fonction pour filtrer récursivement un élément de formulaire pour supprimer un média
    $filter_removed_media = NULL; // Initialize the variable before using it in the closure
    $filter_removed_media = function (&$element) use ($removed_media_id, &$filter_removed_media) {
      if (!$removed_media_id || !is_array($element)) {
        return;
      }

      // Si c'est un conteneur d'items de médias
      if (isset($element['items']) && is_array($element['items'])) {
        foreach ($element['items'] as $key => &$item) {
          // Vérifier si cet item correspond au média supprimé
          if (isset($item['#attributes']['data-entity-id']) &&
              $item['#attributes']['data-entity-id'] === 'media:' . $removed_media_id) {
            unset($element['items'][$key]);
            \Drupal::logger('media_attributes_manager')->debug('Removed media item from items array: @id', [
              '@id' => $removed_media_id
            ]);
          }
        }
        // Réindexer le tableau après suppression
        if (is_array($element['items'])) {
          $element['items'] = array_values($element['items']);
        }
      }

      // Vérifier et mettre à jour les chaînes target_id pour retirer le média supprimé
      if (isset($element['target_id']) && isset($element['target_id']['#value'])) {
        $target_ids = explode(' ', $element['target_id']['#value']);
        $filtered_ids = [];
        foreach ($target_ids as $tid) {
          if ($tid !== 'media:' . $removed_media_id) {
            $filtered_ids[] = $tid;
          }
        }
        $element['target_id']['#value'] = implode(' ', $filtered_ids);
        if (isset($element['target_id']['#attributes']['data-entity-ids'])) {
          $element['target_id']['#attributes']['data-entity-ids'] = implode(' ', $filtered_ids);
        }
        \Drupal::logger('media_attributes_manager')->debug('Filtered target_id field: @ids', [
          '@ids' => implode(' ', $filtered_ids)
        ]);
      }

      // Instead of using Element::children() which requires proper render arrays,
      // manually iterate through the element to find child elements
      foreach ($element as $key => $child) {
        // Skip property keys (those starting with #) and non-array values
        if ($key[0] !== '#') {
          if (is_array($child)) {
            $filter_removed_media($element[$key]);
          } else {
            // If it's not an array but also not a property (doesn't start with #),
            // it's likely an issue for Element::children(), so let's make it safe
            // by ensuring it's handled as a property
            $element['#' . $key] = $child;
            unset($element[$key]);
          }
        }
      }
    };

    if (!empty($field_name)) {
      // Recherche des différents wrappers possibles du champ
      $possible_wrappers = [
        // Format standard pour les wrappers de champ
        $field_name . '_wrapper',
        // Format details
        $field_name . '--wrapper',
        // Format groupe de champs
        'group-' . str_replace('_', '-', $field_name),
      ];

      foreach ($possible_wrappers as $wrapper_key) {
        if (isset($form[$wrapper_key])) {
          \Drupal::logger('media_attributes_manager')->debug('Found outer field wrapper: @wrapper', [
            '@wrapper' => $wrapper_key
          ]);

          // Appliquer la fonction de filtrage pour supprimer le média du rendu
          $filter_removed_media($form[$wrapper_key]);

          return $form[$wrapper_key];
        }
      }

      // Si on n'a pas trouvé de wrapper spécifique, essayer le conteneur de champ standard
      if (isset($form[$field_name])) {
        $field_container = $form[$field_name];

        // Appliquer la fonction de filtrage pour supprimer le média du rendu
        $filter_removed_media($field_container);

        \Drupal::logger('media_attributes_manager')->debug('Returning standard field container for AJAX: @field', [
          '@field' => $field_name
        ]);

        return $field_container;
      }
    }

    // Recherche par ID directement dans le formulaire
    if (!empty($field_name)) {
      $wrapper_id = 'edit-' . str_replace('_', '-', $field_name) . '-wrapper';

      foreach (Element::children($form) as $key) {
        if (isset($form[$key]['#id']) && $form[$key]['#id'] == $wrapper_id) {
          // Appliquer la fonction de filtrage pour supprimer le média du rendu
          $filter_removed_media($form[$key]);

          \Drupal::logger('media_attributes_manager')->debug('Found wrapper by ID: @id', ['@id' => $wrapper_id]);
          return $form[$key];
        }
      }
    }

    // Original logic if we can't find the field container
    $parents = [];
    if ($triggering_element['#type'] == 'submit' && strpos($triggering_element['#name'], '_remove_')) {
      // L'utilisateur a cliqué sur un bouton de suppression
      $parents = array_slice($triggering_element['#array_parents'], 0, -static::$deleteDepth);

      \Drupal::logger('media_attributes_manager')->debug('Remove button clicked. Parents: @parents', [
        '@parents' => json_encode($parents)
      ]);

      // Pour un bouton de suppression, essayez de remonter plus haut dans la hiérarchie
      // pour obtenir le conteneur complet plutôt que juste le widget
      if (!empty($parents) && count($parents) >= 2) {
        // Remontez d'un niveau supplémentaire
        $container_parents = array_slice($parents, 0, -1);
        $container = NestedArray::getValue($form, $container_parents);
        if ($container) {
          // Appliquer la fonction de filtrage pour supprimer le média du rendu
          $filter_removed_media($container);

          \Drupal::logger('media_attributes_manager')->debug('Using parent container for removal');
          return $container;
        }
      }
    }
    elseif (!empty($triggering_element['#ajax']['event']) && $triggering_element['#ajax']['event'] == 'entity_browser_value_updated') {
      $parents = array_slice($triggering_element['#array_parents'], 0, -1);
    }
    elseif ($triggering_element['#type'] == 'submit' && strpos($triggering_element['#name'], '_replace_')) {
      $parents = array_slice($triggering_element['#array_parents'], 0, -static::$deleteDepth);
    }
    else {
      // Cas générique, utiliser la méthode de détermination des parents précédente
      $parents = array_slice($triggering_element['#array_parents'], 0, -static::$deleteDepth);
    }

    // Try to find the widget from parents
    if (!empty($parents)) {
      $widget_element = NestedArray::getValue($form, $parents);
      if ($widget_element) {
        // Appliquer la fonction de filtrage pour supprimer le média du rendu
        $filter_removed_media($widget_element);

        // Log for debugging
        \Drupal::logger('media_attributes_manager')->debug('Widget found and updated via parents');
        return $widget_element;
      }
    }

    // Fallback to parent method as a last resort
    $element = parent::updateWidgetCallback($form, $form_state);

    // Appliquer la fonction de filtrage pour supprimer le média du rendu
    if ($element) {
      $filter_removed_media($element);
    }

    \Drupal::logger('media_attributes_manager')->debug('Fallback to parent updateWidgetCallback');
    return $element;
  }

}
