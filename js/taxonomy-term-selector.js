/**
 * @file
 * Comportement JavaScript pour gérer les sélecteurs de termes taxonomiques.
 * Ce script synchronise les sélecteurs de taxonomie avec les champs d'autocomplétion.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Comportement pour les sélecteurs de termes taxonomiques.
   */
  Drupal.behaviors.taxonomyTermSelector = {
    attach: function (context, settings) {
      // Sélectionner tous les sélecteurs de taxonomie une seule fois
      once('taxonomy-term-selector', '.taxonomy-term-selector', context).forEach(function (selector) {
        const $selector = $(selector);
        const targetFieldName = $selector.data('target-field');
        const $targetField = $(`[name="${targetFieldName}"]`);
        
        // Lorsqu'un terme est sélectionné dans la liste déroulante
        $selector.on('change', function () {
          const termId = $selector.val();
          const targetIdFieldName = $selector.data('target-id-field');
          const $targetIdField = $(`[name="${targetIdFieldName}"]`);
          
          // Si une valeur a été sélectionnée
          if (termId) {
            // Récupérer le label du terme sélectionné
            const termLabel = $selector.find('option:selected').text();
            
            // Mettre à jour le champ d'ID caché avec l'ID du terme
            if ($targetIdField.length) {
              $targetIdField.val(termId);
            }
            
            // Mettre à jour le champ d'autocomplétion
            if ($targetField.length) {
              // Pour les champs entity_autocomplete de Drupal
              if ($targetField.hasClass('form-autocomplete')) {
                // Ajuster la valeur du champ autocomplete avec le format attendu par Drupal
                // Format: "Term label (id)"
                const autocompleteValue = `${termLabel} (${termId})`;
                $targetField.val(autocompleteValue);
                $targetField.attr('data-term-id', termId);
                
                // Déclencher les événements pour s'assurer que Drupal reconnaît la valeur
                $targetField.trigger('autocompleteSelect');
                $targetField.trigger('change');
              } else {
                // Fallback pour d'autres types de champs
                $targetField.val(termId);
                $targetField.trigger('change');
              }
            }
          } else {
            // Si aucune valeur n'est sélectionnée, vider les champs
            $targetField.val('');
            $targetField.attr('data-term-id', '');
            if ($targetIdField.length) {
              $targetIdField.val('');
            }
            $targetField.trigger('change');
          }
        });
      });
    }
  };

  /**
   * Comportement pour synchroniser les champs d'autocomplétion de taxonomie avec leurs champs cachés
   * Ceci est particulièrement utile quand les valeurs sont chargées par AJAX ou initialement par le serveur
   */
  Drupal.behaviors.taxonomyAutocompleteSync = {
    attach: function (context, settings) {
      // Fonction pour extraire l'ID de terme d'une valeur d'autocomplétion
      function extractTermId(value) {
        if (!value) return null;
        
        // Format standard: "Label (123)"
        const matches = value.match(/\(([0-9]+)\)$/);
        if (matches && matches[1]) {
          return matches[1];
        }
        
        // Formats alternatifs
        if (/^[0-9]+$/.test(value.trim())) {
          return value.trim(); // ID numérique seul
        }
        
        // Format: "Label [123]"
        const bracketMatches = value.match(/\[([0-9]+)\]$/);
        if (bracketMatches && bracketMatches[1]) {
          return bracketMatches[1];
        }
        
        return null;
      }
      
      // Sélectionner tous les champs d'autocomplétion de taxonomie avec les nouveaux attributs
      once('taxonomy-autocomplete-sync', '.mam-taxonomy-field, [data-mam-taxonomy-field="true"], .form-autocomplete[data-taxonomy-field="true"], .form-autocomplete[data-hidden-id-field]', context).forEach(function (field) {
        const $field = $(field);
        const fieldValue = $field.val();
        const hiddenIdField = $field.attr('data-hidden-id-field');
        
        // Ajouter les nouveaux attributs s'ils n'existent pas encore
        if (!$field.attr('data-mam-taxonomy-field')) {
          $field.attr('data-mam-taxonomy-field', 'true');
        }
        if (!$field.hasClass('mam-taxonomy-field')) {
          $field.addClass('mam-taxonomy-field');
        }
        
        // Si le champ a une valeur mais que le champ caché n'est pas mis à jour
        if (fieldValue && hiddenIdField) {
          const $hiddenField = $(`[name="${hiddenIdField}"]`);
          const termId = extractTermId(fieldValue);
          
          console.log(`%c[MAM TAXONOMY SYNC] %cSynchronisation du champ ${$field.attr('name')}`, 
                     'background: #3498db; color: white; padding: 2px 5px; border-radius: 3px;', 
                     'color: #2980b9; font-weight: bold;');
          console.log(`  - Valeur: "${fieldValue}"`);
          console.log(`  - Champ caché: ${hiddenIdField}`);
          console.log(`  - ID extrait: ${termId || 'AUCUN'}`);
          
          if (termId && $hiddenField.length) {
            $hiddenField.val(termId);
            $field.attr('data-term-id', termId);
            console.log(`  - ✅ Synchronisation réussie`);
            
            // Envoyer un événement personnalisé pour notifier de la synchronisation
            $(document).trigger('mam:taxonomy:synced', [{
              field: $field.attr('name'),
              hiddenField: hiddenIdField,
              termId: termId
            }]);
          } else {
            console.log(`  - ❌ Échec de la synchronisation`);
          }
        }
        
        // Ajouter un écouteur pour les événements 'autocompleteselect' spécifiques à Drupal
        $field.on('autocompleteselect', function(event, ui) {
          if (ui && ui.item && ui.item.value) {
            const termId = extractTermId(ui.item.value);
            if (termId && hiddenIdField) {
              const $hiddenField = $(`[name="${hiddenIdField}"]`);
              if ($hiddenField.length) {
                $hiddenField.val(termId);
                $field.attr('data-term-id', termId);
                console.log(`Valeur mise à jour lors de la sélection autocomplete: ${termId}`);
              }
            }
          }
        });
      });
    }
  };

  /**
   * Comportement pour déclencher manuellement la synchronisation de tous les champs de taxonomie
   * à l'initialisation de la page et après des opérations AJAX
   */
  Drupal.behaviors.taxonomyForceSyncAll = {
    attach: function(context, settings) {
      // Ne s'exécute qu'une seule fois sur le document
      once('taxonomy-force-sync', 'body', context).forEach(function(body) {
        console.log(`%c[MAM TAXONOMY INIT] %cInitialisation globale des champs de taxonomie`, 
                   'background: #16a085; color: white; padding: 2px 5px; border-radius: 3px;', 
                   'color: #1abc9c; font-weight: bold;');
        
        // Déclencher immédiatement une synchronisation complète
        forceFullSync();
        
        // Attendre que tout le DOM soit chargé
        $(document).ready(function() {
          // Attendre une seconde supplémentaire pour s'assurer que tous les widgets sont chargés
          setTimeout(forceFullSync, 1000);
        });
        
        // S'abonner aux événements AJAX de Drupal
        $(document).on('ajaxComplete', function(event, xhr, settings) {
          // Attendre un petit moment pour que le DOM soit mis à jour
          setTimeout(forceFullSync, 500);
        });
      });
      
      // Fonction pour forcer la synchronisation de tous les champs de taxonomie
      function forceFullSync() {
        const $allTaxonomyFields = $('.mam-taxonomy-field, [data-mam-taxonomy-field="true"], .form-autocomplete[data-taxonomy-field="true"], .form-autocomplete[data-hidden-id-field]');
        
        console.log(`%c[MAM TAXONOMY FORCE SYNC] %cSynchronisation forcée de ${$allTaxonomyFields.length} champs de taxonomie`, 
                   'background: #f1c40f; color: white; padding: 2px 5px; border-radius: 3px;', 
                   'color: #f39c12; font-weight: bold;');
        
        $allTaxonomyFields.each(function() {
          const $field = $(this);
          const fieldName = $field.attr('name');
          const fieldValue = $field.val();
          const hiddenIdField = $field.attr('data-hidden-id-field');
          
          if (fieldValue && hiddenIdField) {
            // Extraire l'ID du terme
            const matches = fieldValue.match(/\(([0-9]+)\)$/);
            if (matches && matches[1]) {
              const termId = matches[1];
              const $hiddenField = $(`[name="${hiddenIdField}"]`);
              
              if ($hiddenField.length) {
                $hiddenField.val(termId);
                $field.attr('data-term-id', termId);
                console.log(`  - Champ ${fieldName}: ID synchronisé à ${termId}`);
              }
            } else {
              console.log(`  - Champ ${fieldName}: impossible d'extraire l'ID de "${fieldValue}"`);
            }
          }
        });
        
        // Déclencher un événement pour signaler que la synchronisation est terminée
        $(document).trigger('mam:taxonomy:allSynced');
      }
    }
  };

})(jQuery, Drupal, once);
