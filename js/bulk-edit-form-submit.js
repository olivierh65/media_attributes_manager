(function ($, Drupal, once) {
  "use strict";

  /**
   * Ce script intercepte la soumission AJAX du formulaire d'édition en masse
   * et reformate les données avant leur envoi au serveur.
   */
  Drupal.behaviors.bulkEditFormSubmit = {
    attach: function (context, settings) {
      // Vérifier et configurer les bundles pour les champs
      once(
        "bundle-attributes-check",
        ".form-autocomplete",
        context
      ).forEach(function(field) {
        const $field = $(field);
        const fieldName = $field.attr("name");

        // Vérifier seulement si c'est un champ de média
        if (fieldName && fieldName.includes("field_") && !fieldName.includes("_clear") && !fieldName.includes("_tid")) {
          // Vérifier si le bundle est défini
          if (!$field.attr("data-bundle")) {
            // Tenter de récupérer le bundle depuis les champs de médias dans le formulaire
            const $form = $field.closest("form");
            const $bundleFields = $form.find('input[name^="media_ids_by_bundle"]');
            
            if ($bundleFields.length > 0) {
              // Extraire le bundle du premier champ trouvé
              const match = $bundleFields.first().attr('name').match(/media_ids_by_bundle\[([^\]]+)\]/);
              if (match && match[1]) {
                const suggestedBundle = match[1];
                console.log(`Ajout de l'attribut data-bundle manquant pour ${fieldName}: ${suggestedBundle}`);
                $field.attr('data-bundle', suggestedBundle);
              }
            }
          }

          // Vérifier si data-field-name est défini
          if (!$field.attr("data-field-name")) {
            $field.attr("data-field-name", fieldName);
          }
        }
      });

      // Traiter le formulaire lors de la soumission
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
          const formFields = $form.find("[data-field-name], [data-bundle], [name^='media_ids'], [data-mam-taxonomy-field], [data-mam-taxonomy-hidden-field]");

          console.log(`%c[MAM FORM SUBMIT] %cRécupération des champs du formulaire`,
                    'background: #9b59b6; color: white; padding: 2px 5px; border-radius: 3px;',
                    'color: #8e44ad; font-weight: bold;');

          // Vérifier spécifiquement les champs de taxonomie
          const $taxonomyFields = $form.find(".mam-taxonomy-field, [data-mam-taxonomy-field='true']");
          console.log(`  - Nombre de champs de taxonomie trouvés: ${$taxonomyFields.length}`);

          $taxonomyFields.each(function() {
            const $field = $(this);
            console.log(`  - Champ taxonomie: ${$field.attr('name')}, valeur: "${$field.val()}", ID caché: ${$field.attr('data-hidden-id-field')}`);
          });

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

          // Journal de débogage pour voir tous les champs du formulaire
          console.log('Tous les champs du formulaire:', formData);

          // Deuxième passe : parcourir les champs DOM pour récupérer les valeurs et attributs data-
          console.log('Début de l\'analyse des champs du formulaire');
          formFields.each(function() {
            const $field = $(this);
            const fieldName = $field.attr("name");

            // Journalisation pour le débogage
            if (fieldName && fieldName.includes('field_am_photo')) {
              console.log(`Champ trouvé: ${fieldName}`, {
                'data-bundle': $field.attr('data-bundle'),
                'data-field-name': $field.attr('data-field-name'),
                'data-field-type': $field.attr('data-field-type'),
                'data-taxonomy-field': $field.attr('data-taxonomy-field'),
                'data-term-id': $field.attr('data-term-id'),
                'data-hidden-id-field': $field.attr('data-hidden-id-field'),
                'val': $field.val(),
                'type': $field.attr('type'),
                'id': $field.attr('id'),
                'class': $field.attr('class')
              });
            }

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
              } else if (dataFieldName.endsWith("_tid")) {
                // Pour les champs cachés contenant les IDs de termes taxonomiques
                const relatedFieldName = dataFieldName.replace("_tid", "");
                // Stocker la valeur du champ caché pour une utilisation ultérieure
                mediaFieldsByBundle[dataBundle][dataFieldName] = fieldValue;

                console.log(`Champ caché d'ID taxonomique détecté: ${dataFieldName}, valeur: ${fieldValue}`);
                // Vérifier si le champ principal a déjà été traité
                if (!mediaFieldsByBundle[dataBundle][relatedFieldName]) {
                  // S'il n'est pas déjà défini, stocker provisoirement l'ID
                  mediaFieldsByBundle[dataBundle][relatedFieldName] = fieldValue;
                }
              } else {
                // Pour les champs normaux
                const dataFieldType = $field.attr("data-field-type");

                // Définir si c'est un champ de taxonomie - utiliser en priorité le nouvel attribut
                const isTaxonomyField = $field.attr("data-mam-taxonomy-field") === "true" ||
                                       $field.hasClass("mam-taxonomy-field") ||
                                       // Fallback à l'ancienne méthode de détection
                                       (dataFieldType === "entity_reference" &&
                                       ($field.attr("data-taxonomy-field") === "true" ||
                                        $field.hasClass("form-autocomplete") &&
                                        fieldName.includes("field_") &&
                                        !fieldName.includes("_clear") &&
                                        !fieldName.includes("_tid")));

                // Traitement spécial pour les champs entity_autocomplete de taxonomie
                console.log(`Vérification pour le champ ${dataFieldName}:`, {
                  'dataFieldType': dataFieldType,
                  'data-taxonomy-field': $field.attr("data-taxonomy-field"),
                  'is form-autocomplete': $field.hasClass("form-autocomplete"),
                  'is taxonomy field': isTaxonomyField
                });

                if (isTaxonomyField) {
                  console.log(`Champ de taxonomie détecté: ${dataFieldName}`);

                  // D'abord, chercher un champ caché associé qui contient l'ID du terme
                  const hiddenIdField = $field.attr("data-hidden-id-field");
                  console.log(`Champ caché associé: ${hiddenIdField}`);

                  if (hiddenIdField) {
                    // Si on a un champ caché associé, récupérer sa valeur
                    const $hiddenField = $(`[name="${hiddenIdField}"]`);
                    console.log(`Champ caché trouvé:`, $hiddenField.length > 0, `Valeur:`, $hiddenField.val());

                    if ($hiddenField.length && $hiddenField.val()) {
                      // Utiliser l'ID stocké dans le champ caché
                      const termId = $hiddenField.val();
                      mediaFieldsByBundle[dataBundle][dataFieldName] = termId;
                      console.log(`Using term ID ${termId} from hidden field for ${dataFieldName}`);
                    } else {
                      // Fallback: essayer d'extraire l'ID du format autocomplete
                      console.log(`Pas de valeur dans le champ caché, fallback vers extraction d'ID`);
                      extractTermId();
                    }
                  } else {
                    // Essayer d'extraire l'ID directement de l'attribut data
                    const dataTermId = $field.attr("data-term-id");
                    console.log(`Attribut data-term-id: ${dataTermId}`);

                    if (dataTermId) {
                      mediaFieldsByBundle[dataBundle][dataFieldName] = dataTermId;
                      console.log(`Using term ID ${dataTermId} from data attribute for ${dataFieldName}`);
                    } else {
                      // Fallback: essayer d'extraire l'ID du format autocomplete
                      console.log(`Pas d'attribut data-term-id, fallback vers extraction d'ID`);
                      extractTermId();
                    }
                  }

                  // Fonction locale pour extraire l'ID du format autocomplete "Label (id)"
                  function extractTermId() {
                    // D'abord vérifier si nous avons un ID de terme dans un attribut data
                  if ($field.attr('data-term-id')) {
                    console.log(`[FORM SUBMIT] Utilisation de l'ID de terme stocké dans data-term-id: ${$field.attr('data-term-id')}`);
                    matches = [null, $field.attr('data-term-id')];
                  }
                  // Si non, continuer avec la méthode d'extraction traditionnelle
                  else {
                    // 1. Essayer le format standard "Label (id)"
                    let matches = fieldValue.match(/\(([0-9]+)\)$/);
    
                    // 2. Essayer d'autres formats possibles
                    if (!matches) {
                      // Format "id" (juste un nombre)
                      if (/^[0-9]+$/.test(fieldValue)) {
                        matches = [null, fieldValue];
                      }
                      // Format "Label [id]"
                      else if (fieldValue.match(/\[([0-9]+)\]$/)) {
                        matches = fieldValue.match(/\[([0-9]+)\]$/);
                      }
                      // Format avec l'ID quelque part dans la chaîne
                      else if (fieldValue.match(/[^0-9]([0-9]+)[^0-9]/)) {
                        matches = fieldValue.match(/[^0-9]([0-9]+)[^0-9]/);
                      }
                    }
                  }

                    if (matches && matches[1]) {
                      mediaFieldsByBundle[dataBundle][dataFieldName] = matches[1];
                      console.log(`Extracted term ID ${matches[1]} from ${fieldValue} for field ${dataFieldName}`);
                    } else {
                      console.log(`Could not extract term ID from ${fieldValue} for field ${dataFieldName}`);
                      // Si tout échoue et que la valeur ressemble à un ID numérique, l'utiliser directement
                      if (/^[0-9]+$/.test(fieldValue.trim())) {
                        mediaFieldsByBundle[dataBundle][dataFieldName] = fieldValue.trim();
                        console.log(`Using numeric value ${fieldValue} as term ID for field ${dataFieldName}`);
                      } else {
                        // Dernier recours : utiliser la valeur telle quelle
                        mediaFieldsByBundle[dataBundle][dataFieldName] = fieldValue;
                      }
                    }
                  }
                } else {
                  // Pour tous les autres champs normaux, stocker la valeur telle quelle
                  mediaFieldsByBundle[dataBundle][dataFieldName] = fieldValue;
                }
              }
            }
          });

          // Construire la structure de données finale
          if (Object.keys(mediaIdsInBundles).length > 0) {
            // Nous avons des IDs organisés par bundle, utiliser cette structure
            for (const bundle in mediaIdsInBundles) {
              // Vérifier et traiter les champs taxonomiques pour s'assurer que les IDs sont inclus
              const bundleFields = mediaFieldsByBundle[bundle] || {};

              // Parcourir tous les champs pour assurer que les champs taxonomiques ont leurs IDs
              for (const fieldName in bundleFields) {
                // Si c'est un champ taxonomique et qu'il a un champ _tid associé
                if (fieldName.includes('field_') && !fieldName.endsWith('_tid') && !fieldName.endsWith('_clear')) {
                  const tidFieldName = fieldName + '_tid';
                  if (bundleFields[tidFieldName]) {
                    console.log(`Champ taxonomie trouvé: ${fieldName}, assignant la valeur de l'ID: ${bundleFields[tidFieldName]}`);
                    // Utiliser l'ID du terme comme valeur du champ principal
                    bundleFields[fieldName] = bundleFields[tidFieldName];
                  } else {
                    // Vérifier si nous avons un champ d'ID supplémentaire (format simplifié)
                    const simpleHiddenName = `taxonomy_term_id_${bundle}_${fieldName.replace(/\[|\]/g, '_')}`;
                    const $extraHiddenField = $form.find(`[name="${simpleHiddenName}"]`);
                    if ($extraHiddenField.length && $extraHiddenField.val()) {
                      console.log(`Champ d'ID supplémentaire trouvé: ${simpleHiddenName}, valeur: ${$extraHiddenField.val()}`);
                      // Utiliser l'ID du champ supplémentaire
                      bundleFields[fieldName] = $extraHiddenField.val();
                      // Ajouter également comme champ _tid pour assurer la compatibilité
                      bundleFields[tidFieldName] = $extraHiddenField.val();
                    } else if (fieldName.includes('field_') && $form.find(`[data-field-name="${fieldName}"]`).attr('data-term-id')) {
                      // Fallback : vérifier l'attribut data-term-id
                      const termId = $form.find(`[data-field-name="${fieldName}"]`).attr('data-term-id');
                      console.log(`ID taxonomie trouvé dans l'attribut data: ${fieldName} => ${termId}`);
                      bundleFields[fieldName] = termId;
                      bundleFields[tidFieldName] = termId;
                    }
                  }
                }
              }

              processedData.entities.media.types[bundle] = {
                ids: mediaIdsInBundles[bundle],
                fields: bundleFields
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

          // Dernière vérification et synchronisation des champs de taxonomie avant l'envoi
          console.log(`%c[MAM FINAL CHECK] %cVérification finale avant envoi`,
                    'background: #e74c3c; color: white; padding: 2px 5px; border-radius: 3px;',
                    'color: #c0392b; font-weight: bold;');

          // Utiliser les nouveaux attributs plus spécifiques
          const $allTaxonomyFields = $form.find('.mam-taxonomy-field, [data-mam-taxonomy-field="true"]');
          console.log(`  - Nombre de champs taxonomie trouvés: ${$allTaxonomyFields.length}`);

          $allTaxonomyFields.each(function() {
            const $field = $(this);
            const fieldName = $field.attr('name');
            const fieldValue = $field.val();
            const dataBundle = $field.attr('data-bundle');
            const hiddenIdField = $field.attr('data-hidden-id-field');

            console.log(`  - Vérification du champ taxonomie: ${fieldName}`);
            console.log(`    - Valeur: "${fieldValue}"`);
            console.log(`    - Bundle: ${dataBundle || 'non défini'}`);
            console.log(`    - Champ caché: ${hiddenIdField || 'non défini'}`);

            // Si le champ a une valeur et appartient à un bundle
            if (fieldValue && dataBundle && hiddenIdField) {
              const $hiddenField = $(`[name="${hiddenIdField}"]`);

              if ($hiddenField.length) {
                const hiddenValue = $hiddenField.val();
                console.log(`    - Valeur du champ caché: ${hiddenValue || 'VIDE'}`);

                // Si le champ caché a une valeur, s'assurer qu'elle est incluse dans la structure
                if (hiddenValue && mediaFieldsByBundle[dataBundle]) {
                  mediaFieldsByBundle[dataBundle][hiddenIdField] = hiddenValue;
                  // S'assurer que le champ principal utilise aussi la valeur d'ID
                  mediaFieldsByBundle[dataBundle][fieldName] = hiddenValue;
                  console.log(`    - ✅ Synchronisé: ${fieldName} => ${hiddenValue}`);
                } else {
                  console.log(`    - ⚠️ Champ caché vide ou bundle non défini`);
                }
              } else {
                console.log(`    - ❌ Champ caché non trouvé dans le DOM`);
              }
            } else {
              console.log(`    - ❌ Informations manquantes pour la synchronisation`);
            }
          });

          // Log complet de la structure finale
          console.log('Structure finale à envoyer:', JSON.parse(JSON.stringify(processedData)));

          // Vérifier spécifiquement les champs taxonomie
          for (const bundle in processedData.entities.media.types) {
            if (processedData.entities.media.types[bundle].fields) {
              const fields = processedData.entities.media.types[bundle].fields;
              console.log(`Bundle ${bundle}, champs:`, fields);
              for (const fieldName in fields) {
                if (fieldName.includes('field_') && !fieldName.endsWith('_clear')) {
                  console.log(`Bundle ${bundle}, Champ: ${fieldName}, Valeur: ${fields[fieldName]}, Type: ${typeof fields[fieldName]}`);
                  if (fieldName.endsWith('_tid')) {
                    console.log(`  Ce champ est un ID de taxonomie associé à ${fieldName.replace('_tid', '')}`);
                  }
                }
              }
            }
          }

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
