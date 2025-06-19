(function($, Drupal, once) {
  'use strict';

  /**
   * Gestionnaire pour le bouton Bulk Edit
   */
  Drupal.behaviors.bulkEditHandler = {
    attach: function(context, settings) {
      // Attacher l'event listener au bouton bulk edit
      once('bulk-edit-handler', '[data-bulk-edit="true"]', context).forEach(function(button) {
        $(button).on('click', function(e) {
        e.preventDefault();
        
        // Récupérer les médias sélectionnés
        var selectedMediaIds = [];
        var $checkboxes = $('.media-item-select-checkbox:checked');
        
        $checkboxes.each(function() {
          // Récupérer l'ID du média depuis les données ou le contexte
          var $mediaItem = $(this).closest('.item-container');
          var entityId = $mediaItem.data('entity-id');
          
          if (entityId && entityId.startsWith('media:')) {
            var mediaId = entityId.split(':')[1];
            if (mediaId) {
              selectedMediaIds.push(mediaId);
            }
          } else {
            // Fallback: essayer de l'extraire depuis les boutons
            var $buttons = $mediaItem.find('[name*="edit_button"], [name*="remove_button"]');
            if ($buttons.length > 0) {
              var buttonName = $buttons.first().attr('name');
              var matches = buttonName.match(/(\d+)_\d+_[a-f0-9]+$/);
              if (matches) {
                selectedMediaIds.push(matches[1]);
              }
            }
          }
        });
        
        if (selectedMediaIds.length === 0) {
          alert('Aucun média sélectionné pour l\'édition en masse.');
          return;
        }
        
        // Préparer les données des médias
        var mediaData = selectedMediaIds.map(function(id) {
          return {
            id: id,
            type: 'media'
          };
        });
        
        // Créer un élément factice pour l'initialisation AJAX de Drupal
        var $dummyElement = $("<div></div>");

        // Configuration AJAX spécifique à Drupal
        var ajaxSettings = {
          url: Drupal.url("media-attributes/bulk-edit-modal"),
          base: false,
          element: $dummyElement.get(0),
          submit: {
            js: true,
            mediaData: JSON.stringify(mediaData),
            ajax_form: 1,
          },
          dialogType: "modal",
          dialog: {
            width: "80%",
            dialogClass: "bulk-edit-modal",
          },
        };

        // Créer et exécuter la requête AJAX via le système Drupal
        var myAjax = Drupal.ajax(ajaxSettings);
        myAjax.execute();
      });
      });
    }
  };

})(jQuery, Drupal, once);
