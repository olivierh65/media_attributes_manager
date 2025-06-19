/**
 * @file
 * Force l'initialisation du drag & drop avec Sortable.js.
 */
(function (Drupal, once) {
  "use strict";

  /**
   * Initialise le drag & drop sur les listes de médias.
   */
  Drupal.behaviors.mediaAttributesSortable = {
    attach: function (context, settings) {
      // 1. D'abord, essayer d'initialiser via Entity Browser (si disponible)
      if (Drupal.behaviors.entityBrowserEntityReference) {
        console.log("Tentative d'initialisation via Entity Browser");
        Drupal.behaviors.entityBrowserEntityReference.attach(context, settings);
      }

      // 2. Ensuite, utiliser Sortable.js directement pour s'assurer que ça fonctionne
      once(
        "media-attributes-sortable",
        "[data-entity-browser-entity-reference-list]",
        context
      ).forEach(function (container) {
        console.log("Initialisation avec Sortable.js");

        // Créer une instance Sortable avec la bibliothèque Sortable.js
        let checkedIds = [];
        Sortable.create(container, {
          animation: 150,
          draggable: ".draggable",
          multiDrag: true,
          selectedClass: "is-selected",
          onStart: function () {
            console.log('[Sortable] onStart');
            checkedIds = [];
            container.querySelectorAll('.draggable').forEach(function (item) {
              const checkbox = item.querySelector('input[type="checkbox"]');
              if (checkbox && checkbox.checked) {
                checkedIds.push(item.getAttribute('data-entity-id'));
                checkbox.checked = false;
              }
            });
            console.log('[Sortable] checkedIds at start:', checkedIds);
          },
          onEnd: function (evt) {
            console.log('[Sortable] onEnd');
            // Après le drag & drop, mettre à jour le champ target_id
            const items = [];
            container.querySelectorAll(".draggable").forEach(function (item) {
              const entityId = item.getAttribute("data-entity-id");
              if (entityId) {
                items.push(entityId);
              }
            });
            // Filtrer les doublons
            const uniqueItems = [...new Set(items)];
            console.log('[Sortable] unique items order after drag:', uniqueItems);

            // Trouver le champ cible
            const details = container.closest("details");
            if (!details) return;

            // Recherche le champ caché target_id dans ce details
            let targetField = details.querySelector(
              'input[name="field_album_multimedia_media[target_id]"]'
            );

            // Fallback global si non trouvé (optionnel)
            if (!targetField) {
              targetField = document.querySelector(
                'input[type="hidden"][id$="target-id"]'
              );
            }

            console.log(
              "Mise à jour du champ cible:",
              targetField ? targetField.id : "(champ non trouvé)"
            );

            if (targetField) {
              // Mettre à jour la valeur sans doublons
              targetField.value = uniqueItems.join(" ");
              console.log("Ordre mis à jour: " + uniqueItems.join(" "));

              // Déclencher un événement pour informer Drupal, en passant l'ordre et la sélection
              const event = new CustomEvent("entity_browser_value_updated", {
                detail: {
                  order: uniqueItems,
                  selected: checkedIds
                }
              });
              targetField.dispatchEvent(event);
            } else {
              console.error("Champ target_id non trouvé: " + targetField);
            }

            // Appeler la fonction globale pour resynchroniser l'état visuel
            if (typeof Drupal.mediaAttributesSyncSelection === "function") {
              Drupal.mediaAttributesSyncSelection(container);
            }
          },
        });
      });
    },
  };
})(Drupal, once);
