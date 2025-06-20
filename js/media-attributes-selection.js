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

        // Fonctions pour sélectionner/désélectionner tous les médias
        function selectAllMedia() {
          const $checkboxes = getCheckboxes();
          $checkboxes.prop('checked', true).trigger('change');
          $checkboxes.each(function() {
            $(this).closest('.item-container, .media-attributes-item').addClass('is-selected');
          });
          return false; // Empêche le comportement par défaut
        }

        function deselectAllMedia() {
          const $checkboxes = getCheckboxes();
          $checkboxes.prop('checked', false).trigger('change');
          $checkboxes.each(function() {
            $(this).closest('.item-container, .media-attributes-item').removeClass('is-selected');
          });
          return false; // Empêche le comportement par défaut
        }

        let lastChecked = null;

        if (getCheckboxes().length === 0) {
          return; // Pas de checkboxes à gérer dans ce widget/contexte
        }

        // Gérer le double-clic sur le widget pour sélectionner tous les médias
        $widget.on('dblclick.mediaAttributesSelection', function(e) {
          // Si shift est pressé, désélectionner tous les médias
          if (e.shiftKey) {
            deselectAllMedia();
          } else {
            selectAllMedia();
          }
          // Empêcher la propagation pour éviter les comportements par défaut indésirables
          e.preventDefault();
          e.stopPropagation();
          return false;
        });

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
          const $checkbox = $(this);
          $checkbox.closest('.item-container, .media-attributes-item').toggleClass('is-selected', $checkbox.prop('checked'));

          // Ajouter un tooltip standard via l'attribut title
          $checkbox.attr('title', Drupal.t('Clic: Sélectionner un média\nShift+Clic: Sélection multiple\nDouble-clic sur la zone: Tout sélectionner\nShift+Double-clic sur la zone: Tout désélectionner'));
        });

        // Ajouter un message d'aide discret au dessus de la liste
        const $helpText = $('<div class="media-selection-help-text">' +
          '<strong>' + Drupal.t('Astuce :') + '</strong> ' +
          Drupal.t('Double-cliquez pour sélectionner tous les médias. Survolez les cases à cocher pour plus d\'options.') +
          '</div>');

        $widget.find('.media-library-select-all').first().before($helpText);

        // Ajouter une classe au widget pour indiquer la fonctionnalité de double-clic
        $widget.addClass('has-batch-selection');
      });

      // Ajouter un style global et les fonctionnalités de tooltip avancées
      once('media-selection-styles', 'body', context).forEach(function() {        // Ajouter les styles CSS
        $('<style>')
          .text(`.media-library-selection.has-batch-selection {
            cursor: pointer;
          }
          .media-library-selection.has-batch-selection:hover::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 120, 200, 0.05);
            pointer-events: none;
            z-index: 1;
          }
          .media-selection-help-text {
            background-color: rgba(0, 120, 200, 0.05);
            border-left: 4px solid #0074bd;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 13px;
            border-radius: 0 2px 2px 0;
          }
          `)
          .appendTo('head');
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
