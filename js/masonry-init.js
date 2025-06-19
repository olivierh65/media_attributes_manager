(function ($, Drupal, once) {
  Drupal.behaviors.masonryGrid = {
    attach: function (context, settings) {
      $(once('masonry-grid', '.media-library--grid', context)).each(function () {
        console.log('Masonry grid behavior attached.');
        var $grid = $(this);
        $grid.masonry({
          itemSelector: '.item-container.media-library-item--grid',
          percentPosition: true
        });
      });
    }
  };
})(jQuery, Drupal, once);