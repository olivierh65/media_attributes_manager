(function ($, Drupal, once) {
  "use strict";



  jQuery(document).ready(function ($) {
    $(document).on("dialog:aftercreate", function (event, dialog, $element) {
      $(".bulk-edit-select2").select2({
        // attache le script au parent du modal pour éviter les problèmes de positionnement
        dropdownParent: $element.closest(".ui-dialog"),
        tags: true,
        createTag: function (params) {
          // Permet la création de n'importe quel texte comme nouveau tag
          return {
            id: params.term,
            text: params.term,
            newTag: true,
          };
        },
      });
    });
  });
})(jQuery, Drupal, once);
