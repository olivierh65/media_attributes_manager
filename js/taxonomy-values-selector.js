/**
 * @file
 * JavaScript pour le sélecteur de valeurs de taxonomie dans le formulaire d'édition en masse.
 *
 * Ce script permet de :
 * 1. Récupérer toutes les valeurs de taxonomie disponibles pour les médias sélectionnés via AJAX
 * 2. Remplir le sélecteur avec ces valeurs
 * 3. Gérer l'interaction entre le sélecteur et le champ d'autocomplétion
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Comportement pour le sélecteur de valeurs de taxonomie.
   */
  Drupal.behaviors.taxonomyValuesSelector = {
    attach: function (context, settings) {
      once('taxonomy-values-selector', '.taxonomy-values-selector', context).forEach(function (selector) {
        const $selector = $(selector);
        const targetFieldName = $selector.data('for-field');
        const $targetField = $selector.closest('.taxonomy-field-container').find(`[data-field-name="${targetFieldName}"]`);
        const $tidField = $selector.closest('.taxonomy-field-container').find(`[data-for-field="${targetFieldName}"]`);
        const $loadingIndicator = $('<span class="taxonomy-selector-loading">').text(Drupal.t('Loading...')).css({
          marginLeft: '0.5em',
          fontSize: '0.85em',
          fontStyle: 'italic',
          opacity: 0.7
        });

        // Récupérer les valeurs de taxonomie des médias sélectionnés via AJAX
        const fetchTaxonomyValues = function() {
          // Ajouter un indicateur de chargement
          $selector.after($loadingIndicator);
          
          // Récupérer les IDs des médias
          const mediaIds = $('input[name="media_ids"]').val();
          
          if (!mediaIds) {
            console.warn('No media IDs found for taxonomy values');
            $loadingIndicator.remove();
            return;
          }
          
          // Faire la requête AJAX
          $.ajax({
            url: Drupal.url('media-attributes/taxonomy-values'),
            type: 'GET',
            data: {
              media_ids: mediaIds,
              field_name: targetFieldName
            },
            dataType: 'json',
            success: function(response) {
              if (response.values && Array.isArray(response.values)) {
                // Remplir le sélecteur avec les valeurs reçues
                populateSelector(response.values);
              } else {
                console.warn('Invalid response format for taxonomy values', response);
              }
              $loadingIndicator.remove();
            },
            error: function(xhr, status, error) {
              console.error('Failed to fetch taxonomy values:', status, error);
              $loadingIndicator.text(Drupal.t('Failed to load values')).css('color', 'red');
              setTimeout(function() {
                $loadingIndicator.remove();
              }, 3000);
            }
          });
        };

        // Remplir le sélecteur avec les valeurs
        const populateSelector = function(taxonomyValues) {
          // Vider le sélecteur
          $selector.find('option').not(':first').remove();
          
          // Si aucune valeur, afficher un message
          if (!taxonomyValues || taxonomyValues.length === 0) {
            const $option = $('<option>', {
              value: '',
              text: Drupal.t('No values available'),
              disabled: true
            });
            $selector.append($option);
            return;
          }
          
          // Ajouter les options
          taxonomyValues.forEach(function(term) {
            const $option = $('<option>', {
              value: term.name,
              text: term.name,
              'data-term-id': term.id
            });
            
            $selector.append($option);
          });
          
          // Sélectionner l'option correspondant à la valeur actuelle du champ
          const currentValue = $targetField.val();
          if (currentValue) {
            $selector.find('option').each(function() {
              if ($(this).val() === currentValue) {
                $(this).prop('selected', true);
                $(this).addClass('taxonomy-option-selected');
              }
            });
          }
        };

        // Événement change sur le sélecteur : mettre à jour le champ de taxonomie
        $selector.on('change', function() {
          const selectedValue = $(this).val();
          const selectedTermId = $(this).find('option:selected').data('term-id');
          
          if (selectedValue) {
            // Mettre à jour le champ d'autocomplétion
            $targetField.val(selectedValue);
            
            // Si un ID est disponible, le mettre dans le champ caché
            if (selectedTermId) {
              $tidField.val(selectedTermId);
            }
            
            // Ajouter une classe au terme sélectionné
            $selector.find('option').removeClass('taxonomy-option-selected');
            $selector.find('option:selected').addClass('taxonomy-option-selected');
            
            // Déclencher un événement change pour que d'autres scripts puissent réagir
            $targetField.trigger('change');
            $targetField.focus();
          }
        });

        // Événement change sur le champ de taxonomie : mettre à jour le sélecteur
        $targetField.on('change', function() {
          const value = $(this).val();
          
          // Si la valeur est vide, ne rien faire
          if (!value) {
            return;
          }
          
          // Mettre à jour le sélecteur
          let found = false;
          $selector.find('option').removeClass('taxonomy-option-selected').each(function() {
            if ($(this).val() === value) {
              $(this).prop('selected', true);
              $(this).addClass('taxonomy-option-selected');
              found = true;
            }
          });
          
          // Si la valeur n'est pas dans le select, ajouter une option
          if (!found && value.trim() !== '') {
            const $option = $('<option>', {
              value: value,
              text: value,
              selected: true,
              class: 'taxonomy-option-selected taxonomy-option-new'
            });
            $selector.append($option);
          }
        });

        // Initialiser le sélecteur - charger les valeurs via AJAX
        fetchTaxonomyValues();
      });
    }
  };

})(jQuery, Drupal, once);
