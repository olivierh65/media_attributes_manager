# Media Attributes Manager - Widget Bulk Edit

## Vue d'ensemble

Ce module implémente un widget Drupal avancé pour la gestion de médias avec les fonctionnalités suivantes :

### ✅ Fonctionnalités Implémentées

1. **Drag & Drop** - Réorganisation des médias par glisser-déposer avec Sortable.js
2. **Sélection Multiple** - Checkboxes pour sélectionner plusieurs médias
3. **Bulk Edit** - Édition en masse des attributs des médias sélectionnés
4. **Autocomplétion** - Fonctionne correctement dans les modales AJAX
5. **Filtrage Intelligent** - N'affiche que les champs pertinents pour l'édition

## Architecture

### Fichiers Principaux

- `src/Plugin/Field/FieldWidget/MediaAttributesWidget.php` - Widget principal
- `src/Form/BulkEditMediaAttributesForm.php` - Formulaire d'édition en masse
- `src/Traits/CustomFieldsTrait.php` - Logique de gestion des champs custom
- `js/bulk-edit-autocomplete-fix.js` - Correction autocomplétion dans modales
- `js/media-attributes-sortable.js` - Fonctionnalité drag & drop
- `js/media-attributes-selection.js` - Gestion des checkboxes

## Problèmes Résolus

### 1. Autocomplétion dans les Modales AJAX
**Problème** : L'autocomplétion ne fonctionnait pas à la première ouverture de la modale bulk edit.

**Solution** : Script JavaScript `bulk-edit-autocomplete-fix.js` qui :
- Détecte l'ouverture des modales avec `MutationObserver`
- Force l'initialisation des widgets d'autocomplétion
- Utilise plusieurs méthodes de fallback (Drupal.autocomplete + jQuery UI manuel)
- Copie les `selection_handler` et `selection_settings` depuis les définitions de champs

### 2. Gestion des Valeurs par Défaut
**Problème** : Erreurs 500 lors de l'ouverture du bulk edit avec plusieurs médias.

**Solution** : Logique différenciée dans `CustomFieldsTrait.php` :

#### Un Seul Média Sélectionné
- Utilise `buildCustomFieldsForm()` 
- Affiche les valeurs actuelles comme défauts
- Widgets standards avec valeurs pré-remplies

#### Plusieurs Médias Sélectionnés  
- Utilise `buildCustomFieldsFormByBundle()`
- **Champs texte** : Select avec toutes les valeurs uniques trouvées
- **Checkboxes** : Cochée si tous identiques, sinon décochée + message "Valeurs différentes"
- **Entity reference** : Select avec toutes les entités référencées

### 3. Filtrage des Champs
**Problème** : Tous les champs apparaissaient, y compris les champs media principaux.

**Solution** : Filtrage intelligent dans `getCustomFields()` :
- ✅ **Inclus** : Champs custom + description/alt text
- ❌ **Exclus** : Champs media principaux (field_media_image, field_media_video_file, etc.)

### 4. Entity Reference avec IDs vs Objets
**Problème** : Les widgets `entity_autocomplete` attendaient des objets entité, pas des IDs.

**Solution** : Dans `buildCustomFieldWidget()` :
```php
// Charger l'entité à partir de l'ID
$entity_default = \Drupal::entityTypeManager()->getStorage($target_type)->load($default_value);
$widget['#default_value'] = $entity_default;
```

## Configuration des Champs

### Champs Exclus du Bulk Edit
```php
$excluded_fields = [
  'field_media_image',      // Champ image principal
  'field_media_video_file', // Champ vidéo principal  
  'field_media_file',       // Champ fichier principal
  'field_media_document',   // Champ document principal
  'field_media_audio_file', // Champ audio principal
];
```

### Champs Spéciaux Inclus
```php
$included_special_fields = [
  'field_media_image_alt_text',     // Alt text pour images
  'field_media_image_title',        // Title pour images
  'field_am_photo_description',     // Description personnalisée
];
```

## Logique des Checkboxes (Multiple Sélection)

```php
if (count($unique_boolean_values) === 1) {
    // Tous identiques → utiliser la valeur commune
    $field_container[$field_name] = [
        '#default_value' => $common_value,
    ];
} else {
    // Valeurs différentes → décoché + message
    $field_container[$field_name] = [
        '#default_value' => FALSE,
        '#description' => 'Valeurs différentes parmi la sélection',
    ];
}
```

## Scripts JavaScript

### bulk-edit-autocomplete-fix.js
- **Objectif** : Résoudre l'autocomplétion dans les modales
- **Méthodes** : MutationObserver + event listeners + multiple fallbacks
- **Debug** : Logs détaillés (désactivés en production)

### media-attributes-sortable.js  
- **Objectif** : Drag & drop avec Sortable.js
- **Fonctionnalité** : Réorganisation + synchronisation avec Drupal

### media-attributes-selection.js
- **Objectif** : Gestion des checkboxes de sélection
- **Fonctionnalité** : Sélection/désélection + bulk operations

## Développement et Debug

### Activer les Logs de Debug
Dans `bulk-edit-autocomplete-fix.js`, décommenter :
```javascript
function debugLog(message, data) {
    if (console && console.log) {
        console.log('[BulkEditAutocomplete]', message, data || '');
    }
}
```

### Tests Recommandés
1. **Sélection unique** : Vérifier que les valeurs actuelles apparaissent
2. **Sélection multiple** : Vérifier la logique des selects/checkboxes
3. **Autocomplétion** : Tester dès la première ouverture de modale
4. **Entity reference** : Vérifier que les champs Author fonctionnent

## Maintenance Future

### Ajouter un Nouveau Type de Champ
1. Modifier `buildCustomFieldWidget()` dans `CustomFieldsTrait.php`
2. Ajouter la logique dans `buildCustomFieldsFormByBundle()` si nécessaire
3. Tester avec sélection unique et multiple

### Exclure un Champ du Bulk Edit
Ajouter le nom du champ dans `$excluded_fields` dans `getCustomFields()`.

### Inclure un Champ Spécial
Ajouter le nom du champ dans `$included_special_fields` dans `getCustomFields()`.

## Historique des Problèmes

- ❌ **Erreurs 500** : Résolu par gestion correcte des entity_reference
- ❌ **Autocomplétion non fonctionnelle** : Résolu par script JS robuste  
- ❌ **Champs indésirables** : Résolu par filtrage intelligent
- ❌ **Checkboxes confuses** : Résolu par logique de valeurs homogènes
- ✅ **Tout fonctionne maintenant !**
