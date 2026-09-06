<?php
/**
 * Post-import site finalisation.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The last step before the site is handed to the customer.
 *
 * Everything here is the difference between "the content is in the database"
 * and "the site looks like the demo they clicked on": front page, menus,
 * permalinks, and flushing the caches that would otherwise serve the old state.
 */
final class Wtp_Import_Finalizer implements Wtp_Importer
{
    public function key(): string
    {
        return 'finalize';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return true;
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $site = $context->site();
        $applied = [];

        $applied['identity'] = $this->applyIdentity($site);
        $applied['front_page'] = $this->applyFrontPage($context, $site);
        $applied['menus'] = $this->assignMenus($context, $site);
        $applied['permalinks'] = $this->applyPermalinks($site);
        $applied['visibility'] = $this->applyVisibility($site);

        $this->flushCaches();
        $this->tidyUp($context);

        $context->markDone('', $applied);
        $context->logger()->info('Finalised the site', $applied);

        return Wtp_Import_Result::done($applied);
    }

    /** @param array<string,mixed> $site */
    private function applyIdentity(array $site): bool
    {
        $changed = false;

        $title = trim((string) ($site['title'] ?? ''));
        if ($title !== '' && get_option('blogname') !== $title) {
            update_option('blogname', $title);
            $changed = true;
        }

        $tagline = (string) ($site['tagline'] ?? '');
        // An empty tagline is a deliberate choice: WordPress's default
        // "Just another WordPress site" on a paid site looks unfinished.
        if ($tagline !== '' || get_option('blogdescription') === 'Just another WordPress site') {
            update_option('blogdescription', $tagline);
            $changed = true;
        }

        $timezone = (string) ($site['timezone'] ?? '');
        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            update_option('timezone_string', $timezone);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Point the site at the demo's home page.
     *
     * @param array<string,mixed> $site
     */
    private function applyFrontPage(Wtp_Import_Context $context, array $site): bool
    {
        $frontTitle = trim((string) ($site['front_page'] ?? ''));
        if ($frontTitle === '') {
            return false;
        }

        $front = $this->findPage($frontTitle);
        if ($front === 0) {
            $context->logger()->warning('The demo names a front page that was not imported', [
                'page' => $frontTitle,
            ], 'front_page_missing');
            return false;
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $front);

        $blogTitle = trim((string) ($site['blog_page'] ?? ''));
        if ($blogTitle !== '') {
            $blog = $this->findPage($blogTitle);
            if ($blog > 0 && $blog !== $front) {
                update_option('page_for_posts', $blog);
            }
        }

        return true;
    }

    /**
     * Attach imported menus to the theme's registered locations.
     *
     * The plan maps location => menu name; a demo that names a location the
     * active theme does not have is a warning, not a failure.
     *
     * @param array<string,mixed> $site
     * @return int menus assigned
     */
    private function assignMenus(Wtp_Import_Context $context, array $site): int
    {
        $wanted = isset($site['menus']) && is_array($site['menus']) ? $site['menus'] : [];
        $locations = get_registered_nav_menus();
        $assigned = get_theme_mod('nav_menu_locations', []);
        if (!is_array($assigned)) {
            $assigned = [];
        }

        $count = 0;

        foreach ($wanted as $location => $menuName) {
            $location = (string) $location;
            $menuName = (string) $menuName;

            if (!isset($locations[$location])) {
                $context->logger()->debug('The active theme has no such menu location', [
                    'location' => $location,
                ]);
                continue;
            }
            $menu = wp_get_nav_menu_object($menuName);
            if (!$menu) {
                continue;
            }
            $assigned[$location] = (int) $menu->term_id;
            $count++;
        }

        // A demo that ships exactly one menu and names no locations still gets
        // it attached to the theme's primary location — otherwise the site
        // renders with no navigation at all, which reads as broken.
        if ($count === 0 && $locations !== []) {
            $menus = wp_get_nav_menus();
            if (count($menus) === 1) {
                $primary = array_key_exists('primary', $locations) ? 'primary' : (string) array_key_first($locations);
                $assigned[$primary] = (int) $menus[0]->term_id;
                $count++;
            }
        }

        if ($count > 0) {
            set_theme_mod('nav_menu_locations', $assigned);
        }
        return $count;
    }

    /** @param array<string,mixed> $site */
    private function applyPermalinks(array $site): bool
    {
        $structure = (string) ($site['permalink_structure'] ?? '');
        if ($structure === '') {
            return false;
        }

        global $wp_rewrite;
        if (!($wp_rewrite instanceof WP_Rewrite)) {
            return false;
        }

        $wp_rewrite->set_permalink_structure($structure);
        $wp_rewrite->flush_rules(true);

        return true;
    }

    /**
     * A provisioned site must be visible to search engines unless the demo
     * explicitly asks otherwise — a silently de-indexed site is a support
     * ticket weeks later.
     *
     * @param array<string,mixed> $site
     */
    private function applyVisibility(array $site): bool
    {
        $discourage = !empty($site['discourage_search_engines']);
        update_option('blog_public', $discourage ? '0' : '1');
        return !$discourage;
    }

    private function findPage(string $title): int
    {
        $page = get_page_by_path(sanitize_title($title));
        if ($page instanceof WP_Post) {
            return (int) $page->ID;
        }

        $found = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
            'title' => $title,
        ]);
        if ($found !== []) {
            return (int) $found[0];
        }

        // Fall back to a slug match, which is how most demos name their pages.
        $bySlug = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
            'name' => sanitize_title($title),
        ]);
        return $bySlug === [] ? 0 : (int) $bySlug[0];
    }

    private function flushCaches(): void
    {
        wp_cache_flush();
        delete_option('rewrite_rules');

        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache();
        }
        // Give popular caching plugins a chance to drop their page cache.
        do_action('wtp_after_finalize');
    }

    /**
     * Remove the bookkeeping the importer left behind. The source-id meta is
     * kept: it is what makes a future re-import idempotent.
     */
    private function tidyUp(Wtp_Import_Context $context): void
    {
        delete_post_meta_by_key('_wtp_source_parent');

        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'wtp_import_map_%'"
        );

        $context->logger()->debug('Cleaned up import bookkeeping');
    }
}
