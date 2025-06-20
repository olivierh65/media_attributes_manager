/**
 * @file
 * JavaScript pour gérer les tooltips d'attributs personnalisés des médias
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Comportement pour initialiser et gérer les tooltips d'attributs personnalisés
   */
  Drupal.behaviors.mediaAttributesTooltip = {
    attach: function (context, settings) {
      // Initialise les tooltips sur les médias
      once('media-attributes-tooltip', '.media-item-with-tooltip', context).forEach(function(element) {
        const $media = $(element);
        
        // Ajoute des événements pour améliorer l'expérience utilisateur
        $media.on('mouseenter', function() {
          // On peut ajouter des effets ou du traitement supplémentaire ici
          $media.addClass('tooltip-active');
        });
        
        $media.on('mouseleave', function() {
          $media.removeClass('tooltip-active');
        });
      });
      
      // Écouteur d'événement pour mettre à jour les tooltips après une mise à jour des attributs
      $(document).on('mediaAttributesUpdated', function(event, updatedMedia) {
        if (updatedMedia && updatedMedia.length) {
          console.log('Mise à jour des tooltips pour les médias modifiés:', updatedMedia);
          
          // Pour chaque média mis à jour
          updatedMedia.forEach(function(mediaData) {
            // Trouver tous les éléments correspondant à ce média
            const $mediaElements = $('[data-media-id="' + mediaData.id + '"]');
            
            if ($mediaElements.length > 0) {
              console.log('Éléments trouvés pour le média ' + mediaData.id + ':', $mediaElements.length);
              
              // 1. D'abord collecter tous les attributs data-* actuels pour le débogage
              const currentAttrs = {};
              const $tooltipElement = $mediaElements.find('.media-item-with-tooltip');
              if ($tooltipElement.length) {
                $.each($tooltipElement[0].attributes, function() {
                  if (this.name.startsWith('data-media-')) {
                    currentAttrs[this.name] = this.value;
                  }
                });
                console.log('Attributs actuels pour le média ' + mediaData.id + ':', currentAttrs);
              }
              
              // 2. Mettre à jour les attributs data-* avec les nouvelles valeurs
              if (mediaData.fields) {
                const updatedFields = [];
                
                // Traiter tous les champs mis à jour
                Object.keys(mediaData.fields).forEach(function(fieldName) {
                  // Ne pas traiter les champs de clearing ou les champs TID auxiliaires
                  if (!fieldName.endsWith('_clear') && !fieldName.endsWith('_tid')) {
                    const value = mediaData.fields[fieldName];
                    const safeFieldName = fieldName.toLowerCase().replace(/[^a-zA-Z0-9_]/g, '_');
                    
                    // Formatter la valeur en chaîne pour l'attribut HTML
                    let attrValue = value;
                    if (Array.isArray(value)) {
                      attrValue = value.join(', ');
                    } else if (value === null || value === undefined) {
                      attrValue = '';
                    } else if (typeof value === 'boolean') {
                      attrValue = value ? 'Oui' : 'Non';
                    }
                    
                    // TRÈS IMPORTANT: Mettre à jour l'attribut data-* à la fois sur le conteneur parent ET sur l'élément tooltip
                    $mediaElements.attr('data-media-attr-' + safeFieldName, attrValue);
                    $tooltipElement.attr('data-media-attr-' + safeFieldName, attrValue);
                    
                    // Conserver la trace du champ mis à jour pour l'affichage du tooltip
                    updatedFields.push(safeFieldName);
                    
                    console.log('Mise à jour de l\'attribut', 'data-media-attr-' + safeFieldName, 'avec la valeur', attrValue);
                    
                    // 3. Mettre à jour directement le HTML du tooltip ou créer un nouvel élément si nécessaire
                    const $tooltipValue = $mediaElements.find('.media-tooltip-attribute[data-field-name="' + safeFieldName + '"] .media-tooltip-value');
                    if ($tooltipValue.length) {
                      // Si le champ existe déjà dans le tooltip, mettre à jour sa valeur
                      $tooltipValue.text(attrValue || '');
                      console.log('Mise à jour du tooltip pour le champ', safeFieldName);
                    } else {
                      // Si le champ n'existe pas dans le tooltip, l'ajouter
                      const $tooltipContent = $mediaElements.find('.media-tooltip-content');
                      if ($tooltipContent.length) {
                        const labelKey = 'data-media-label-' + safeFieldName;
                        const label = currentAttrs[labelKey] || mediaData.fields[fieldName + '_label'] || safeFieldName.charAt(0).toUpperCase() + safeFieldName.slice(1).replace(/_/g, ' ');
                        
                        // Créer la nouvelle entrée de tooltip
                        const newField = $('<div class="media-tooltip-attribute" data-field-name="' + safeFieldName + '">' +
                          '<span class="media-tooltip-label">' + label + ':</span>' +
                          '<span class="media-tooltip-value">' + (attrValue || '') + '</span></div>');
                        
                        // L'ajouter au tooltip
                        $tooltipContent.prepend(newField);
                        console.log('Ajout d\'un nouveau champ au tooltip:', safeFieldName);
                      }
                    }
                  }
                });
                
                // Suppression de la partie débogage
                
                // Journaliser les champs mis à jour pour le débogage
                console.log('Champs mis à jour pour le média ' + mediaData.id + ':', updatedFields.join(', '));
              }
              
              // 5. Appliquer une classe temporaire pour indiquer visuellement la mise à jour
              $mediaElements.addClass('tooltip-updated');
              setTimeout(function() {
                $mediaElements.removeClass('tooltip-updated');
              }, 2000);
              
              // 6. Forcer l'initialisation complète du tooltip après un court délai
              setTimeout(function() {
                $tooltipElement.removeOnce('media-attributes-tooltip');
                Drupal.behaviors.mediaAttributesTooltip.attach(document, Drupal.settings);
              }, 500);
            }
          });
        }
      });
    }
  };

})(jQuery, Drupal, once);
