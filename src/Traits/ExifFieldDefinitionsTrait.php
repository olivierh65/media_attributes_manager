<?php

namespace Drupal\media_attributes_manager\Traits;

/**
 * Trait for EXIF field definitions and constants.
 * 
 * This trait centralizes all EXIF field definitions to ensure consistency
 * across the module and avoid duplication.
 */
trait ExifFieldDefinitionsTrait {

  /**
   * Get the complete list of supported EXIF field keys.
   *
   * @return array
   *   Array of EXIF field keys.
   */
  public static function getExifFieldKeys() {
    return [
      'computed_height',
      'computed_width',
      'make',
      'model',
      'orientation',
      'software',
      'copyright',
      'artist',
      'datetime_original',
      'datetime_digitized',
      'exif_image_width',
      'exif_image_length',
      'exposure',
      'aperture',
      'iso',
      'focal_length',
      'gps_latitude',
      'gps_longitude',
      'gps_altitude',
      'gps_date',
      'gps_coordinates',
    ];
  }

  /**
   * Get the field type mapping for EXIF data.
   *
   * @return array
   *   An array mapping EXIF keys to field types and settings.
   */
  public static function getExifFieldTypeMap() {
    return [
      'computed_height' => ['type' => 'integer'],
      'computed_width' => ['type' => 'integer'],
      'make' => ['type' => 'string'],
      'model' => ['type' => 'string'],
      'orientation' => ['type' => 'integer'],
      'datetime_original' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
      'datetime_digitized' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
      'exif_image_width' => ['type' => 'integer'],
      'exif_image_length' => ['type' => 'integer'],
      'exposure' => ['type' => 'string'],
      'aperture' => ['type' => 'string'],
      'iso' => ['type' => 'integer'],
      'focal_length' => ['type' => 'string'],
      'gps_latitude' => ['type' => 'string'],
      'gps_longitude' => ['type' => 'string'],
      'gps_altitude' => ['type' => 'string'],
      'gps_date' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
      'gps_coordinates' => ['type' => 'string'],
      'software' => ['type' => 'string'],
      'copyright' => ['type' => 'string'],
      'artist' => ['type' => 'string'],
    ];
  }

  /**
   * Get the field label mapping for EXIF data.
   *
   * @return array
   *   An array mapping EXIF keys to human-readable labels.
   */
  public static function getExifFieldLabelMap() {
    return [
      'computed_height' => 'Height (pixels)',
      'computed_width' => 'Width (pixels)',
      'make' => 'Camera Make',
      'model' => 'Camera Model',
      'orientation' => 'Orientation',
      'datetime_original' => 'Date/Time Original',
      'datetime_digitized' => 'Date/Time Digitized',
      'exif_image_width' => 'EXIF Image Width',
      'exif_image_length' => 'EXIF Image Height',
      'exposure' => 'Exposure Time',
      'aperture' => 'Aperture (F-Number)',
      'iso' => 'ISO Speed',
      'focal_length' => 'Focal Length',
      'gps_latitude' => 'GPS Latitude',
      'gps_longitude' => 'GPS Longitude',
      'gps_altitude' => 'GPS Altitude',
      'gps_date' => 'GPS Date/Time',
      'gps_coordinates' => 'GPS Coordinates (formatted)',
      'software' => 'Software',
      'copyright' => 'Copyright',
      'artist' => 'Artist/Author',
    ];
  }

  /**
   * Get EXIF fields grouped by category for form display.
   *
   * @return array
   *   Array of EXIF fields grouped by category.
   */
  public static function getExifFieldsByCategory() {
    return [
      'computed' => [
        'computed_height',
        'computed_width',
      ],
      'ifd0' => [
        'make',
        'model',
        'orientation',
        'software',
        'copyright',
        'artist',
      ],
      'exif' => [
        'datetime_original',
        'datetime_digitized',
        'exif_image_width',
        'exif_image_length',
        'exposure',
        'aperture',
        'iso',
        'focal_length',
      ],
      'gps' => [
        'gps_latitude',
        'gps_longitude',
        'gps_altitude',
        'gps_date',
        'gps_coordinates',
      ],
    ];
  }

  /**
   * Get category labels for form display.
   *
   * @return array
   *   Array of category labels.
   */
  public static function getExifCategoryLabels() {
    return [
      'computed' => 'Basic Image Information',
      'ifd0' => 'Camera Information',
      'exif' => 'EXIF Information',
      'gps' => 'GPS Information',
    ];
  }

  /**
   * Generate a clean field name for an EXIF key.
   *
   * @param string $exif_key
   *   The EXIF key.
   *
   * @return string
   *   The clean field name.
   */
  public static function generateExifFieldName($exif_key) {
    // Remove 'exif_' prefix if already present to avoid duplication
    $clean_exif_key = preg_replace('/^exif_/', '', $exif_key);
    return 'field_exif_' . $clean_exif_key;
  }

  /**
   * Check if a given key is a valid EXIF field key.
   *
   * @param string $exif_key
   *   The EXIF key to check.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public static function isValidExifFieldKey($exif_key) {
    return in_array($exif_key, static::getExifFieldKeys());
  }

  /**
   * Get all EXIF field names (with field_ prefix).
   *
   * @return array
   *   Array of EXIF field names.
   */
  public static function getExifFieldNames() {
    $field_names = [];
    foreach (static::getExifFieldKeys() as $exif_key) {
      $field_names[] = static::generateExifFieldName($exif_key);
    }
    return $field_names;
  }

  /**
   * Extract EXIF key from field name.
   *
   * @param string $field_name
   *   The field name (e.g., 'field_exif_make').
   *
   * @return string|null
   *   The EXIF key or NULL if not a valid EXIF field name.
   */
  public static function extractExifKeyFromFieldName($field_name) {
    if (strpos($field_name, 'field_exif_') === 0) {
      return substr($field_name, strlen('field_exif_'));
    }
    return null;
  }

  /**
   * Check if a field name is an EXIF field.
   *
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if it's an EXIF field, FALSE otherwise.
   */
  public static function isExifFieldName($field_name) {
    $exif_key = static::extractExifKeyFromFieldName($field_name);
    return $exif_key !== null && static::isValidExifFieldKey($exif_key);
  }

  /**
   * Get EXIF data structure for form display with titles and labels.
   *
   * This method provides a complete structure for building form elements
   * with translated titles and field labels.
   *
   * @return array
   *   Array suitable for form building with titles and field descriptions.
   */
  public static function getExifFormStructure() {
    $field_labels = static::getExifFieldLabelMap();
    $fields_by_category = static::getExifFieldsByCategory();
    $category_labels = static::getExifCategoryLabels();
    
    $structure = [];
    foreach ($fields_by_category as $category_key => $field_keys) {
      $structure[$category_key] = [
        'title' => $category_labels[$category_key] ?? ucfirst($category_key),
        'items' => [],
      ];
      
      foreach ($field_keys as $field_key) {
        $structure[$category_key]['items'][$field_key] = $field_labels[$field_key] ?? ucfirst(str_replace('_', ' ', $field_key));
      }
    }
    
    return $structure;
  }

  /**
   * Get just the category keys.
   *
   * @return array
   *   Array of category keys.
   */
  public static function getExifCategoryKeys() {
    return array_keys(static::getExifFieldsByCategory());
  }

}
