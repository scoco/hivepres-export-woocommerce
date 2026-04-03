# Hivepress → WooCommerce CSV Export

Script PHP standalone permettant d'exporter les annonces **Hivepress** au format CSV compatible avec l'**import natif WooCommerce** (Products > Import).

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759B?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6%2B-96588A?logo=woocommerce&logoColor=white)
![Hivepress](https://img.shields.io/badge/Hivepress-1.x-FFAB00)
![License](https://img.shields.io/badge/License-MIT-green)

---

## Fonctionnalités

- ✅ Interface HTML avec aperçu des annonces avant export
- ✅ CSV avec BOM UTF-8 (compatible Excel sans manipulation)
- ✅ Colonnes natives WooCommerce (prêt à importer sans mapping)
- ✅ Catégories hiérarchiques (`Parent > Enfant`)
- ✅ Images : featured image + galerie
- ✅ Gestion des types de prix Hivepress (`fixed`, `negotiable`, `free`, `contact`)
- ✅ Metas custom exportées (`hp_location`, `hp_price_type`, `hp_user`…)
- ✅ Pagination par batch pour les gros volumes
- ✅ Filtres : statut, type de produit WooCommerce, taille de page
- ✅ Protégé par token secret

---

## Installation

1. **Cloner ou télécharger** le fichier `hivepress-export.php`

2. **Ouvrir le fichier** et changer le token par défaut :

```php
define( 'HP_EXPORT_TOKEN', 'CHANGE_ME_SECRET_TOKEN' );
```

3. **Déposer le fichier** à la racine de votre installation WordPress (même niveau que `wp-config.php`)

4. **Accéder** via navigateur :

```
https://monsite.com/hivepress-export.php?token=MON_TOKEN_SECRET
```

> ⚠️ **Supprimer le fichier après usage.** Il n'est protégé que par le token.

---

## Utilisation

### Interface web

L'interface affiche :
- Le nombre total d'annonces trouvées
- Un tableau de prévisualisation avec miniatures, prix, catégories, localisation
- Des filtres (statut, type WooCommerce, taille de batch)
- Des boutons de téléchargement CSV

### Paramètres d'URL

| Paramètre | Valeurs | Défaut | Description |
|---|---|---|---|
| `token` | string | — | **Obligatoire.** Token de sécurité |
| `status` | `publish`, `pending`, `draft`, `any` | `publish` | Statut des annonces |
| `type` | `simple`, `external` | `simple` | Type de produit WooCommerce créé |
| `batch` | entier | `500` | Nombre d'annonces par page |
| `page` | entier | `1` | Page courante |
| `download` | _(présence suffit)_ | — | Force le téléchargement CSV |

**Exemple — télécharger directement toutes les annonces publiées :**

```
https://monsite.com/hivepress-export.php?token=MON_TOKEN&status=publish&batch=1000&page=1&download
```

---

## Colonnes du CSV exporté

### Colonnes WooCommerce natives

| Colonne | Source |
|---|---|
| `Type` | Paramètre `type` (simple / external) |
| `SKU` | Généré automatiquement : `HP-000123` |
| `Name` | `post_title` du listing |
| `Description` | `post_content` (texte brut) |
| `Short description` | `post_excerpt` ou troncature automatique |
| `Regular price` | Meta `hp_price` (si type = fixed) |
| `Categories` | Taxonomie `hp_listing_category` (hiérarchique) |
| `Tags` | Taxonomie `hp_listing_tag` |
| `Images` | Featured image + galerie (URLs séparées par virgule) |
| `Published` | `1` si publié, `0` sinon |
| `External URL` | Permalink de l'annonce Hivepress |
| `Button text` | `"Voir l'annonce"` |

### Metas custom

| Colonne | Description |
|---|---|
| `Meta: hp_listing_id` | ID WordPress du listing |
| `Meta: hp_price_type` | `fixed`, `negotiable`, `free` ou `contact` |
| `Meta: hp_location` | Adresse / localisation |
| `Meta: hp_user` | Nom et email de l'auteur |
| `Meta: hp_verified` | `1` si annonce vérifiée |

---

## Import dans WooCommerce

1. Aller dans **WooCommerce > Produits > Importer**
2. Sélectionner le fichier CSV exporté
3. Vérifier le mapping des colonnes (automatique si noms identiques)
4. Lancer l'import

> Les colonnes `Meta: *` seront importées comme méta-données produit WooCommerce et visibles dans l'onglet **Custom Fields** de chaque produit.

---

## Personnalisation

### Ajouter un champ custom Hivepress

1. Ajouter la colonne dans le tableau `$woo_columns` :

```php
$woo_columns = [
    // ... colonnes existantes ...
    'Meta: hp_mon_champ',
];
```

2. Ajouter la valeur dans la construction de `$row` :

```php
$row = [
    // ... valeurs existantes ...
    'Meta: hp_mon_champ' => get_post_meta( $pid, 'hp_mon_champ', true ),
];
```

### Modifier la logique de SKU

```php
function hp_generate_sku( int $post_id ): string {
    // Exemple : utiliser un meta existant
    $custom_ref = get_post_meta( $post_id, 'hp_reference', true );
    return $custom_ref ?: 'HP-' . str_pad( $post_id, 6, '0', STR_PAD_LEFT );
}
```

### Gros volumes (> 2000 annonces)

Exporter par batch via plusieurs appels successifs :

```bash
# Batch 1
curl "https://monsite.com/hivepress-export.php?token=MON_TOKEN&batch=500&page=1&download" -o export-p1.csv

# Batch 2
curl "https://monsite.com/hivepress-export.php?token=MON_TOKEN&batch=500&page=2&download" -o export-p2.csv
```

Puis fusionner les CSV (en conservant l'en-tête du premier fichier uniquement).

---

## Prérequis

- WordPress 5.8+
- Plugin **Hivepress** actif
- Plugin **WooCommerce** actif (pour l'import)
- PHP 7.4+
- Accès FTP/SSH pour déposer le fichier

---

## Sécurité

- Le fichier est protégé par un **token secret** à définir avant usage
- Il ne fait aucune écriture en base de données (lecture seule)
- **À supprimer impérativement après usage**
- Ne pas versionner avec un token réel dans le code

---

## Licence

MIT — Libre d'utilisation, modification et distribution.

---

## Contribution

Les PR sont les bienvenues, notamment pour :
- Support des annonces avec attributs (produits variables WooCommerce)
- Export multi-sites
- Filtrage par catégorie ou auteur
- Génération d'un log d'import
