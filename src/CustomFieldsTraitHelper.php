<?php

namespace Drupal\media_attributes_manager;

use Drupal\media_attributes_manager\Traits\CustomFieldsTrait;

/**
 * Helper class to use CustomFieldsTrait methods from places like controllers.
 * 
 * This class simply exists to instantiate the trait methods for use
 * outside of classes that directly use the trait.
 */
class CustomFieldsTraitHelper {
  
  use CustomFieldsTrait;
  
}
