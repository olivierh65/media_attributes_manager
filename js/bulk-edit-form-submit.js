(function ($, Drupal, once) {
  "use strict";

  /**
   * Ce script intercepte la soumission AJAX du formulaire d'édition en masse
   * pour reformater les données avant leur envoi au serveur.
   */
  Drupal.behaviors.bulkEditFormSubmit = {
    attach: function (context, settings) {
      once(
        "bulk-edit-form-submit",
        ".bulk-edit-submit-button",
        context
      ).forEach(function (button) {
        // Intercepter le comportement normal de Drupal AJAX
        $(button).on("click", function (e) {
          e.preventDefault();

          // Récupérer le formulaire parent
          const $form = $(button).closest("form");

          // Récupérer toutes les données du formulaire
          const formData = $form.serializeArray();

          // Reformater les données selon la structure demandée avec un niveau supplémentaire:
          // Structure: { entities: { media: { types: { type: { ids: [], fields: {} } } } } }
          // Où 'type' est le bundle media (image, video_file, etc.)
          const processedData = {
            entities: {
              media: {
                types: {},
              },
            },
            formMetadata: {}, // Pour stocker form_build_id, form_token, etc.
          };

          // Structure pour conserver les données organisées par bundle
          const mediaIdsInBundles = {};
          const mediaFieldsByBundle = {};
          const formMetaFields = {};
          let allMediaIds = [];

          // Récupérer tous les champs avec les attributs data-
          const formFields = $form.find("[data-field-name], [data-bundle], [name^='media_ids']");
          
          // Première passe : récupérer les IDs des médias par bundle et les champs de métadonnées du formulaire
          $.each(formData, function (i, field) {
            // Récupérer les champs de métadonnées du formulaire Drupal
            if (["form_build_id", "form_token", "form_id"].includes(field.name)) {
              formMetaFields[field.name] = field.value;
              processedData.formMetadata[field.name] = field.value;
              return;
            }
            
            // Récupérer les IDs des médias par bundle
            if (field.name.startsWith("media_ids_by_bundle")) {
              const match = field.name.match(/media_ids_by_bundle\[([^\]]+)\]/);
              if (match && match[1]) {
                const bundle = match[1];
                const ids = field.value.split(",").filter(id => id.trim() !== "");
                if (ids.length > 0) {
                  mediaIdsInBundles[bundle] = ids;
                  
                  // Initialiser la structure pour ce bundle si nécessaire
                  if (!mediaFieldsByBundle[bundle]) {
                    mediaFieldsByBundle[bundle] = {};
                  }
                }
              }
            }
            
            // Récupérer tous les IDs de médias en cas de fallback nécessaire
            if (field.name === "media_ids") {
              allMediaIds = field.value.split(",").filter(id => id.trim() !== "");
            }
          });
          
          // Deuxième passe : parcourir les champs DOM pour récupérer les valeurs et attributs data-
          formFields.each(function() {
            const $field = $(this);
            const fieldName = $field.attr("name");
            
            // Ignorer les champs sans nom ou déjà traités
            if (!fieldName || ["form_build_id", "form_token", "form_id", "media_ids"].includes(fieldName)) {
              return;
            }
            
            // Récupérer les attributs data-
            const dataBundle = $field.attr("data-bundle");
            const dataFieldName = $field.attr("data-field-name");
            
            // Ignorer les champs sans attributs data-bundle si ce n'est pas un champ media_ids
            if (!dataBundle && !fieldName.startsWith("media_ids_by_bundle")) {
              return;
            }
            
            // Traiter les champs personnalisés
            if (dataBundle && dataFieldName) {
              // Initialiser la structure pour ce bundle si nécessaire
              if (!mediaFieldsByBundle[dataBundle]) {
                mediaFieldsByBundle[dataBundle] = {};
              }
              
              // Récupérer la valeur du champ
              const fieldValue = $field.val();
              
              // Traiter les champs normaux et les cases à cocher pour effacer
              if (dataFieldName.endsWith("_clear")) {
                // Pour les checkbox "Clear", vérifier si elles sont cochées
                // Les checkboxes HTML renvoient "on" ou undefined
                const isChecked = $field.is(":checked");
                mediaFieldsByBundle[dataBundle][dataFieldName] = isChecked;
              } else {
                // Pour les champs normaux, stocker leur valeur
                mediaFieldsByBundle[dataBundle][dataFieldName] = fieldValue;
              }
            }
          });

          // Construire la structure de données finale
          if (Object.keys(mediaIdsInBundles).length > 0) {
            // Nous avons des IDs organisés par bundle, utiliser cette structure
            for (const bundle in mediaIdsInBundles) {
              processedData.entities.media.types[bundle] = {
                ids: mediaIdsInBundles[bundle],
                fields: mediaFieldsByBundle[bundle] || {}
              };
            }
          } else {
            // Fallback: Tenter d'utiliser les données de media_data pour retrouver les bundles
            const mediaDataField = formData.find(
              (f) => f.name === "media_data" || f.name === "mediaData"
            );
            
            if (mediaDataField && allMediaIds.length > 0) {
              try {
                // Analyser les données JSON des médias qui contiennent les types
                const mediaData = JSON.parse(mediaDataField.value);
                const mediaByBundle = {};
                
                // Regrouper les médias par bundle
                mediaData.forEach((item) => {
                  if (item.id && item.bundle) {
                    if (!mediaByBundle[item.bundle]) {
                      mediaByBundle[item.bundle] = [];
                    }
                    mediaByBundle[item.bundle].push(item.id);
                  }
                });
                
                // Construire la structure finale
                for (const bundle in mediaByBundle) {
                  processedData.entities.media.types[bundle] = {
                    ids: mediaByBundle[bundle],
                    fields: mediaFieldsByBundle[bundle] || {}
                  };
                }
              } catch (e) {
                console.error("Error parsing media data:", e);
                
                // Fallback: En cas d'erreur, utiliser un type générique
                processedData.entities.media.types.generic = {
                  ids: allMediaIds,
                  fields: Object.assign({}, ...Object.values(mediaFieldsByBundle))
                };
              }
            } else {
              // Dernier recours: Si aucune information de bundle n'est disponible
              processedData.entities.media.types.generic = {
                ids: allMediaIds,
                fields: Object.assign({}, ...Object.values(mediaFieldsByBundle))
              };
            }
          }

          // Supprimer la propriété temporaire
          delete processedData.allMediaIds;

          // Créer un élément factice pour l'initialisation AJAX de Drupal
          const $dummyElement = $("<div></div>");

          // Logging pour le débogage
          console.log("Données formatées pour l'envoi:", processedData);

          // Configuration AJAX spécifique à Drupal
          const ajaxSettings = {
            url: Drupal.url("media-attributes/bulk-edit/submit"),
            base: false,
            element: $dummyElement.get(0),
            submit: {
              js: true,
              entitiesData: JSON.stringify(processedData.entities),
              ajax_form: 1,
              // Ajouter les champs de sécurité Drupal directement dans la requête
              form_build_id: processedData.formMetadata.form_build_id,
              form_token: processedData.formMetadata.form_token,
              form_id: processedData.formMetadata.form_id,
            },
          };

          // Créer et exécuter la requête AJAX via le système Drupal
          const ajaxRequest = Drupal.ajax(ajaxSettings);
          ajaxRequest.execute();
        });
      });

      // Écouteur pour l'événement personnalisé après mise à jour
      once("media-attributes-updated-handler", "body", context).forEach(
        function (element) {
          $(document).on(
            "mediaAttributesUpdated",
            function (event, updatedMedia) {
              // Déclencher des actions supplémentaires si nécessaire après mise à jour
              if (updatedMedia && updatedMedia.length > 0) {
                // Par exemple, mettre à jour d'autres éléments de l'interface
                console.log("Mise à jour des médias réussie:", updatedMedia);
              }
            }
          );
        }
      );
    },
  };
})(jQuery, Drupal, once);
