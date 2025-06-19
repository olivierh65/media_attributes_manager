(function ($, Drupal, once) {
  Drupal.behaviors.mediaAttributesBulkButtons = {
    attach: function (context, settings) {
      function updateBulkButtons() {
        var checked =
          $(".media-item-select-checkbox:checked", context).length > 0;
        $(".bulk-edit-button, .bulk-remove-button", context).prop(
          "disabled",
          !checked
        );
      }
      $(once("bulk-buttons", ".media-item-select-checkbox", context)).on(
        "change",
        updateBulkButtons
      );
      updateBulkButtons();

      $(once("bulk-edit-modal", ".bulk-edit-button", context)).on(
        "click",
        function (e) {
          e.preventDefault();
          var mediaData = {};
          $(".media-item-select-checkbox:checked", context).each(function () {
            var $imgDiv = $(this)
              .closest(".item-container")
              .find(".media-library-item__image");
            var mediaId = $imgDiv.data("media-id");
            if (mediaId) {
              var data = {};
              $.each($imgDiv[0].attributes, function () {
                if (this.name.indexOf("data-media-") === 0) {
                  var key = this.name
                    .replace("data-media-", "")
                    .replace(/-([a-z])/g, function (g) {
                      return g[1].toUpperCase();
                    });
                  data[key] = this.value;
                }
              });
              mediaData[mediaId] = data;
            }
          });
          if (Object.keys(mediaData).length) {
            // 1. Création d'un élément factice pour l'initialisation AJAX de Drupal
            var $dummyElement = $("<div></div>");

            // 2. Configuration AJAX spécifique à Drupal
            var ajaxSettings = {
              url: Drupal.url("media-attributes/bulk-edit-modal"),
              base: false,
              element: $dummyElement.get(0),
              submit: {
                js: true,
                // Vos données doivent être dans l'objet 'submit'
                mediaData: JSON.stringify(mediaData),
                ajax_form: 1,
              },
              dialogType: "modal",
              dialog: {
                width: "70%",
              },
            };

            // Crée et exécute la requête AJAX via le système Drupal
            var myAjax = Drupal.ajax(ajaxSettings);
            myAjax.execute();
          }
        }
      );
    },
  };
})(jQuery, Drupal, once);
