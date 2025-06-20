/**
 * @file
 * JavaScript pour forcer le rafraîchissement des médias après une édition en masse.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Comportement pour recharger les éléments média après une mise à jour en masse.
   */
  Drupal.behaviors.mediaAttributesRefresh = {
    attach: function (context, settings) {
      // Définir la fonction pour rafraîchir les médias
      if (!$.fn.refreshMediaItems) {
        $.fn.refreshMediaItems = function(bundles) {
          console.log('Rafraîchissement des médias mis à jour:', bundles);
          
          // Trouver tous les éléments avec la classe media-updated
          const $updatedItems = $('.media-updated', context);
          
          if ($updatedItems.length > 0) {
            console.log('Médias à rafraîchir trouvés:', $updatedItems.length);
            
            // Pour chaque élément mis à jour
            $updatedItems.each(function() {
              const $item = $(this);
              const entityId = $item.attr('data-entity-id');
              
              if (entityId && entityId.startsWith('media:')) {
                const mediaId = entityId.replace('media:', '');
                console.log('Rafraîchissement du média:', mediaId);
                
                // Utiliser les attributs data-media-attr-* existants pour mettre à jour le tooltip
                const $tooltipContent = $item.find('.media-tooltip-content');
                const $mediaElement = $item.find('.media-item-with-tooltip');
                
                if ($tooltipContent.length && $mediaElement.length) {
                  // Collecter tous les attributs data-media-attr-* et data-media-label-*
                  const attributes = {};
                  const labels = {};
                  
                  $.each($mediaElement[0].attributes, function() {
                    if (this.name.startsWith('data-media-attr-')) {
                      const fieldName = this.name.replace('data-media-attr-', '');
                      attributes[fieldName] = this.value;
                    }
                    else if (this.name.startsWith('data-media-label-')) {
                      const fieldName = this.name.replace('data-media-label-', '');
                      labels[fieldName] = this.value;
                    }
                  });
                  
                  // Récupérer toutes les valeurs actuelles des attributs data-* pour le debugging
                  console.log('Attributs disponibles pour le média ' + mediaId + ':', attributes);
                  console.log('Labels disponibles pour le média ' + mediaId + ':', labels);
                  
                  // Reconstruire le contenu du tooltip avec tous les champs disponibles
                  let tooltipHtml = '';
                  const displayedFields = [];
                  
                  // IMPORTANT: Afficher explicitement les champs connus importants comme field_am_photo_author et field_am_photo_description
                  // même s'ils sont vides, pour garantir leur présence dans le tooltip
                  const criticalFields = ['field_am_photo_author', 'field_am_photo_description'];
                  
                  // Traiter d'abord les champs critiques
                  criticalFields.forEach(function(fieldName) {
                    const value = attributes[fieldName] || '';
                    const label = labels[fieldName] || fieldName.charAt(0).toUpperCase() + fieldName.slice(1).replace(/_/g, ' ');
                    
                    displayedFields.push(fieldName);
                    tooltipHtml += '<div class="media-tooltip-attribute" data-field-name="' + fieldName + '">' +
                      '<span class="media-tooltip-label">' + label + ':</span>' +
                      '<span class="media-tooltip-value">' + (value || '') + '</span>' +
                      '</div>';
                    
                    console.log('Ajout du champ critique', fieldName, 'avec valeur:', value);
                  });
                  
                  // Ensuite traiter les autres champs
                  Object.keys(attributes).forEach(function(fieldName) {
                    // Ne pas réafficher les champs critiques déjà traités
                    if (!criticalFields.includes(fieldName)) {
                      // Ne pas afficher de champs vides sauf pour les champs critiques
                      if (attributes[fieldName] !== "") {
                        displayedFields.push(fieldName);
                        const label = labels[fieldName] || fieldName.charAt(0).toUpperCase() + fieldName.slice(1).replace(/_/g, ' ');
                        
                        tooltipHtml += '<div class="media-tooltip-attribute" data-field-name="' + fieldName + '">' +
                          '<span class="media-tooltip-label">' + label + ':</span>' +
                          '<span class="media-tooltip-value">' + attributes[fieldName] + '</span>' +
                          '</div>';
                      }
                    }
                  });
                  
                  // Si aucun champ n'est affiché, ajouter un message
                  if (displayedFields.length === 0) {
                    tooltipHtml += '<div class="media-tooltip-attribute"><i>Aucun attribut personnalisé disponible</i></div>';
                  }
                  
                  // Suppression du débogueur
                  
                  // Remplacer le contenu du tooltip
                  $tooltipContent.html(tooltipHtml);
                  console.log('Tooltip mis à jour pour le média ' + mediaId + ' avec ' + displayedFields.length + ' champs');
                  
                  // Enlever la classe de mise à jour après un court délai
                  setTimeout(function() {
                    $item.removeClass('media-updated');
                    // Ajouter une classe pour indiquer que l'élément a été rechargé
                    $item.addClass('media-refreshed').delay(2000).queue(function(next) {
                      $(this).removeClass('media-refreshed');
                      next();
                    });
                  }, 500);
                }
              }
            });
          }
          
          return this;
        };
      }
      
      // Écouter l'événement mediaAttributesUpdated pour rafraîchir les médias
      $(document).on('mediaAttributesUpdated', function(event, updatedMedia) {
        console.log('Événement mediaAttributesUpdated reçu avec:', updatedMedia);
        
        // Traiter immédiatement les données pour mettre à jour directement les attributs du DOM
        if (updatedMedia && updatedMedia.length) {
          updatedMedia.forEach(function(mediaItem) {
            const mediaSelector = '[data-entity-id="media:' + mediaItem.id + '"]';
            const $mediaElement = $(mediaSelector);
            
            if ($mediaElement.length) {
              console.log('Mise à jour directe du média:', mediaItem.id, 'avec les champs:', mediaItem.fields);
              
              // Indiquer visuellement la mise à jour
              $mediaElement.addClass('media-direct-update');
              
              // Mise à jour directe des attributs data-* dans le DOM
              const $tooltipElement = $mediaElement.find('.media-item-with-tooltip');
              if ($tooltipElement.length && mediaItem.fields) {
                // Journaliser tous les attributs actuels pour le débogage
                const currentAttrs = {};
                $.each($tooltipElement[0].attributes, function() {
                  if (this.name.startsWith('data-media-')) {
                    currentAttrs[this.name] = this.value;
                  }
                });
                console.log('Attributs actuels:', currentAttrs);
                
                // Mettre à jour chaque attribut avec les nouvelles valeurs
                Object.keys(mediaItem.fields).forEach(function(fieldName) {
                  if (!fieldName.endsWith('_label')) {
                    const fieldValue = mediaItem.fields[fieldName] || '';
                    const attrName = 'data-media-attr-' + fieldName;
                    
                    $tooltipElement.attr(attrName, fieldValue);
                    console.log('Mise à jour attribut:', attrName, '=', fieldValue);
                  }
                });
              }
            }
          });
        }
        
        // PUIS attendre un court instant pour que les modifications DOM soient appliquées
        // avant de lancer la reconstruction complète des tooltips
        setTimeout(function() {
          if (updatedMedia && updatedMedia.length) {
            const bundles = updatedMedia.map(function(media) {
              return media.bundle;
            }).filter(function(value, index, self) {
              return self.indexOf(value) === index;
            });
            
            // Force le rafraîchissement des tooltips
            $(document).refreshMediaItems(bundles);
            
            // Ensuite, force un rechargement complet des tooltips
            setTimeout(function() {
              $('.media-updated').each(function() {
                const $item = $(this);
                const $tooltipElement = $item.find('.media-item-with-tooltip');
                
                // Forcer un rerendering des tooltips en détachant et réattachant
                if (Drupal.behaviors.mediaAttributesTooltip && $tooltipElement.length) {
                  $tooltipElement.removeOnce('media-attributes-tooltip');
                  Drupal.behaviors.mediaAttributesTooltip.attach(document, Drupal.settings);
                }
              });
            }, 500);
          }
        }, 300);
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);
