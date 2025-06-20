/**
 * @file
 * Comportement JavaScript pour initialiser et surveiller les champs de taxonomie.
 * Version simplifiée qui fonctionne avec taxonomy-term-handler.js
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Fonction utilitaire pour extraire l'ID d'un terme taxonomique à partir de différents formats
   */
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

  /**
   * Fonction pour initialiser un champ de taxonomie
   */
  function initTaxonomyField($field) {
    const fieldName = $field.attr("name");
    if (!fieldName || fieldName.includes("_tid")) return;

    console.log(`%c[MAM TAXONOMY INIT] %cInitialisation du champ ${fieldName}`, 
                'background: #2ecc71; color: white; padding: 2px 5px; border-radius: 3px;', 
                'color: #27ae60; font-weight: bold;');
    
    // Obtenir le bundle du champ (important pour la soumission)
    const dataBundle = $field.attr('data-bundle') || '';
    
    if (!dataBundle) {
      console.log(`  - ⚠️ ATTENTION: Ce champ n'a pas d'attribut data-bundle, ce qui peut causer des problèmes lors de la soumission`);
      
      // Essayer de trouver le bundle via les autres champs du formulaire
      const $form = $field.closest('form');
      const $bundleFields = $form.find('input[name^="media_ids_by_bundle"]');
      if ($bundleFields.length > 0) {
        const match = $bundleFields.first().attr('name').match(/media_ids_by_bundle\[([^\]]+)\]/);
        if (match && match[1]) {
          const suggestedBundle = match[1];
          console.log(`  - 🔍 Bundle suggéré d'après les autres champs: ${suggestedBundle}`);
          $field.attr('data-bundle', suggestedBundle);
        }
      }
    }
    
    // Marquer clairement ce champ comme champ de taxonomie
    $field.attr("data-mam-taxonomy-field", "true");
    $field.addClass("mam-taxonomy-field");
    
    // S'assurer que data-field-name est défini
    if (!$field.attr("data-field-name")) {
      $field.attr("data-field-name", fieldName);
    }
    
    // Créer ou trouver le champ caché associé
    const hiddenIdFieldName = fieldName + "_tid";
    let $hiddenField = $(`input[name="${hiddenIdFieldName}"]`);
    
    if (!$hiddenField.length) {
      console.log(`  - Création du champ caché: ${hiddenIdFieldName}`);
      $hiddenField = $('<input>', {
        type: 'hidden',
        name: hiddenIdFieldName,
        class: 'taxonomy-term-id mam-taxonomy-term-id',
        'data-for-field': fieldName,
        'data-mam-taxonomy-hidden-field': 'true',
        'data-mam-for-field-name': fieldName,
        'data-bundle': $field.attr('data-bundle') || '',
        'data-field-name': hiddenIdFieldName
      });
      
      // Ajouter le champ caché après le champ autocomplete
      $field.after($hiddenField);
      
      // Lier le champ autocomplete au champ caché
      $field.attr("data-hidden-id-field", hiddenIdFieldName);
    } else {
      console.log(`  - Champ caché existant trouvé: ${hiddenIdFieldName}`);
    }
    
    // Synchroniser la valeur actuelle si elle existe
    const fieldValue = $field.val();
    console.log(`  - Valeur actuelle: "${fieldValue || ''}"`);
    
    // Vérifier d'abord si un ID est stocké dans un attribut data- (ajouté par taxonomy-term-handler)
    if ($field.attr('data-term-id')) {
      const termId = $field.attr('data-term-id');
      $hiddenField.val(termId);
      console.log(`  - ✅ ID récupéré depuis data-term-id: ${termId}`);
    }
    // Sinon, essayer d'extraire l'ID de la valeur
    else if (fieldValue) {
      const termId = extractTermId(fieldValue);
      if (termId) {
        $hiddenField.val(termId);
        $field.attr("data-term-id", termId);
        console.log(`  - ✅ ID extrait et synchronisé: ${termId}`);
      } else {
        console.log(`  - ❌ Impossible d'extraire un ID de la valeur`);
      }
    }
  }

  /**
   * Fonction pour scanner le DOM et rechercher les champs de taxonomie
   */
  function scanForNewTaxonomyFields() {
    // Cibler spécifiquement les champs d'autocomplétion de taxonomie non initialisés
    $('.form-autocomplete[data-autocomplete-path*="/taxonomy_term/"]:not(.mam-taxonomy-field):not([data-mam-taxonomy-field="true"])').each(function() {
      const $field = $(this);
      const fieldName = $field.attr("name");
      
      if (fieldName && fieldName.includes("field_") && !fieldName.includes("_clear") && !fieldName.includes("_tid")) {
        console.log(`  - Nouveau champ de taxonomie détecté: ${fieldName}`);
        initTaxonomyField($field);
      }
    });
    
    // Synchroniser les champs déjà identifiés
    $('.mam-taxonomy-field, [data-mam-taxonomy-field="true"]').each(function() {
      const $field = $(this);
      const fieldName = $field.attr("name");
      
      if (!fieldName) return;
      
      // Synchroniser avec un champ caché si nécessaire
      const hiddenIdField = $field.attr("data-hidden-id-field");
      if (hiddenIdField) {
        const $hiddenField = $(`input[name="${hiddenIdField}"]`);
        
        // Si le champ a un term-id stocké dans data-
        if ($field.attr("data-term-id") && $hiddenField.length) {
          $hiddenField.val($field.attr("data-term-id"));
        }
      }
    });
  }

  /**
   * Comportement pour initialiser tous les champs de taxonomie
   */
  Drupal.behaviors.mamTaxonomyFieldInitializer = {
    attach: function (context, settings) {
      // Sélectionner les champs d'autocomplétion de taxonomie
      once('mam-taxonomy-init', '.form-autocomplete[data-autocomplete-path*="/taxonomy_term/"]', context).forEach(function(field) {
        initTaxonomyField($(field));
      });
      
      // Observer les événements AJAX
      once('mam-taxonomy-observer', 'body', context).forEach(function() {
        $(document).on('drupalAjaxSuccess', function() {
          setTimeout(scanForNewTaxonomyFields, 100);
        });
        
        // Vérification initiale
        setTimeout(scanForNewTaxonomyFields, 500);
      });
    }
  };
  
  /**
   * Comportement pour intercepter la soumission du formulaire
   */
  Drupal.behaviors.mamTaxonomyFormSubmitHelper = {
    attach: function(context, settings) {
      once('mam-taxonomy-submit-helper', 'form', context).forEach(function(form) {
        const $form = $(form);
        
        // Vérification juste avant la soumission
        $form.on('submit.mam-taxonomy', function() {
          console.log('%c[MAM TAXONOMY SUBMIT] %cPréparation des champs pour soumission', 
                     'background: #e74c3c; color: white; padding: 2px 5px; border-radius: 3px;', 
                     'color: #c0392b; font-weight: bold;');
          
          // Trouver tous les champs de taxonomie
          $form.find('.mam-taxonomy-field, [data-mam-taxonomy-field="true"], .form-autocomplete[data-autocomplete-path*="/taxonomy_term/"]').each(function() {
            const $field = $(this);
            const fieldName = $field.attr('name');
            
            if (!fieldName || fieldName.includes("_clear") || fieldName.includes("_tid")) {
              return;
            }
            
            // Récupérer l'ID du terme si disponible
            let termId = $field.attr('data-term-id');
            const fieldValue = $field.val();
            
            if (!termId && fieldValue) {
              termId = extractTermId(fieldValue);
              if (termId) {
                $field.attr('data-term-id', termId);
              }
            }
            
            if (termId) {
              console.log(`  - ID trouvé pour ${fieldName}: ${termId}`);
              
              // Mettre à jour ou créer un champ caché pour l'ID
              const hiddenIdFieldName = fieldName + "_tid";
              let $hiddenField = $form.find(`[name="${hiddenIdFieldName}"]`);
              
              if (!$hiddenField.length) {
                console.log(`    - Création d'un champ caché pour la soumission: ${hiddenIdFieldName}`);
                $hiddenField = $('<input>', {
                  type: 'hidden',
                  name: hiddenIdFieldName,
                  value: termId,
                  class: 'taxonomy-term-id mam-taxonomy-term-id',
                  'data-for-field': fieldName,
                  'data-mam-taxonomy-hidden-field': 'true'
                });
                $field.after($hiddenField);
              } else {
                $hiddenField.val(termId);
              }
              
              // Ajouter aussi un champ avec un format simplifié pour garantir la transmission
              const dataBundle = $field.attr('data-bundle');
              const dataFieldName = $field.attr('data-field-name') || fieldName;
              
              if (dataBundle) {
                const simpleHiddenName = `taxonomy_term_id_${dataBundle}_${dataFieldName.replace(/\[|\]/g, '_')}`;
                let $extraHiddenField = $form.find(`[name="${simpleHiddenName}"]`);
                
                if (!$extraHiddenField.length) {
                  console.log(`    - Création d'un champ supplémentaire: ${simpleHiddenName}`);
                  $extraHiddenField = $('<input>', {
                    type: 'hidden',
                    name: simpleHiddenName,
                    value: termId,
                    'data-for-field': fieldName,
                    'data-bundle': dataBundle,
                    'data-field-name': dataFieldName
                  });
                  $form.append($extraHiddenField);
                } else {
                  $extraHiddenField.val(termId);
                }
              }
            }
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
