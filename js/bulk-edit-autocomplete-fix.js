(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Debug function - décommenter pour activer les logs en développement
  function debugLog(message, data) {
    // Réduire les logs en production - décommenter pour debug
    // if (console && console.log) {
    //   console.log('[BulkEditAutocomplete]', message, data || '');
    // }
  }

  /**
   * OBJECTIF : Résoudre le problème d'autocomplétion qui ne fonctionne pas
   * à la première ouverture des modales bulk edit.
   * 
   * PROBLÈME : Les scripts Drupal d'autocomplétion ne sont pas automatiquement
   * attachés aux nouveaux éléments DOM insérés via AJAX dans les modales.
   * 
   * SOLUTION : Détecter l'ajout de nouveaux éléments et forcer l'initialisation
   * de l'autocomplétion avec plusieurs méthodes de fallback.
   */
  Drupal.behaviors.bulkEditModalAutocompleteFix = {
    attach: function (context, settings) {
      debugLog('Behavior attached');
      
      // Utiliser once pour s'assurer que l'événement n'est attaché qu'une seule fois
      // MÉTHODE 1 : Écouter l'événement d'ouverture des dialogues
      // Drupal déclenche 'dialogopen' quand une modale s'ouvre
      $(once('dialog-open-handler', 'body', context)).on('dialogopen.bulkEditFix', function (event) {
        var $dialog = $(event.target);
        debugLog('Dialog opened');
        
        // Chercher le formulaire de bulk edit avec plusieurs sélecteurs possibles
        // Car l'ID peut varier selon le contexte
        var $bulkForm = $dialog.find('#media-attributes-bulk-edit-form, form[id*="bulk"], form[id*="media-attributes"], .bulk-edit-form, form[data-drupal-form-id*="bulk"]');
        
        if ($bulkForm.length > 0) {
          debugLog('Bulk edit modal detected', {
            formId: $bulkForm.attr('id'),
            formClass: $bulkForm.attr('class'),
            selector: 'found'
          });
          
          // Multiples tentatives d'initialisation à des délais différents
          // Car le DOM peut ne pas être complètement rendu immédiatement
          setTimeout(function() { initAutocompleteFields($dialog, 'attempt1'); }, 100);
          setTimeout(function() { initAutocompleteFields($dialog, 'attempt2'); }, 300);
          setTimeout(function() { initAutocompleteFields($dialog, 'attempt3'); }, 600);
        } else {
          // Debugger ce qu'on trouve dans le dialogue pour diagnostiquer
          debugLog('No bulk edit form found in dialog', {
            allForms: $dialog.find('form').map(function() { 
              return {
                id: this.id,
                className: this.className,
                action: this.action
              };
            }).get(),
            dialogContent: $dialog.find('*[id]').map(function() { 
              return this.id; 
            }).get().slice(0, 10) // Limiter à 10 premiers éléments avec ID
          });
          
          // Essayer quand même d'initialiser l'autocomplétion si on trouve des champs
          if ($dialog.find('input[data-autocomplete-path]').length > 0) {
            debugLog('Found autocomplete fields anyway, trying to initialize');
            setTimeout(function() { initAutocompleteFields($dialog, 'fallback'); }, 200);
          }
        }
      });
      
      // MÉTHODE 2 : Observer les mutations DOM
      // MutationObserver détecte quand de nouveaux éléments sont ajoutés au DOM
      // Plus fiable que les événements pour détecter l'ajout de contenu AJAX
      if (window.MutationObserver) {
        var observer = new MutationObserver(function(mutations) {
          mutations.forEach(function(mutation) {
            if (mutation.type === 'childList') {
              // Chercher les formulaires avec plusieurs sélecteurs
              $(mutation.addedNodes).find('form[id*="bulk"], form[id*="media-attributes"], .bulk-edit-form, form[data-drupal-form-id*="bulk"]').each(function() {
                debugLog('Form detected via mutation observer', {
                  formId: this.id,
                  formClass: this.className
                });
                var $form = $(this);
                var $dialog = $form.closest('.ui-dialog-content, .ui-dialog');
                setTimeout(function() { initAutocompleteFields($dialog, 'mutation'); }, 50);
              });
              
              // Aussi chercher directement les champs d'autocomplétion
              // Car parfois le formulaire est déjà là mais les champs sont ajoutés après
              $(mutation.addedNodes).find('input[data-autocomplete-path]').each(function() {
                debugLog('Autocomplete field detected via mutation observer', {
                  fieldId: this.id,
                  path: $(this).data('autocomplete-path')
                });
                var $field = $(this);
                var $dialog = $field.closest('.ui-dialog-content, .ui-dialog');
                setTimeout(function() { initAutocompleteFields($dialog, 'field-mutation'); }, 50);
              });
            }
          });
        });
        
        // Observer tout le body pour détecter les changements
        observer.observe(document.body, {
          childList: true,
          subtree: true
        });
      }
    }
  };

  /**
   * Fonction principale pour initialiser les champs d'autocomplétion
   * 
   * Cette fonction essaie plusieurs méthodes d'initialisation car :
   * 1. Drupal.autocomplete.attach() peut ne pas fonctionner dans certains contextes
   * 2. L'initialisation manuelle jQuery UI peut être nécessaire
   * 3. Les paramètres de sélection doivent être copiés depuis les définitions de champs
   */
  function initAutocompleteFields($container, attempt) {
    debugLog('Initializing autocomplete fields', attempt);
    
    $container.find('input[data-autocomplete-path]').each(function() {
      var $input = $(this);
      var path = $input.data('autocomplete-path');
      
      if (!path) {
        debugLog('No autocomplete path for input', $input.attr('id'));
        return;
      }
      
      // Éviter la réinitialisation multiple si déjà initialisé avec succès
      // Ceci évite le spam d'initialisations quand plusieurs événements se déclenchent
      if ($input.data('autocomplete-fixed') && $input.data('ui-autocomplete')) {
        debugLog('Autocomplete already initialized and working', $input.attr('id'));
        return;
      }
      
      debugLog('Processing input', {
        id: $input.attr('id'),
        path: path,
        hasAutocomplete: !!$input.data('ui-autocomplete'),
        attempt: attempt
      });
      
      // Nettoyer complètement l'autocomplétion existante
      if ($input.data('ui-autocomplete')) {
        $input.autocomplete('destroy');
      }
      $input.removeData('ui-autocomplete');
      $input.removeClass('ui-autocomplete-input');
      $input.off('input.autocomplete keydown.autocomplete');
      
      // MÉTHODE 1 : Utiliser Drupal.autocomplete si disponible (méthode préférée)
      if (typeof Drupal.autocomplete !== 'undefined' && Drupal.autocomplete.attach) {
        try {
          Drupal.autocomplete.attach($input[0]);
          $input.data('autocomplete-fixed', true);
          debugLog('Used Drupal.autocomplete.attach', $input.attr('id'));
          return;
        } catch (e) {
          debugLog('Drupal.autocomplete.attach failed', e.message);
        }
      }
      
      // MÉTHODE 2 : Initialisation manuelle jQuery UI (fallback)
      // Si la méthode Drupal échoue, on initialise manuellement avec jQuery UI
      try {
        $input.autocomplete({
          source: function(request, response) {
            debugLog('Autocomplete request', {term: request.term, path: path});
            
            // Faire la requête AJAX vers l'endpoint d'autocomplétion Drupal
            $.ajax({
              url: path,
              data: { q: request.term },
              dataType: 'json',
              success: function(data) {
                debugLog('Autocomplete response', data);
                // Formatter les données au format jQuery UI
                response(data.map(function(item) {
                  return {
                    label: item.label || item.value,
                    value: item.value || item.label
                  };
                }));
              },
              error: function(xhr, status, error) {
                debugLog('Autocomplete AJAX error', {status: status, error: error});
                response([]);
              }
            });
          },
          minLength: 1,     // Déclencher après 1 caractère
          delay: 300,       // Attendre 300ms après la dernière frappe
          select: function(event, ui) {
            debugLog('Autocomplete selection', ui.item);
            $input.val(ui.item.label || ui.item.value);
            // Déclencher l'événement change pour notifier Drupal
            $input.trigger('change');
            return false; // Empêcher le comportement par défaut
          }
        });
        
        $input.data('autocomplete-fixed', true);
        debugLog('Manual autocomplete initialized', $input.attr('id'));
      } catch (e) {
        debugLog('Manual autocomplete initialization failed', e.message);
      }
    });
  }

})(jQuery, Drupal, drupalSettings, once);
