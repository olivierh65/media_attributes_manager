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

/**
 * Plugin implementation of the 'media_attributes_widget' widget.
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

  public static function processBulkButtons(&$element, FormStateInterface $form_state, &$complete_form) {
    // Cas classique avec 'actions'
    if (isset($element['actions'])) {
      $element['actions']['bulk_buttons_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bulk-buttons-wrapper']],
        'bulk_edit' => [
          '#type' => 'button',
          '#value' => \Drupal::translation()->translate('Bulk Edit'),
          '#attributes' => [
            'class' => ['bulk-edit-button'],
            'type' => 'button',
            'data-bulk-edit' => 'true', // Attribut pour identifier le bouton
          ],
        ],
        'bulk_remove' => [
          '#type' => 'button',
          '#value' => \Drupal::translation()->translate('Bulk Remove'),
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
          '#value' => \Drupal::translation()->translate('Bulk Edit'),
          '#attributes' => [
            'class' => ['bulk-edit-button'],
            'type' => 'button',
          ],
        ],
        'bulk_remove' => [
          '#type' => 'button',
          '#value' => \Drupal::translation()->translate('Bulk Remove'),
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

  public static function updateWidgetCallback(array &$form, FormStateInterface $form_state) {
    // Appelle la méthode parente qui gère la logique AJAX standard
    $element = parent::updateWidgetCallback($form, $form_state);

    // Ici on peut ajouter notre logique spécifique si nécessaire
    // Pour l'instant, on retourne simplement l'élément de la méthode parente
    return $element;
  }

}
