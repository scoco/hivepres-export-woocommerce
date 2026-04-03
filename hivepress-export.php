<?php

/**
 * Hivepress → WooCommerce CSV Export
 *
 * Déposer à la racine WordPress et accéder via :
 * https://monsite.com/hivepress-export.php
 *
 * Supporte : annonces simples, prix, catégories, tags, images, meta custom.
 * Compatible avec l'import natif WooCommerce (Products > Import).
 *
 * ⚠️  SUPPRIMER ce fichier après usage (accès non protégé).
 */

// ─── Sécurité minimale ──────────────────────────────────────────────────────
define('HP_EXPORT_TOKEN', 'Xkw4aXMlB49awk0x8qdBgXu030jy7zeKxPTJeaIP05IZ5CPxbBYJpW02OoFX'); // Remplacer avant usage

if (! isset($_GET['token']) || $_GET['token'] !== HP_EXPORT_TOKEN) {
  http_response_code(403);
  die('Accès refusé. Ajoutez ?token=CHANGE_ME_SECRET_TOKEN à l\'URL.');
}

// ─── Bootstrap WordPress ────────────────────────────────────────────────────
define('DOING_AJAX', true); // évite certains redirects
require_once __DIR__ . '/wp-load.php';

if (! class_exists('HivePress\Core')) {
  die('Hivepress n\'est pas actif sur ce site.');
}

// ─── Paramètres ─────────────────────────────────────────────────────────────
$batch_size    = isset($_GET['batch'])  ? (int) $_GET['batch']  : 500;
$product_type  = isset($_GET['type'])   ? sanitize_key($_GET['type']) : 'simple';
$status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'publish';
$do_download   = isset($_GET['download']); // ?download pour forcer le téléchargement

// ─── Colonnes WooCommerce CSV ────────────────────────────────────────────────
// Ordre identique à celui attendu par l'import natif WooCommerce.
$woo_columns = [
  'ID',
  'Type',
  'SKU',
  'Name',
  'Published',
  'Is featured?',
  'Visibility in catalog',
  'Short description',
  'Description',
  'Date sale price starts',
  'Date sale price ends',
  'Tax status',
  'Tax class',
  'In stock?',
  'Stock',
  'Backorders allowed?',
  'Sold individually?',
  'Weight (kg)',
  'Length (cm)',
  'Width (cm)',
  'Height (cm)',
  'Allow customer reviews?',
  'Purchase note',
  'Sale price',
  'Regular price',
  'Categories',
  'Tags',
  'Shipping class',
  'Images',
  'Download limit',
  'Download expiry',
  'Parent',
  'Grouped products',
  'Upsells',
  'Cross-sells',
  'External URL',
  'Button text',
  'Position',
  // Attributs custom Hivepress exportés en colonnes meta:
  'Meta: hp_listing_id',
  'Meta: hp_price_type',
  'Meta: hp_location',
  'Meta: hp_user',
  'Meta: hp_verified',
];

// ─── Requête listings Hivepress ──────────────────────────────────────────────
$query_args = [
  'post_type'      => 'hp_listing',
  'post_status'    => $status_filter,
  'posts_per_page' => $batch_size,
  'paged'          => max(1, (int) ($_GET['page'] ?? 1)),
  'orderby'        => 'date',
  'order'          => 'DESC',
];

$query    = new WP_Query($query_args);
$listings = $query->posts;
$total    = $query->found_posts;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Retourne l'URL de l'image mise en avant d'un post.
 */
function hp_get_featured_image_url(int $post_id): string
{
  $thumb_id = get_post_thumbnail_id($post_id);
  if (! $thumb_id) {
    return '';
  }
  $src = wp_get_attachment_url($thumb_id);
  return $src ?: '';
}

/**
 * Retourne toutes les images galerie d'un listing Hivepress.
 * Hivepress stocke les IDs d'images dans hp_images (tableau sérialisé ou IDs attachments).
 */
function hp_get_gallery_image_urls(int $post_id): array
{
  $urls = [];

  // Méthode 1 : meta hp_images (array d'IDs)
  $image_ids = get_post_meta($post_id, 'hp_images', true);
  if (is_array($image_ids) && ! empty($image_ids)) {
    foreach ($image_ids as $id) {
      $url = wp_get_attachment_url((int) $id);
      if ($url) {
        $urls[] = $url;
      }
    }
    return $urls;
  }

  // Méthode 2 : attachments enfants du post
  $attachments = get_posts([
    'post_type'      => 'attachment',
    'post_parent'    => $post_id,
    'posts_per_page' => 20,
    'post_status'    => 'inherit',
    'fields'         => 'ids',
  ]);

  foreach ($attachments as $att_id) {
    if ((int) $att_id === (int) get_post_thumbnail_id($post_id)) {
      continue; // image mise en avant déjà gérée
    }
    $url = wp_get_attachment_url($att_id);
    if ($url) {
      $urls[] = $url;
    }
  }

  return $urls;
}

/**
 * Retourne les termes d'une taxonomie sous forme de chaîne WooCommerce.
 * Catégories : "Parent > Enfant, Autre"
 * Tags : "tag1, tag2"
 */
function hp_get_taxonomy_string(int $post_id, string $taxonomy, bool $hierarchical = false): string
{
  $terms = get_the_terms($post_id, $taxonomy);
  if (! $terms || is_wp_error($terms)) {
    return '';
  }

  if (! $hierarchical) {
    return implode(', ', wp_list_pluck($terms, 'name'));
  }

  // Construction des chemins hiérarchiques (ex. "Immobilier > Appartements")
  $paths = [];
  foreach ($terms as $term) {
    $ancestors = array_reverse(get_ancestors($term->term_id, $taxonomy, 'taxonomy'));
    $path_parts = [];
    foreach ($ancestors as $ancestor_id) {
      $ancestor = get_term($ancestor_id, $taxonomy);
      if ($ancestor && ! is_wp_error($ancestor)) {
        $path_parts[] = $ancestor->name;
      }
    }
    $path_parts[] = $term->name;
    $paths[]      = implode(' > ', $path_parts);
  }

  return implode(', ', $paths);
}

/**
 * Retourne le nom d'affichage de l'auteur du listing.
 */
function hp_get_listing_author(int $post_id): string
{
  $post   = get_post($post_id);
  $author = get_userdata($post->post_author);
  return $author ? $author->display_name . ' (' . $author->user_email . ')' : '';
}

/**
 * Génère un SKU unique basé sur l'ID du listing.
 */
function hp_generate_sku(int $post_id): string
{
  return 'HP-' . str_pad($post_id, 6, '0', STR_PAD_LEFT);
}

// ─── Construction des lignes CSV ─────────────────────────────────────────────

$rows = [];

foreach ($listings as $listing) {
  $pid = $listing->ID;

  // Prix
  $price      = get_post_meta($pid, 'hp_price', true);
  $price_type = get_post_meta($pid, 'hp_price_type', true); // fixed | negotiable | free | contact

  // Normalisation : si price_type n'est pas "fixed", on ne met pas de prix fixe
  $regular_price = '';
  if (in_array($price_type, ['fixed', '', null], true) && $price !== '' && $price !== false) {
    $regular_price = number_format((float) $price, 2, '.', '');
  }

  // Images
  $featured_url  = hp_get_featured_image_url($pid);
  $gallery_urls  = hp_get_gallery_image_urls($pid);
  $all_image_urls = array_filter(array_merge([$featured_url], $gallery_urls));
  $images_str    = implode(', ', $all_image_urls);

  // Catégories & tags
  $categories = hp_get_taxonomy_string($pid, 'hp_listing_category', true);
  $tags       = hp_get_taxonomy_string($pid, 'hp_listing_tag', false);

  // Localisation
  $location = get_post_meta($pid, 'hp_location', true);
  if (is_array($location)) {
    // Certaines versions stockent {address, latitude, longitude}
    $location = $location['address'] ?? implode(', ', $location);
  }

  // Statut WooCommerce (1 = publié, 0 = brouillon)
  $published = ($listing->post_status === 'publish') ? 1 : 0;

  // Description courte : extrait WordPress ou les 150 premiers caractères
  $short_desc = $listing->post_excerpt
    ?: wp_trim_words(wp_strip_all_tags($listing->post_content), 25, '…');

  // ── Ligne formatée ────────────────────────────────────────────────────────
  $row = [
    'ID'                    => '', // vide = création dans WooCommerce
    'Type'                  => $product_type,
    'SKU'                   => hp_generate_sku($pid),
    'Name'                  => $listing->post_title,
    'Published'             => $published,
    'Is featured?'          => 0,
    'Visibility in catalog' => 'visible',
    'Short description'     => $short_desc,
    'Description'           => wp_strip_all_tags($listing->post_content),
    'Date sale price starts' => '',
    'Date sale price ends'  => '',
    'Tax status'            => 'none',
    'Tax class'             => '',
    'In stock?'             => 1,
    'Stock'                 => '',
    'Backorders allowed?'   => 0,
    'Sold individually?'    => 1,
    'Weight (kg)'           => '',
    'Length (cm)'           => '',
    'Width (cm)'            => '',
    'Height (cm)'           => '',
    'Allow customer reviews?' => 0,
    'Purchase note'         => '',
    'Sale price'            => '',
    'Regular price'         => $regular_price,
    'Categories'            => $categories,
    'Tags'                  => $tags,
    'Shipping class'        => '',
    'Images'                => $images_str,
    'Download limit'        => '',
    'Download expiry'       => '',
    'Parent'                => '',
    'Grouped products'      => '',
    'Upsells'               => '',
    'Cross-sells'           => '',
    'External URL'          => get_permalink($pid),
    'Button text'           => 'Voir l\'annonce',
    'Position'              => 0,
    // Metas custom
    'Meta: hp_listing_id'   => $pid,
    'Meta: hp_price_type'   => $price_type,
    'Meta: hp_location'     => $location,
    'Meta: hp_user'         => hp_get_listing_author($pid),
    'Meta: hp_verified'     => get_post_meta($pid, 'hp_verified', true) ? 1 : 0,
  ];

  $rows[] = $row;
}

// ─── Sortie ──────────────────────────────────────────────────────────────────

if ($do_download) {
  // Export direct en téléchargement
  $filename = 'hivepress-export-' . date('Y-m-d-His') . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Pragma: no-cache');
  header('Expires: 0');

  $out = fopen('php://output', 'w');
  fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 pour Excel
  fputcsv($out, $woo_columns);
  foreach ($rows as $row) {
    fputcsv($out, array_values($row));
  }
  fclose($out);
  exit;
} else {
  // Interface HTML
  $current_page = (int) ($_GET['page'] ?? 1);
  $total_pages  = ceil($total / $batch_size);
  $base_url     = strtok($_SERVER['REQUEST_URI'], '?');
  $token_param  = 'token=' . urlencode(HP_EXPORT_TOKEN);
?>
  <!DOCTYPE html>
  <html lang="fr">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hivepress → WooCommerce Export</title>
    <style>
      *,
      *::before,
      *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
      }

      body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #f0f2f5;
        color: #1a1a2e;
        min-height: 100vh;
        padding: 2rem;
      }

      .wrap {
        max-width: 960px;
        margin: 0 auto;
      }

      h1 {
        font-size: 1.6rem;
        font-weight: 700;
        margin-bottom: .25rem;
      }

      .subtitle {
        color: #666;
        margin-bottom: 2rem;
        font-size: .9rem;
      }

      .card {
        background: #fff;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, .08);
      }

      .card h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        border-bottom: 1px solid #eee;
        padding-bottom: .5rem;
      }

      .stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .stat {
        background: #f8f9fa;
        border-radius: 8px;
        padding: .9rem 1rem;
        text-align: center;
      }

      .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: #0073aa;
        line-height: 1;
      }

      .stat-label {
        font-size: .75rem;
        color: #777;
        margin-top: .25rem;
      }

      .btn {
        display: inline-block;
        padding: .6rem 1.2rem;
        border-radius: 6px;
        font-size: .9rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        border: none;
      }

      .btn-primary {
        background: #0073aa;
        color: #fff;
      }

      .btn-primary:hover {
        background: #005d8c;
      }

      .btn-secondary {
        background: #e8f0fe;
        color: #1a73e8;
      }

      .btn-secondary:hover {
        background: #d2e3fc;
      }

      .btn+.btn {
        margin-left: .5rem;
      }

      .filter-form {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: flex-end;
      }

      .filter-form label {
        display: flex;
        flex-direction: column;
        gap: .25rem;
        font-size: .8rem;
        font-weight: 600;
        color: #555;
      }

      .filter-form select,
      .filter-form input {
        padding: .4rem .6rem;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: .85rem;
      }

      table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
      }

      th {
        background: #f5f7fa;
        text-align: left;
        padding: .6rem .75rem;
        font-weight: 600;
        color: #444;
        border-bottom: 2px solid #e0e0e0;
      }

      td {
        padding: .5rem .75rem;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: top;
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      tr:hover td {
        background: #fafbfc;
      }

      .badge {
        display: inline-block;
        padding: .15rem .5rem;
        border-radius: 20px;
        font-size: .72rem;
        font-weight: 600;
      }

      .badge-green {
        background: #e6f4ea;
        color: #1e7e34;
      }

      .badge-gray {
        background: #f0f0f0;
        color: #666;
      }

      .badge-orange {
        background: #fff3e0;
        color: #e65100;
      }

      .pagination {
        display: flex;
        gap: .4rem;
        align-items: center;
        flex-wrap: wrap;
        margin-top: 1rem;
      }

      .pagination a,
      .pagination span {
        padding: .35rem .7rem;
        border-radius: 5px;
        font-size: .82rem;
        text-decoration: none;
        background: #f0f0f0;
        color: #333;
      }

      .pagination a:hover {
        background: #ddd;
      }

      .pagination .current {
        background: #0073aa;
        color: #fff;
        font-weight: 700;
      }

      .warn {
        background: #fff8e1;
        border-left: 4px solid #ffc107;
        padding: .75rem 1rem;
        border-radius: 0 6px 6px 0;
        font-size: .85rem;
        margin-bottom: 1rem;
      }

      img.thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
      }

      .no-image {
        width: 40px;
        height: 40px;
        background: #eee;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #aaa;
        font-size: .7rem;
      }
    </style>
  </head>

  <body>
    <div class="wrap">
      <h1>🐝 Hivepress → WooCommerce Export</h1>
      <p class="subtitle">Export des annonces Hivepress au format CSV compatible avec l'import natif WooCommerce.</p>

      <div class="warn">
        ⚠️ <strong>Sécurité :</strong> Supprimez ce fichier après usage. Il expose des données publiquement (protégé uniquement par le token).
      </div>

      <!-- Statistiques -->
      <div class="card">
        <div class="stats">
          <div class="stat">
            <div class="stat-value"><?php echo number_format($total); ?></div>
            <div class="stat-label">Annonces trouvées</div>
          </div>
          <div class="stat">
            <div class="stat-value"><?php echo number_format(count($rows)); ?></div>
            <div class="stat-label">Dans cette page</div>
          </div>
          <div class="stat">
            <div class="stat-value"><?php echo $total_pages; ?></div>
            <div class="stat-label">Pages (batch <?php echo $batch_size; ?>)</div>
          </div>
          <div class="stat">
            <div class="stat-value"><?php echo count($woo_columns); ?></div>
            <div class="stat-label">Colonnes CSV</div>
          </div>
        </div>
      </div>

      <!-- Filtres + téléchargement -->
      <div class="card">
        <h2>⚙️ Paramètres d'export</h2>
        <form method="GET" class="filter-form">
          <input type="hidden" name="token" value="<?php echo esc_attr(HP_EXPORT_TOKEN); ?>">
          <label>
            Statut
            <select name="status">
              <?php foreach (['publish', 'pending', 'draft', 'any'] as $s) : ?>
                <option value="<?php echo $s; ?>" <?php selected($status_filter, $s); ?>><?php echo $s; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Type WooCommerce
            <select name="type">
              <?php foreach (['simple', 'external'] as $t) : ?>
                <option value="<?php echo $t; ?>" <?php selected($product_type, $t); ?>><?php echo $t; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Annonces par batch
            <input type="number" name="batch" value="<?php echo $batch_size; ?>" min="1" max="2000" style="width:90px">
          </label>
          <label>
            Page
            <input type="number" name="page" value="<?php echo $current_page; ?>" min="1" max="<?php echo $total_pages; ?>" style="width:70px">
          </label>
          <button type="submit" class="btn btn-secondary">🔄 Filtrer</button>
        </form>

        <div style="margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap;">
          <!-- Téléchargement page courante -->
          <a href="?<?php echo $token_param; ?>&status=<?php echo $status_filter; ?>&type=<?php echo $product_type; ?>&batch=<?php echo $batch_size; ?>&page=<?php echo $current_page; ?>&download"
            class="btn btn-primary">
            ⬇️ Télécharger cette page (CSV)
          </a>
          <!-- Téléchargement de tout -->
          <?php if ($total <= 2000) : ?>
            <a href="?<?php echo $token_param; ?>&status=<?php echo $status_filter; ?>&type=<?php echo $product_type; ?>&batch=<?php echo $total; ?>&page=1&download"
              class="btn btn-primary">
              ⬇️ Télécharger tout (<?php echo $total; ?> annonces)
            </a>
          <?php else : ?>
            <span style="font-size:.82rem;color:#999;align-self:center;">Plus de 2000 annonces : utilisez la pagination pour exporter par batch.</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Aperçu tableau -->
      <div class="card">
        <h2>👁 Aperçu des données exportées</h2>
        <?php if (empty($rows)) : ?>
          <p style="color:#999; padding:1rem 0;">Aucune annonce trouvée avec ce statut.</p>
        <?php else : ?>
          <div style="overflow-x:auto;">
            <table>
              <thead>
                <tr>
                  <th>Image</th>
                  <th>SKU</th>
                  <th>Nom</th>
                  <th>Prix</th>
                  <th>Type prix</th>
                  <th>Catégories</th>
                  <th>Tags</th>
                  <th>Statut</th>
                  <th>Localisation</th>
                  <th>Images</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row) :
                  $img_url    = strtok($row['Images'], ',');
                  $price_type = $row['Meta: hp_price_type'];
                  $badge_cls  = match ($price_type) {
                    'free'       => 'badge-green',
                    'negotiable' => 'badge-orange',
                    'contact'    => 'badge-gray',
                    default      => 'badge-green',
                  };
                ?>
                  <tr>
                    <td>
                      <?php if ($img_url) : ?>
                        <img class="thumb" src="<?php echo esc_url(trim($img_url)); ?>" alt="" loading="lazy">
                      <?php else : ?>
                        <span class="no-image">–</span>
                      <?php endif; ?>
                    </td>
                    <td style="font-family:monospace; color:#888;"><?php echo esc_html($row['SKU']); ?></td>
                    <td title="<?php echo esc_attr($row['Name']); ?>"><?php echo esc_html($row['Name']); ?></td>
                    <td><?php echo $row['Regular price'] !== '' ? esc_html($row['Regular price']) . ' €' : '<span style="color:#aaa">–</span>'; ?></td>
                    <td>
                      <span class="badge <?php echo $badge_cls; ?>">
                        <?php echo esc_html($price_type ?: 'fixed'); ?>
                      </span>
                    </td>
                    <td title="<?php echo esc_attr($row['Categories']); ?>"><?php echo esc_html($row['Categories']); ?></td>
                    <td title="<?php echo esc_attr($row['Tags']); ?>"><?php echo esc_html($row['Tags']); ?></td>
                    <td>
                      <?php if ($row['Published']) : ?>
                        <span class="badge badge-green">publié</span>
                      <?php else : ?>
                        <span class="badge badge-gray">brouillon</span>
                      <?php endif; ?>
                    </td>
                    <td title="<?php echo esc_attr($row['Meta: hp_location']); ?>"><?php echo esc_html($row['Meta: hp_location']); ?></td>
                    <td><?php echo count(array_filter(explode(', ', $row['Images']))); ?> img</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($total_pages > 1) : ?>
            <div class="pagination">
              <span>Page :</span>
              <?php
              $max_show = 10;
              $start    = max(1, $current_page - 5);
              $end      = min($total_pages, $start + $max_show - 1);
              for ($p = $start; $p <= $end; $p++) :
                $url = sprintf(
                  '?%s&status=%s&type=%s&batch=%d&page=%d',
                  $token_param,
                  $status_filter,
                  $product_type,
                  $batch_size,
                  $p
                );
              ?>
                <?php if ($p === $current_page) : ?>
                  <span class="current"><?php echo $p; ?></span>
                <?php else : ?>
                  <a href="<?php echo esc_url($url); ?>"><?php echo $p; ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($end < $total_pages) : ?>
                <span>…</span>
                <a href="?<?php echo $token_param; ?>&status=<?php echo $status_filter; ?>&type=<?php echo $product_type; ?>&batch=<?php echo $batch_size; ?>&page=<?php echo $total_pages; ?>">
                  <?php echo $total_pages; ?>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <p style="font-size:.78rem; color:#aaa; text-align:center; margin-top:1rem;">
        Généré par <strong>hivepress-export.php</strong> —
        <?php echo gmdate('Y-m-d H:i:s'); ?> UTC
      </p>
    </div>
  </body>

  </html>
<?php
}
