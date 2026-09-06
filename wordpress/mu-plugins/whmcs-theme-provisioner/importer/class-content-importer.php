<?php
/**
 * WXR content import.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Security\SafeZip;
use Wtp\Shared\Security\SecurityException;
use Wtp\Shared\Security\UrlGuard;

/**
 * Imports a WordPress eXtended RSS export, resumably.
 *
 * Why not lean on the official `wordpress-importer` plugin: it is built around
 * a single long request with a progress page, and it holds the whole file in
 * memory. Provisioning needs the opposite — many short, unattended passes that
 * can be interrupted at any point and resumed exactly where they stopped.
 *
 * So this walks the file with XMLReader (constant memory regardless of size),
 * handles one `<item>` at a time, and keeps its position plus the source-id to
 * new-id map in the cursor. Three phases:
 *
 *   terms  authors, categories, tags and custom terms  (one pass, small)
 *   items  posts, pages, attachments, menu items       (chunked, resumable)
 *   remap  parents, menus, thumbnails and URL rewriting (one pass)
 *
 * Every created object records its source id in post meta, which is what makes
 * a re-run skip it instead of duplicating it.
 */
final class Wtp_Import_Content_Importer implements Wtp_Importer
{
    private const META_SOURCE_ID = '_wtp_source_id';
    private const META_SOURCE_URL = '_wtp_source_url';
    private const MAP_OPTION_PREFIX = 'wtp_import_map_';

    /** WXR namespaces, by prefix. */
    private const NAMESPACES = [
        'wp' => 'http://wordpress.org/export/1.2/',
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
        'dc' => 'http://purl.org/dc/elements/1.1/',
    ];

    public function key(): string
    {
        return 'content';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return isset($step['asset']) && is_array($step['asset']);
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $asset = $context->asset();
        if ($asset === null) {
            return Wtp_Import_Result::skipped('This demo ships no content file');
        }

        try {
            $file = $this->localFile($context, $asset);
        } catch (Wtp_Security_Exception $e) {
            return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
        }

        $phase = (string) $context->cursorValue('phase', 'terms');

        try {
            if ($phase === 'terms') {
                return $this->importTerms($context, $file);
            }
            if ($phase === 'items') {
                return $this->importItems($context, $file);
            }
            return $this->remap($context, $file);
        } catch (Throwable $e) {
            return Wtp_Import_Result::failed('content_import_failed', $e->getMessage());
        }
    }

    // --------------------------------------------------------------- phases

    private function importTerms(Wtp_Import_Context $context, string $file): Wtp_Import_Result
    {
        if ($context->planOption('delete_default_content', true) && !$context->alreadyDone('defaults')) {
            $this->removeDefaultContent($context);
            $context->markDone('defaults');
        }

        $created = 0;
        $reader = $this->openReader($file);

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                $name = $reader->name;
                if ($name !== 'wp:category' && $name !== 'wp:tag' && $name !== 'wp:term') {
                    continue;
                }

                $node = $this->currentElement($reader);
                if ($node === null) {
                    continue;
                }
                if ($this->createTerm($name, $node)) {
                    $created++;
                }
            }
        } finally {
            $reader->close();
        }

        $context->logger()->info('Imported taxonomy terms', ['created' => $created]);

        return Wtp_Import_Result::progress(
            ['phase' => 'items', 'index' => 0, 'map' => [], 'imported' => 0],
            10,
            'Imported taxonomies'
        );
    }

    private function importItems(Wtp_Import_Context $context, string $file): Wtp_Import_Result
    {
        $start = (int) $context->cursorValue('index', 0);
        $imported = (int) $context->cursorValue('imported', 0);
        $chunkSize = max(5, min(200, (int) $context->option('chunk_size', 40)));
        $fetchAttachments = (bool) $context->option('fetch_attachments', $context->planOption('fetch_attachments', false));

        $map = $this->loadMap($context);

        $index = 0;
        $processed = 0;
        $reachedEnd = false;
        $reader = $this->openReader($file);

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'item') {
                    continue;
                }

                if ($index < $start) {
                    $index++;
                    $reader->next();
                    continue;
                }

                if ($processed >= $chunkSize || !$context->hasTimeLeft(6.0)) {
                    break;
                }

                $node = $this->currentElement($reader);
                $index++;
                $processed++;

                if ($node === null) {
                    continue;
                }

                $result = $this->importItem($context, $node, $map, $fetchAttachments);
                if ($result > 0) {
                    $imported++;
                }
            }

            $reachedEnd = $processed < $chunkSize && $context->hasTimeLeft(6.0);
        } finally {
            $reader->close();
        }

        $this->saveMap($context, $map);

        if (!$reachedEnd) {
            $percent = 10 + (int) min(80, $index / max(1, $index + $chunkSize) * 80);
            return Wtp_Import_Result::progress(
                ['phase' => 'items', 'index' => $index, 'imported' => $imported],
                $percent,
                'Imported ' . $imported . ' items so far'
            );
        }

        $context->logger()->info('Imported demo content', ['items' => $imported]);

        return Wtp_Import_Result::progress(
            ['phase' => 'remap', 'index' => $index, 'imported' => $imported],
            92,
            'Linking imported content'
        );
    }

    private function remap(Wtp_Import_Context $context, string $file): Wtp_Import_Result
    {
        $map = $this->loadMap($context);

        $this->remapParents($map);
        $this->remapMenuItems($map);
        $this->remapThumbnails($map);

        if ($context->planOption('replace_urls', true)) {
            $sourceUrl = (string) ($context->planOption('source_site_url', '') ?? '');
            if ($sourceUrl === '') {
                $sourceUrl = $this->baseUrlFrom($file);
            }
            if ($sourceUrl !== '') {
                $replaced = $this->replaceUrls($sourceUrl, home_url());
                $context->logger()->info('Rewrote demo URLs', [
                    'from' => $sourceUrl,
                    'to' => home_url(),
                    'rows' => $replaced,
                ]);
            }
        }

        $imported = (int) $context->cursorValue('imported', 0);
        $this->clearMap($context);
        $context->markDone('', ['items' => $imported]);

        return Wtp_Import_Result::done(['items' => $imported]);
    }

    // ---------------------------------------------------------------- items

    /**
     * @param array<int,int> $map source post id => new post id
     * @return int the created post id, or 0 when nothing was created
     */
    private function importItem(
        Wtp_Import_Context $context,
        SimpleXMLElement $item,
        array &$map,
        bool $fetchAttachments
    ): int {
        $wp = $item->children(self::NAMESPACES['wp']);
        $content = $item->children(self::NAMESPACES['content']);
        $excerpt = $item->children(self::NAMESPACES['excerpt']);
        $dc = $item->children(self::NAMESPACES['dc']);

        $sourceId = (int) ($wp->post_id ?? 0);
        $postType = (string) ($wp->post_type ?? 'post');
        $status = (string) ($wp->status ?? 'publish');

        if ($postType === '' || $status === 'auto-draft') {
            return 0;
        }
        if (!post_type_exists($postType) && $postType !== 'nav_menu_item') {
            // Custom post types belonging to a plugin that failed to install.
            return 0;
        }

        // Idempotency: an item already imported from this source id is left alone.
        $existing = $this->findBySourceId($sourceId, $postType);
        if ($existing > 0) {
            $map[$sourceId] = $existing;
            return 0;
        }

        $postData = [
            'post_title' => (string) $item->title,
            'post_name' => (string) ($wp->post_name ?? ''),
            'post_content' => (string) ($content->encoded ?? ''),
            'post_excerpt' => (string) ($excerpt->encoded ?? ''),
            'post_status' => $status,
            'post_type' => $postType,
            'post_date' => $this->date((string) ($wp->post_date ?? '')),
            'post_date_gmt' => $this->date((string) ($wp->post_date_gmt ?? '')),
            'comment_status' => (string) ($wp->comment_status ?? 'closed'),
            'ping_status' => (string) ($wp->ping_status ?? 'closed'),
            'menu_order' => (int) ($wp->menu_order ?? 0),
            'post_password' => (string) ($wp->post_password ?? ''),
            'post_author' => $this->resolveAuthor((string) ($dc->creator ?? '')),
            'post_parent' => 0, // remapped in the final phase
        ];

        if ($postType === 'attachment') {
            $newId = $this->importAttachment($context, $item, $postData, $fetchAttachments);
        } else {
            $newId = wp_insert_post(wp_slash($postData), true);
        }

        if (is_wp_error($newId) || (int) $newId <= 0) {
            $context->logger()->debug('Skipped an item that could not be created', [
                'source_id' => $sourceId,
                'type' => $postType,
                'reason' => is_wp_error($newId) ? $newId->get_error_message() : 'unknown',
            ]);
            return 0;
        }

        $newId = (int) $newId;
        $map[$sourceId] = $newId;

        update_post_meta($newId, self::META_SOURCE_ID, $sourceId);
        if (!empty((string) $item->link)) {
            update_post_meta($newId, self::META_SOURCE_URL, (string) $item->link);
        }
        // Remembered for the parent/menu remap pass.
        update_post_meta($newId, '_wtp_source_parent', (int) ($wp->post_parent ?? 0));

        $this->importMeta($newId, $wp);
        $this->importTerms2($newId, $item);

        if ((string) ($wp->is_sticky ?? '0') === '1') {
            stick_post($newId);
        }

        return $newId;
    }

    /**
     * @param array<string,mixed> $postData
     * @return int|WP_Error
     */
    private function importAttachment(
        Wtp_Import_Context $context,
        SimpleXMLElement $item,
        array $postData,
        bool $fetchRemote
    ) {
        $wp = $item->children(self::NAMESPACES['wp']);
        $url = (string) ($wp->attachment_url ?? '');

        if (!$fetchRemote || $url === '') {
            // Register the attachment without its file. The post survives, the
            // media library shows a placeholder, and nothing 500s.
            $postData['post_mime_type'] = 'application/octet-stream';
            return wp_insert_post(wp_slash($postData), true);
        }

        try {
            // Demo packages are operator-supplied but the URLs inside them are
            // not, so an attachment fetch is a genuine SSRF vector.
            (new UrlGuard(['https', 'http'], null, true))->validate($url);
        } catch (SecurityException $e) {
            $context->logger()->warning('Refused to fetch an attachment', [
                'reason' => $e->getMessage(),
            ], 'unsafe_url');
            return wp_insert_post(wp_slash($postData), true);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temporary = download_url($url, 60);
        if (is_wp_error($temporary)) {
            $context->logger()->debug('Attachment download failed', [
                'reason' => $temporary->get_error_message(),
            ]);
            return wp_insert_post(wp_slash($postData), true);
        }

        $fileArray = ['name' => basename(wp_parse_url($url, PHP_URL_PATH) ?: 'file'), 'tmp_name' => $temporary];
        $attachmentId = media_handle_sideload($fileArray, 0, $postData['post_title']);

        if (is_wp_error($attachmentId)) {
            @unlink($temporary);
            return wp_insert_post(wp_slash($postData), true);
        }

        // media_handle_sideload creates its own post; align it with the export.
        wp_update_post([
            'ID' => $attachmentId,
            'post_name' => $postData['post_name'],
            'post_date' => $postData['post_date'],
            'post_date_gmt' => $postData['post_date_gmt'],
            'post_excerpt' => $postData['post_excerpt'],
        ]);

        return $attachmentId;
    }

    private function importMeta(int $postId, SimpleXMLElement $wp): void
    {
        foreach ($wp->postmeta ?? [] as $meta) {
            $key = (string) $meta->meta_key;
            if ($key === '' || $key[0] === '_' && in_array($key, ['_edit_lock', '_edit_last'], true)) {
                continue;
            }
            $value = (string) $meta->meta_value;

            // Serialized values from the export are re-serialized safely by
            // maybe_unserialize + update_post_meta; nothing is unserialized
            // into an object graph we then call methods on.
            update_post_meta($postId, $key, maybe_unserialize($value));
        }
    }

    private function importTerms2(int $postId, SimpleXMLElement $item): void
    {
        $byTaxonomy = [];

        foreach ($item->category ?? [] as $category) {
            $taxonomy = (string) ($category['domain'] ?? '');
            $slug = (string) ($category['nicename'] ?? '');
            $name = trim((string) $category);

            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $term = $slug !== '' ? get_term_by('slug', $slug, $taxonomy) : get_term_by('name', $name, $taxonomy);
            if (!$term) {
                $created = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, ['slug' => $slug]);
                if (is_wp_error($created)) {
                    continue;
                }
                $termId = (int) $created['term_id'];
            } else {
                $termId = (int) $term->term_id;
            }
            $byTaxonomy[$taxonomy][] = $termId;
        }

        foreach ($byTaxonomy as $taxonomy => $ids) {
            wp_set_object_terms($postId, $ids, $taxonomy, false);
        }
    }

    // --------------------------------------------------------------- remaps

    /** @param array<int,int> $map */
    private function remapParents(array $map): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wtp_source_parent' AND meta_value <> '0'"
        );

        foreach ($rows as $row) {
            $sourceParent = (int) $row->meta_value;
            if (!isset($map[$sourceParent])) {
                continue;
            }
            wp_update_post(['ID' => (int) $row->post_id, 'post_parent' => $map[$sourceParent]]);
        }

        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_wtp_source_parent']);
    }

    /**
     * Menu items store their target in meta, using the *source* ids. Without
     * this pass every imported menu points at nothing.
     *
     * @param array<int,int> $map
     */
    private function remapMenuItems(array $map): void
    {
        $items = get_posts([
            'post_type' => 'nav_menu_item',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
        ]);

        foreach ($items as $itemId) {
            $type = (string) get_post_meta($itemId, '_menu_item_type', true);
            $objectId = (int) get_post_meta($itemId, '_menu_item_object_id', true);
            $parent = (int) get_post_meta($itemId, '_menu_item_menu_item_parent', true);

            if ($type === 'post_type' && isset($map[$objectId])) {
                update_post_meta($itemId, '_menu_item_object_id', $map[$objectId]);
            }
            if ($parent > 0 && isset($map[$parent])) {
                update_post_meta($itemId, '_menu_item_menu_item_parent', $map[$parent]);
            }
        }
    }

    /** @param array<int,int> $map */
    private function remapThumbnails(array $map): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'"
        );

        foreach ($rows as $row) {
            $source = (int) $row->meta_value;
            if (isset($map[$source])) {
                update_post_meta((int) $row->post_id, '_thumbnail_id', $map[$source]);
            }
        }
    }

    /**
     * Replace the demo site's URL with this site's, in post content, excerpts
     * and non-serialized meta.
     *
     * Serialized meta is handled value by value rather than with a blind
     * string replace, which would corrupt every serialized length prefix.
     *
     * @return int rows touched
     */
    private function replaceUrls(string $from, string $to): int
    {
        global $wpdb;

        $from = rtrim($from, '/');
        $to = rtrim($to, '/');
        if ($from === '' || $from === $to) {
            return 0;
        }

        $touched = 0;

        $touched += (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)",
            $from,
            $to
        ));
        $touched += (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(post_excerpt, %s, %s)",
            $from,
            $to
        ));
        $touched += (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s) WHERE post_type = 'attachment'",
            $from,
            $to
        ));

        // Meta and options may hold serialized structures.
        foreach ([$wpdb->postmeta => 'meta_id', $wpdb->options => 'option_id'] as $table => $primaryKey) {
            $column = $table === $wpdb->options ? 'option_value' : 'meta_value';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$primaryKey} AS pk, {$column} AS value FROM {$table} WHERE {$column} LIKE %s",
                '%' . $wpdb->esc_like($from) . '%'
            ));

            foreach ($rows as $row) {
                $updated = $this->replaceInValue((string) $row->value, $from, $to);
                if ($updated === (string) $row->value) {
                    continue;
                }
                $wpdb->update($table, [$column => $updated], [$primaryKey => $row->pk]);
                $touched++;
            }
        }

        return $touched;
    }

    private function replaceInValue(string $value, string $from, string $to): string
    {
        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if ($unserialized === false && $value !== serialize(false)) {
            return str_replace($from, $to, $value);
        }
        $walked = $this->walkReplace($unserialized, $from, $to);
        return serialize($walked);
    }

    /** @param mixed $value @return mixed */
    private function walkReplace($value, string $from, string $to, int $depth = 0)
    {
        if ($depth > 12) {
            return $value;
        }
        if (is_string($value)) {
            return str_replace($from, $to, $value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->walkReplace($item, $from, $to, $depth + 1);
            }
            return $out;
        }
        return $value;
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string,mixed> $asset */
    private function localFile(Wtp_Import_Context $context, array $asset): string
    {
        $cached = (string) $context->cursorValue('file', '');
        if ($cached !== '' && is_file($cached)) {
            return $cached;
        }

        $downloaded = $context->downloader()->fetch($asset);
        $filename = strtolower((string) ($asset['filename'] ?? ''));

        if (substr($filename, -4) === '.zip') {
            $directory = get_temp_dir() . 'wtp-content-' . wp_generate_password(8, false);
            $context->downloader()->extract($downloaded, $directory, SafeZip::CONTENT_EXTENSIONS);

            $found = glob($directory . '/*.xml') ?: glob($directory . '/*/*.xml');
            if (!$found) {
                throw new Wtp_Security_Exception('content_missing', 'The content archive holds no .xml export');
            }
            return $found[0];
        }

        return $downloaded;
    }

    private function openReader(string $file): XMLReader
    {
        $reader = new XMLReader();

        // LIBXML_NONET blocks network access during parsing. LIBXML_NOENT is
        // deliberately NOT set: despite the name it *enables* entity
        // substitution, which is precisely the XXE we are defending against.
        $flags = LIBXML_NONET;
        if (defined('LIBXML_NOCDATA')) {
            $flags |= LIBXML_NOCDATA;
        }
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            @libxml_disable_entity_loader(true);
        }

        if (!$reader->open($file, 'UTF-8', $flags)) {
            throw new RuntimeException('Cannot read the content export');
        }
        return $reader;
    }

    private function currentElement(XMLReader $reader): ?SimpleXMLElement
    {
        $xml = $reader->readOuterXml();
        if ($xml === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<root xmlns:wp="' . self::NAMESPACES['wp'] . '"'
            . ' xmlns:content="' . self::NAMESPACES['content'] . '"'
            . ' xmlns:excerpt="' . self::NAMESPACES['excerpt'] . '"'
            . ' xmlns:dc="' . self::NAMESPACES['dc'] . '">' . $xml . '</root>',
            'SimpleXMLElement',
            LIBXML_NONET | LIBXML_NOCDATA
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            return null;
        }
        $children = $element->children();
        foreach ($children as $child) {
            return $child;
        }
        // Namespaced roots (wp:category and friends) are not in the default ns.
        foreach ($element->children(self::NAMESPACES['wp']) as $child) {
            return $child;
        }
        return null;
    }

    private function createTerm(string $nodeName, SimpleXMLElement $node): bool
    {
        $wp = $node->children(self::NAMESPACES['wp']);
        $isNamespaced = $wp->count() > 0;
        $source = $isNamespaced ? $wp : $node;

        if ($nodeName === 'wp:category') {
            $taxonomy = 'category';
            $slug = (string) ($source->category_nicename ?? '');
            $name = (string) ($source->cat_name ?? '');
            $parent = (string) ($source->category_parent ?? '');
        } elseif ($nodeName === 'wp:tag') {
            $taxonomy = 'post_tag';
            $slug = (string) ($source->tag_slug ?? '');
            $name = (string) ($source->tag_name ?? '');
            $parent = '';
        } else {
            $taxonomy = (string) ($source->term_taxonomy ?? '');
            $slug = (string) ($source->term_slug ?? '');
            $name = (string) ($source->term_name ?? '');
            $parent = (string) ($source->term_parent ?? '');
        }

        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return false;
        }
        if ($name === '' && $slug === '') {
            return false;
        }
        if ($slug !== '' && get_term_by('slug', $slug, $taxonomy)) {
            return false;
        }

        $args = ['slug' => $slug];
        if ($parent !== '') {
            $parentTerm = get_term_by('slug', $parent, $taxonomy);
            if ($parentTerm) {
                $args['parent'] = (int) $parentTerm->term_id;
            }
        }

        $created = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, $args);
        return !is_wp_error($created);
    }

    private function findBySourceId(int $sourceId, string $postType): int
    {
        if ($sourceId <= 0) {
            return 0;
        }
        $found = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => self::META_SOURCE_ID,
            'meta_value' => $sourceId,
            'suppress_filters' => true,
        ]);
        return $found === [] ? 0 : (int) $found[0];
    }

    private function resolveAuthor(string $login): int
    {
        if ($login !== '') {
            $user = get_user_by('login', $login);
            if ($user instanceof WP_User) {
                return (int) $user->ID;
            }
        }
        // Fall back to the site's first administrator: the demo's author
        // accounts are not recreated, since inventing logins on a customer's
        // site would be a security decision we have no business making.
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        return $admins === [] ? 1 : (int) $admins[0];
    }

    private function date(string $value): string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return current_time('mysql');
        }
        return $value;
    }

    private function removeDefaultContent(Wtp_Import_Context $context): void
    {
        $removed = 0;

        // "Hello world!", the sample page and the default comment: WordPress's
        // starter content, never anything the customer created — the site is
        // minutes old at this point.
        foreach ([1, 2] as $postId) {
            $post = get_post($postId);
            if ($post instanceof WP_Post && in_array($post->post_name, ['hello-world', 'sample-page', 'privacy-policy'], true)) {
                wp_delete_post($postId, true);
                $removed++;
            }
        }
        $comment = get_comment(1);
        if ($comment instanceof WP_Comment) {
            wp_delete_comment(1, true);
        }

        $context->logger()->debug('Removed WordPress default content', ['posts' => $removed]);
    }

    private function baseUrlFrom(string $file): string
    {
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            return '';
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);

        if (preg_match('#<wp:base_blog_url>(.*?)</wp:base_blog_url>#', $head, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('#<wp:base_site_url>(.*?)</wp:base_site_url>#', $head, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /** @return array<int,int> */
    private function loadMap(Wtp_Import_Context $context): array
    {
        $stored = get_option(self::MAP_OPTION_PREFIX . $context->markerKey('map'), []);
        return is_array($stored) ? array_map('intval', $stored) : [];
    }

    /** @param array<int,int> $map */
    private function saveMap(Wtp_Import_Context $context, array $map): void
    {
        update_option(self::MAP_OPTION_PREFIX . $context->markerKey('map'), $map, false);
    }

    private function clearMap(Wtp_Import_Context $context): void
    {
        delete_option(self::MAP_OPTION_PREFIX . $context->markerKey('map'));
    }
}
