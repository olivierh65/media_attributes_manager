(function ($, Drupal, once) {
  'use strict';

  /**
   * Comportement Drupal pour la rotation d'images.
   */
  Drupal.behaviors.mediaImageRotation = {
    attach: function (context, settings) {
      // Attacher l'écouteur d'événement aux boutons de rotation
      $(once('media-rotate-btn', '.media-rotate-btn', context)).on('click', function (e) {
        e.preventDefault();

        var $button = $(this);
        var mediaId = $button.data('media-id');
        var wrapperId = $button.data('wrapper-id');

        if (!mediaId) {
          console.error('Media ID not found for rotation button');
          return;
        }

        // Afficher une animation de rotation sur la vignette
        var $itemContainer = $button.closest('.item-container');
        if ($itemContainer.length) {
          var $image = $itemContainer.find('img').first();
          if ($image.length) {
            // Appliquer une rotation CSS immédiatement pour le feedback utilisateur
            var currentRotation = parseInt($image.data('rotation')) || 0;
            var newRotation = (currentRotation + 90) % 360;

            $image.data('rotation', newRotation);
            $image.css({
              'transform': 'rotate(' + newRotation + 'deg)',
              'transition': 'transform 0.3s ease-in-out'
            });
          }
        }
      });
    }
  };

})(jQuery, Drupal, once);
