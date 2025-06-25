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

  // Global function for cleaning stuck queue items
  window.cleanStuckItems = function() {
    if (confirm('This will clean stuck items from the EXIF field creation queue. Continue?')) {
      $.ajax({
        url: Drupal.url('media-attributes/clean-stuck-items'),
        method: 'POST',
        dataType: 'json',
        success: function(response) {
          if (response.success) {
            alert('Cleaned ' + response.cleaned + ' stuck items. You can now try applying EXIF data again.');
            location.reload(); // Reload to update button states
          } else {
            alert('Error cleaning stuck items: ' + (response.message || 'Unknown error'));
          }
        },
        error: function() {
          alert('Error communicating with server. Please try again.');
        }
      });
    }
  };

  // Progress bar management for EXIF processing
  Drupal.behaviors.mediaAttributesExifProgress = {
    attach: function (context, settings) {
      // Handle EXIF apply button with progress
      $(once('exif-progress', '.apply-exif-button', context)).on('click', function(e) {
        var $button = $(this);
        var $form = $button.closest('form');
        
        // Check if this is a regular form submit or if we should handle with AJAX progress
        if ($button.hasClass('use-ajax-progress')) {
          e.preventDefault();
          e.stopPropagation();
          
          // Collect selected media IDs
          var selectedMediaIds = [];
          var fieldName = $button.data('field-name');
          
          console.log('Button field-name:', fieldName);
          console.log('Looking for checkboxes in form:', $form.length);
          
          // Try multiple approaches to find selected checkboxes
          var $checkboxes = [];
          
          // Approach 1: Look for checkboxes with media-item-select-checkbox class
          $checkboxes = $('.media-item-select-checkbox:checked', $form);
          console.log('Approach 1 - Found checkboxes:', $checkboxes.length);
          
          // Approach 2: If fieldName is available, use more specific selector
          if (fieldName && $checkboxes.length === 0) {
            $checkboxes = $('input[name*="[' + fieldName + ']"][name*="[select_checkbox]"]:checked', $form);
            console.log('Approach 2 - Found checkboxes:', $checkboxes.length);
          }
          
          // Approach 3: Look for any checked checkbox in items
          if ($checkboxes.length === 0) {
            $checkboxes = $('input[type="checkbox"]:checked[name*="select_checkbox"]', $form);
            console.log('Approach 3 - Found checkboxes:', $checkboxes.length);
          }
          
          // Extract media IDs from found checkboxes
          $checkboxes.each(function() {
            var $checkbox = $(this);
            var $mediaContainer = $checkbox.closest('.media-library-item, .item-container, [data-entity-id]');
            
            console.log('Processing checkbox:', $checkbox.attr('name'));
            console.log('Container found:', $mediaContainer.length);
            
            if ($mediaContainer.length) {
              var entityId = $mediaContainer.attr('data-entity-id') || $mediaContainer.data('entity-id');
              console.log('Entity ID found:', entityId);
              
              if (entityId) {
                var mediaId = entityId.toString().replace(/^media:/, '');
                if (mediaId && mediaId !== entityId) {
                  selectedMediaIds.push(mediaId);
                  console.log('Added media ID:', mediaId);
                }
              }
            }
            
            // Alternative: try to extract from checkbox name
            if (selectedMediaIds.length === 0) {
              var checkboxName = $checkbox.attr('name');
              if (checkboxName) {
                // Look for patterns like [items][0][buttons][select_checkbox]
                var matches = checkboxName.match(/\[items\]\[(\d+)\]\[buttons\]\[select_checkbox\]/);
                if (matches) {
                  var delta = matches[1];
                  // Find the corresponding media ID in the same row
                  var $row = $checkbox.closest('.media-library-item, .item-container');
                  if ($row.length) {
                    var dataEntityId = $row.attr('data-entity-id') || $row.data('entity-id');
                    if (dataEntityId) {
                      var mediaId = dataEntityId.toString().replace(/^media:/, '');
                      if (mediaId) {
                        selectedMediaIds.push(mediaId);
                        console.log('Added media ID from name pattern:', mediaId);
                      }
                    }
                  }
                }
              }
            }
          });
          
          console.log('Final selected media IDs:', selectedMediaIds);
          
          if (selectedMediaIds.length === 0) {
            alert(Drupal.t('Please select at least one media item to apply EXIF data.'));
            return;
          }
          
          // Start EXIF processing with progress
          Drupal.mediaAttributesExifProgress.startProcessing($button, selectedMediaIds);
        }
        // Otherwise let the normal form submit handle it
      });
    }
  };

  // EXIF Progress management object
  Drupal.mediaAttributesExifProgress = {
    activeSession: null,
    progressInterval: null,
    
    startProcessing: function($button, mediaIds) {
      var self = this;
      
      // Disable the button and show initial progress
      $button.prop('disabled', true);
      self.showProgressBar($button, 0, mediaIds.length);
      
      // Start processing
      $.ajax({
        url: Drupal.url('media-attributes/exif-progress/start'),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
          media_ids: mediaIds,
          session_id: 'exif_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)
        }),
        success: function(response) {
          if (response.success) {
            self.activeSession = response.session_id;
            self.startProgressPolling($button, response.total);
          } else {
            self.handleError($button, response.error || 'Unknown error occurred');
          }
        },
        error: function(xhr, status, error) {
          self.handleError($button, 'Failed to start EXIF processing: ' + error);
        }
      });
    },
    
    startProgressPolling: function($button, total) {
      var self = this;
      
      self.progressInterval = setInterval(function() {
        if (!self.activeSession) {
          clearInterval(self.progressInterval);
          return;
        }
        
        $.ajax({
          url: Drupal.url('media-attributes/exif-progress/status/' + self.activeSession),
          method: 'GET',
          success: function(response) {
            if (response.success) {
              self.updateProgressBar($button, response.current, response.total, response.percent);
              
              if (response.completed) {
                self.completeProcessing($button, response);
              }
            } else {
              self.handleError($button, response.error || 'Progress tracking failed');
            }
          },
          error: function() {
            // Continue polling - temporary network issues shouldn't stop the process
          }
        });
      }, 1000); // Poll every second
    },
    
    showProgressBar: function($button, current, total) {
      var percent = total > 0 ? Math.round((current / total) * 100) : 0;
      var progressHtml = '<div class="exif-progress-container">' +
        '<div class="exif-progress-bar">' +
          '<div class="exif-progress-fill" style="width: ' + percent + '%"></div>' +
        '</div>' +
        '<div class="exif-progress-text">' +
          Drupal.t('Processing EXIF data: @current of @total (@percent%)', {
            '@current': current,
            '@total': total,
            '@percent': percent
          }) +
        '</div>' +
      '</div>';
      
      // Remove any existing progress bar
      $button.siblings('.exif-progress-container').remove();
      
      // Add progress bar after the button
      $button.after(progressHtml);
    },
    
    updateProgressBar: function($button, current, total, percent) {
      var $container = $button.siblings('.exif-progress-container');
      if ($container.length) {
        $container.find('.exif-progress-fill').css('width', percent + '%');
        $container.find('.exif-progress-text').text(
          Drupal.t('Processing EXIF data: @current of @total (@percent%)', {
            '@current': current,
            '@total': total,
            '@percent': percent
          })
        );
      }
    },
    
    completeProcessing: function($button, response) {
      var self = this;
      
      // Clear polling
      clearInterval(self.progressInterval);
      self.activeSession = null;
      
      // Show completion message
      var $container = $button.siblings('.exif-progress-container');
      if ($container.length) {
        var message = '';
        if (response.updated_count > 0) {
          message = Drupal.t('EXIF data applied to @count media item(s) successfully!', {
            '@count': response.updated_count
          });
          $container.find('.exif-progress-text').text(message).addClass('success-message');
        } else {
          message = Drupal.t('No media items were updated. Please check that selected media items have EXIF data.');
          $container.find('.exif-progress-text').text(message).addClass('warning-message');
        }
        
        if (response.errors && response.errors.length > 0) {
          console.warn('EXIF processing errors:', response.errors);
        }
        
        // Remove progress bar after delay
        setTimeout(function() {
          $container.fadeOut(function() {
            $(this).remove();
          });
          $button.prop('disabled', false);
          
          // Refresh the page to show updated media
          if (response.updated_count > 0) {
            location.reload();
          }
        }, 3000);
      } else {
        // Fallback if no progress container
        $button.prop('disabled', false);
        if (response.updated_count > 0) {
          location.reload();
        }
      }
    },
    
    handleError: function($button, errorMessage) {
      var self = this;
      
      // Clear polling
      clearInterval(self.progressInterval);
      self.activeSession = null;
      
      // Show error message
      var $container = $button.siblings('.exif-progress-container');
      if ($container.length) {
        $container.find('.exif-progress-text').text(
          Drupal.t('Error: @message', {'@message': errorMessage})
        ).addClass('error-message');
        
        setTimeout(function() {
          $container.fadeOut(function() {
            $(this).remove();
          });
          $button.prop('disabled', false);
        }, 5000);
      } else {
        alert(Drupal.t('Error: @message', {'@message': errorMessage}));
        $button.prop('disabled', false);
      }
      
      console.error('EXIF Progress Error:', errorMessage);
    }
  };
})(jQuery, Drupal, once);
