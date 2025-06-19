(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.mediaAttributesSelection = {
    attach: function (context, settings) {
      const widgets = once('media-attributes-selection', '.media-library-selection', context);

      widgets.forEach(function (widgetElement) {
        const $widget = $(widgetElement);
        // Fonction utilitaire pour toujours récupérer la liste DOM à jour
        function getCheckboxes() {
          return $widget.find('.item-container .media-item-select-checkbox, .media-attributes-item .media-item-select-checkbox');
        }
        let lastChecked = null;

        if (getCheckboxes().length === 0) {
          return; // Pas de checkboxes à gérer dans ce widget/contexte
        }

        $widget.on('click.mediaAttributesSelection', '.media-item-select-checkbox', function (e) {
          const $checkboxes = getCheckboxes();
          const currentCheckbox = this;

          if (!lastChecked) {
            lastChecked = currentCheckbox;
          }

          if (e.shiftKey) {
            const start = $checkboxes.index(currentCheckbox);
            const end = $checkboxes.index(lastChecked);
            const min = Math.min(start, end);
            const max = Math.max(start, end);
            const isChecked = $(currentCheckbox).prop('checked');

            for (let i = min; i <= max; i++) {
              if ($checkboxes[i]) {
                $($checkboxes[i]).prop('checked', isChecked).trigger('change');
              }
            }
          }
          lastChecked = currentCheckbox;

          // Ajoute/enlève une classe sur le conteneur parent de l'item pour un retour visuel
          $(currentCheckbox).closest('.item-container, .media-attributes-item').toggleClass('is-selected', $(currentCheckbox).prop('checked'));
        });

        // S'assurer que l'état visuel est correct au chargement (si des cases sont cochées par défaut)
        getCheckboxes().each(function() {
          $(this).closest('.item-container, .media-attributes-item').toggleClass('is-selected', $(this).prop('checked'));
        });
      });
    }
  };

  // Fonction globale pour resynchroniser l'état visuel des checkboxes et conteneurs
  Drupal.mediaAttributesSyncSelection = function (context) {
    var $widget = $(context).closest('.media-library-selection');
    var $checkboxes = $widget.find('.item-container .media-item-select-checkbox, .media-attributes-item .media-item-select-checkbox');
    $checkboxes.each(function() {
      $(this).closest('.item-container, .media-attributes-item').toggleClass('is-selected', $(this).prop('checked'));
    });
  };

})(jQuery, Drupal, once);
