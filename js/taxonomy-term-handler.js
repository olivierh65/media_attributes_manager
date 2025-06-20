/**
 * @file
 * Comportement JavaScript pour intercepter les données brutes d'autocomplétion des termes de taxonomie.
 * Cette approche permet d'extraire directement les IDs des termes à partir des données AJAX.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Behavior pour gérer les champs d'autocomplétion de taxonomie.
   */
  Drupal.behaviors.taxonomyTermHandler = {
    attach: function (context, settings) {
      // Cibler tous les champs d'autocomplétion d'entité (taxonomie)
      once('taxonomy-term-handler', 'input.form-autocomplete[data-autocomplete-path]', context).forEach(function(input) {
        var $input = $(input);
        
        // Vérifier si c'est bien un champ taxonomie
        if ($input.attr('data-autocomplete-path').indexOf('/taxonomy_term/') > -1) {
          console.log('[TAXONOMY HANDLER] Initialisation du champ ' + $input.attr('name'));
          
          // Créer ou récupérer le champ caché pour stocker l'ID
          var hiddenFieldName = $input.attr('name') + '_tid';
          var $hiddenField = $('input[name="' + hiddenFieldName + '"]');
          
          if ($hiddenField.length === 0) {
            $hiddenField = $('<input>', {
              type: 'hidden',
              name: hiddenFieldName,
              class: 'taxonomy-term-id mam-taxonomy-term-id',
              'data-for-field': $input.attr('name'),
              'data-mam-taxonomy-hidden-field': 'true',
              'data-bundle': $input.attr('data-bundle') || '',
              'data-field-name': hiddenFieldName
            });
            $input.after($hiddenField);
            console.log('[TAXONOMY HANDLER] Champ caché créé: ' + hiddenFieldName);
          }
          
          // Ajouter des attributs pour identifier ce champ comme un champ de taxonomie
          $input.attr('data-mam-taxonomy-field', 'true');
          $input.addClass('mam-taxonomy-field');
          $input.attr('data-hidden-id-field', hiddenFieldName);

          // Intercepter l'événement d'autocomplétion avant qu'il ne modifie l'UI
          $input.off('autocompleteresponse.mam-taxonomy').on('autocompleteresponse.mam-taxonomy', function (event, ui) {
            console.log('[TAXONOMY HANDLER] Réponse autocomplétion reçue:', ui);
            
            // Si nous avons des résultats
            if (ui && ui.content && ui.content.length > 0) {
              // Parcourir tous les éléments pour extraire les IDs et les stocker pour utilisation future
              ui.content.forEach(function(item) {
                // Stocker l'ID original dans les données de l'élément pour qu'il soit disponible lors de la sélection
                var termId = extractTermIdFromItem(item);
                if (termId) {
                  // Stocker l'ID dans l'élément pour qu'il soit accessible lors de la sélection
                  item.originalTermId = termId;
                  console.log('[TAXONOMY HANDLER] ID stocké pour suggestion: "' + item.label + '" -> ' + termId);
                }
              });
            }
          });
          
          // Intercepter la sélection d'un élément d'autocomplétion
          $input.off('autocompleteselect.mam-taxonomy').on('autocompleteselect.mam-taxonomy', function (event, ui) {
            if (ui && ui.item) {
              console.log('[TAXONOMY HANDLER] Terme sélectionné:', ui.item);
              
              // Récupérer l'ID du terme que nous avons stocké pendant l'événement response
              var termId = ui.item.originalTermId || extractTermIdFromItem(ui.item);
              
              if (termId) {
                console.log('[TAXONOMY HANDLER] ID de terme sélectionné: ' + termId + ' pour ' + $input.attr('name'));
                $hiddenField.val(termId);
                $input.attr('data-term-id', termId);
                
                // Émettre un événement personnalisé pour informer les autres scripts
                $(document).trigger('mam:taxonomy:selected', [{
                  field: $input.attr('name'),
                  value: ui.item.value,
                  termId: termId
                }]);
                
                // Force un événement change pour s'assurer que les autres gestionnaires sont notifiés
                setTimeout(function() {
                  $input.trigger('change');
                }, 0);
              }
            }
          });
          
          // Fonction pour extraire l'ID d'un élément d'autocomplétion
          function extractTermIdFromItem(item) {
            var termId = null;
            
            // Chercher l'ID dans les différentes propriétés possibles
            if (item.hasOwnProperty('tid')) {
              termId = item.tid; // Format standard
            } else if (item.hasOwnProperty('id')) {
              termId = item.id; // Format alternatif
            } else if (item.hasOwnProperty('target_id')) {
              termId = item.target_id; // Autre format possible
            } else if (item.hasOwnProperty('entity') && item.entity && item.entity.id) {
              termId = item.entity.id; // Format avec objet entity
            }
            
            // Fallback: essayer d'extraire l'ID du format "Label (123)"
            if (!termId && item.value) {
              var matches = item.value.match(/\(([0-9]+)\)$/);
              if (matches && matches[1]) {
                termId = matches[1];
              }
            }
            
            return termId;
          }
        }
      });

      // Configurer un intercepteur AJAX global pour capturer les données brutes
      once('taxonomy-ajax-interceptor', 'body', context).forEach(function(body) {
        $(document).ajaxComplete(function(event, xhr, settings) {
          // Vérifier si c'est une requête d'autocomplétion de taxonomie
          if (settings.url && settings.url.indexOf('/taxonomy_term/') > -1) {
            console.log('[TAXONOMY HANDLER] Requête AJAX interceptée:', settings.url);
            
            try {
              // Les données sont généralement dans responseText au format JSON
              if (xhr.responseText) {
                var data = JSON.parse(xhr.responseText);
                console.log('[TAXONOMY HANDLER] Données brutes reçues:', data);
                
                // Les données d'autocomplétion de Drupal ont une structure spécifique
                // Chaque élément contient des informations comme l'étiquette et la valeur
                if (Array.isArray(data)) {
                  data.forEach(function(item) {
                    console.log('[TAXONOMY HANDLER] Élément brut:', item);
                    
                    // Chercher des informations d'ID dans les données
                    if (item.hasOwnProperty('entity')) {
                      console.log('[TAXONOMY HANDLER] Propriétés de l\'entité:', item.entity);
                    }
                  });
                }
              }
            } catch (e) {
              console.error('[TAXONOMY HANDLER] Erreur lors de l\'analyse des données AJAX:', e);
            }
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
