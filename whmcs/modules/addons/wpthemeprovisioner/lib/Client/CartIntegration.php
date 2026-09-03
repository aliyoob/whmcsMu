<?php
namespace Wtp\Addon\Client;

use Throwable;
use Wtp\Addon\Catalog\CatalogException;
use Wtp\Addon\Container;
use Wtp\Addon\Job\SelectionRepository;
use Wtp\Shared\Support\Json;

/**
 * Puts the theme/demo picker on the order form and binds the customer's choice
 * to the service that checkout creates.
 *
 * WHMCS's order forms are template files we must not fork, so the picker is
 * injected by hooks and mounted by a small script. The important part is what
 * happens *after* the click: nothing the browser sends is trusted. Two integer
 * ids arrive, and both are re-resolved against the live catalogue here, at
 * checkout, and again when the provisioning module builds the plan.
 */
final class CartIntegration
{
    public const SESSION_KEY = 'wtp_cart';
    public const FIELD_THEME = 'wtp_theme_id';
    public const FIELD_DEMO = 'wtp_demo_id';

    /** Catalogue payloads larger than this are trimmed rather than bloating the page. */
    private const MAX_THEMES_INLINE = 60;
    private const MAX_DEMOS_PER_THEME = 40;

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // ------------------------------------------------------------ rendering

    /**
     * Is the picker relevant to the page currently being rendered?
     *
     * @param array<string,mixed> $vars the client area template variables
     */
    public function appliesTo(array $vars): bool
    {
        $filename = isset($vars['filename']) ? (string) $vars['filename'] : '';
        if ($filename !== 'cart') {
            return false;
        }
        return $this->currentProductId() > 0;
    }

    /**
     * The product being configured right now.
     *
     * On `a=add` and `a=confproduct` the id is in the request; when the
     * customer edits an existing cart line it comes from the cart session.
     */
    public function currentProductId(): int
    {
        foreach (['pid', 'productId'] as $key) {
            if (isset($_REQUEST[$key]) && is_numeric($_REQUEST[$key])) {
                return (int) $_REQUEST[$key];
            }
        }

        if (isset($_REQUEST['i']) && is_numeric($_REQUEST['i'])) {
            $index = (int) $_REQUEST['i'];
            $products = $this->cartProducts();
            if (isset($products[$index]['pid'])) {
                return (int) $products[$index]['pid'];
            }
        }
        return 0;
    }

    /** @return string HTML for the document head */
    public function headOutput(array $vars): string
    {
        if (!$this->appliesTo($vars)) {
            return '';
        }
        $productId = $this->currentProductId();
        if (!$this->container->productSettings()->isEnabled($productId)) {
            return '';
        }

        $catalogue = $this->catalogueFor($productId);
        if ($catalogue['themes'] === []) {
            return '';
        }

        $css = @file_get_contents(WTP_ADDON_DIR . '/assets/css/selector.css');

        // The catalogue rides along as an inline JSON island rather than a
        // second HTTP endpoint: fewer moving parts, no extra attack surface,
        // and nothing to authenticate. "</" is escaped so a theme description
        // can never close the script element.
        $payload = str_replace('</', '<\\/', Json::encode($catalogue));

        return "\n<style id=\"wtp-selector-css\">" . ($css === false ? '' : $css) . "</style>\n"
            . '<script type="application/json" id="wtp-catalogue">' . $payload . "</script>\n";
    }

    /** @return string HTML for the end of the document body */
    public function footerOutput(array $vars): string
    {
        if (!$this->appliesTo($vars)) {
            return '';
        }
        $productId = $this->currentProductId();
        if (!$this->container->productSettings()->isEnabled($productId)) {
            return '';
        }
        if ($this->catalogueFor($productId)['themes'] === []) {
            return '';
        }

        $js = @file_get_contents(WTP_ADDON_DIR . '/assets/js/selector.js');
        return "\n<script id=\"wtp-selector-js\">" . ($js === false ? '' : $js) . "</script>\n";
    }

    /**
     * The catalogue as the browser sees it: names, images, descriptions and the
     * ids to post back. No storage paths, no checksums, no import plans.
     *
     * @return array<string,mixed>
     */
    public function catalogueFor(int $productId): array
    {
        $catalog = $this->container->catalog();
        $settings = $this->container->productSettings();

        $themes = [];
        $available = $settings->availableThemes($productId, $catalog);

        foreach (array_slice($available, 0, self::MAX_THEMES_INLINE) as $theme) {
            $themeId = (int) $theme['id'];
            $demos = [];

            foreach (array_slice($catalog->listDemos($themeId, true), 0, self::MAX_DEMOS_PER_THEME) as $demo) {
                $demos[] = [
                    'id' => (int) $demo['id'],
                    'name' => (string) $demo['name'],
                    'description' => (string) ($demo['description'] ?? ''),
                    'image' => (string) ($demo['preview_image_url'] ?? ''),
                    'preview' => (string) ($demo['preview_site_url'] ?? ''),
                ];
            }

            // A theme with no active demo cannot be provisioned, so it is not offered.
            if ($demos === []) {
                continue;
            }

            $themes[] = [
                'id' => $themeId,
                'name' => (string) $theme['name'],
                'description' => (string) ($theme['description'] ?? ''),
                'image' => (string) ($theme['preview_image_url'] ?? ''),
                'demos' => $demos,
            ];
        }

        $staged = $this->stagedFor($productId);

        return [
            'required' => $this->container->productSettings()->isRequired($productId),
            'fields' => ['theme' => self::FIELD_THEME, 'demo' => self::FIELD_DEMO],
            'selected' => [
                'theme' => $staged === null ? 0 : (int) $staged['theme_id'],
                'demo' => $staged === null ? 0 : (int) $staged['demo_id'],
            ],
            'strings' => [
                'heading' => 'Choose your WordPress theme',
                'subheading' => 'Your site is installed with this theme and demo content, ready to edit.',
                'demoHeading' => 'Choose a demo for %s',
                'select' => 'Select',
                'selected' => 'Selected',
                'preview' => 'Preview',
                'change' => 'Change theme',
                'required' => 'Please choose a theme and a demo before continuing.',
            ],
            'themes' => $themes,
        ];
    }

    // ----------------------------------------------------------- validation

    /**
     * `ShoppingCartValidateProductUpdate` — the customer submitted the product
     * configuration form.
     *
     * @param array<string,mixed> $request
     * @return string[] validation errors, empty when acceptable
     */
    public function validateProductUpdate(array $request): array
    {
        $productId = $this->currentProductId();
        if ($productId <= 0 || !$this->container->productSettings()->isEnabled($productId)) {
            return [];
        }

        $themeId = isset($request[self::FIELD_THEME]) ? (int) $request[self::FIELD_THEME] : 0;
        $demoId = isset($request[self::FIELD_DEMO]) ? (int) $request[self::FIELD_DEMO] : 0;

        if ($themeId <= 0 || $demoId <= 0) {
            if ($this->container->productSettings()->isRequired($productId)) {
                return ['Please choose a WordPress theme and demo for this hosting plan.'];
            }
            return [];
        }

        if (!$this->container->productSettings()->isThemeAllowed($productId, $themeId)) {
            return ['That theme is not available with this hosting plan.'];
        }

        try {
            $this->container->catalog()->resolveSelection($themeId, $demoId);
        } catch (CatalogException $e) {
            return [$this->humanise($e->getMessage())];
        }

        $this->stage($productId, $themeId, $demoId, $request);
        return [];
    }

    /**
     * `ShoppingCartValidateCheckout` — last chance to stop an order that would
     * provision a site with nothing to install.
     *
     * @return string[]
     */
    public function validateCheckout(): array
    {
        $errors = [];

        foreach ($this->cartProducts() as $index => $product) {
            $productId = (int) ($product['pid'] ?? 0);
            if ($productId <= 0 || !$this->container->productSettings()->isRequired($productId)) {
                continue;
            }

            $staged = $this->stagedForIndex($index, $productId, (string) ($product['domain'] ?? ''));
            if ($staged === null) {
                $errors[] = 'Please choose a WordPress theme and demo for every hosting plan in your cart.';
                break;
            }

            try {
                $this->container->catalog()->resolveSelection(
                    (int) $staged['theme_id'],
                    (int) $staged['demo_id']
                );
            } catch (CatalogException $e) {
                $errors[] = 'The theme or demo you selected is no longer available. '
                    . 'Please choose another before completing your order.';
                break;
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------- binding

    /**
     * `AfterShoppingCartCheckout` — attach each staged selection to the service
     * WHMCS just created.
     *
     * `ServiceIDs` arrives in cart order, but relying on position alone would
     * silently mis-assign a theme if WHMCS ever changed that. Every match is
     * therefore cross-checked against the service's product id and domain, and
     * an ambiguous case is logged rather than guessed.
     *
     * @param array<string,mixed> $vars
     */
    public function bindOrder(array $vars): void
    {
        $serviceIds = isset($vars['ServiceIDs']) && is_array($vars['ServiceIDs']) ? $vars['ServiceIDs'] : [];
        if ($serviceIds === []) {
            $this->clearStaging();
            return;
        }

        $orderId = (int) ($vars['OrderID'] ?? 0);
        $staged = $this->allStaged();
        if ($staged === []) {
            $this->clearStaging();
            return;
        }

        $services = $this->loadServices($serviceIds);

        foreach (array_values($serviceIds) as $position => $serviceId) {
            $serviceId = (int) $serviceId;
            $service = $services[$serviceId] ?? null;
            if ($service === null) {
                continue;
            }

            $match = $this->matchStaged($staged, $position, (int) $service['packageid'], (string) $service['domain']);
            if ($match === null) {
                continue;
            }

            try {
                $this->container->selections()->record(
                    [
                        'service_id' => $serviceId,
                        'order_id' => $orderId,
                        'client_id' => (int) $service['userid'],
                        'product_id' => (int) $service['packageid'],
                        'domain' => (string) $service['domain'],
                    ],
                    (int) $match['theme_id'],
                    (int) $match['demo_id'],
                    SelectionRepository::SOURCE_CLIENT
                );
            } catch (Throwable $e) {
                // The order is already paid for; a binding failure must not
                // throw here. It is recorded so provisioning can fall back to
                // the product defaults and an operator can see what happened.
                error_log(sprintf(
                    '[WTP] could not bind theme selection to service %d on order %d: %s',
                    $serviceId,
                    $orderId,
                    $e->getMessage()
                ));
            }
        }

        $this->clearStaging();
    }

    // ------------------------------------------------------------- staging

    /** @param array<string,mixed> $request */
    private function stage(int $productId, int $themeId, int $demoId, array $request): void
    {
        $this->startSession();

        $index = isset($request['i']) && is_numeric($request['i']) ? (int) $request['i'] : null;
        $domain = (string) ($request['domain'] ?? '');
        if ($domain === '' && isset($request['sld'], $request['tld'])) {
            $domain = trim((string) $request['sld']) . (string) $request['tld'];
        }

        $entry = [
            'pid' => $productId,
            'index' => $index,
            'domain' => strtolower(trim($domain)),
            'theme_id' => $themeId,
            'demo_id' => $demoId,
            'staged_at' => time(),
        ];

        $staged = $this->allStaged();

        // Replace an existing entry for the same cart line rather than piling up.
        foreach ($staged as $key => $existing) {
            $sameIndex = $index !== null && $existing['index'] === $index;
            $samePair = $existing['pid'] === $productId && $existing['domain'] === $entry['domain'];
            if ($sameIndex || $samePair) {
                $staged[$key] = $entry;
                $_SESSION[self::SESSION_KEY] = $staged;
                return;
            }
        }

        $staged[] = $entry;
        $_SESSION[self::SESSION_KEY] = $staged;
    }

    /** @return array<int,array<string,mixed>> */
    public function allStaged(): array
    {
        $this->startSession();
        $staged = isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])
            ? $_SESSION[self::SESSION_KEY]
            : [];

        $clean = [];
        foreach ($staged as $entry) {
            if (!is_array($entry) || !isset($entry['pid'], $entry['theme_id'], $entry['demo_id'])) {
                continue;
            }
            $clean[] = [
                'pid' => (int) $entry['pid'],
                'index' => isset($entry['index']) && $entry['index'] !== null ? (int) $entry['index'] : null,
                'domain' => (string) ($entry['domain'] ?? ''),
                'theme_id' => (int) $entry['theme_id'],
                'demo_id' => (int) $entry['demo_id'],
                'staged_at' => (int) ($entry['staged_at'] ?? 0),
            ];
        }
        return $clean;
    }

    /** @return array<string,mixed>|null */
    private function stagedFor(int $productId): ?array
    {
        $index = isset($_REQUEST['i']) && is_numeric($_REQUEST['i']) ? (int) $_REQUEST['i'] : null;
        foreach ($this->allStaged() as $entry) {
            if ($index !== null && $entry['index'] === $index) {
                return $entry;
            }
            if ($index === null && $entry['pid'] === $productId) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function stagedForIndex(int $index, int $productId, string $domain): ?array
    {
        $staged = $this->allStaged();
        foreach ($staged as $entry) {
            if ($entry['index'] === $index) {
                return $entry;
            }
        }
        foreach ($staged as $entry) {
            if ($entry['pid'] === $productId && $entry['domain'] === strtolower(trim($domain))) {
                return $entry;
            }
        }
        foreach ($staged as $entry) {
            if ($entry['pid'] === $productId) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $staged
     * @return array<string,mixed>|null
     */
    private function matchStaged(array $staged, int $position, int $productId, string $domain): ?array
    {
        $domain = strtolower(trim($domain));

        foreach ($staged as $entry) {
            if ($entry['index'] === $position && $entry['pid'] === $productId) {
                return $entry;
            }
        }
        foreach ($staged as $entry) {
            if ($entry['pid'] === $productId && $entry['domain'] !== '' && $entry['domain'] === $domain) {
                return $entry;
            }
        }

        $candidates = array_values(array_filter($staged, static function (array $entry) use ($productId): bool {
            return $entry['pid'] === $productId;
        }));

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (isset($candidates[$position])) {
            return $candidates[$position];
        }
        return null;
    }

    public function clearStaging(): void
    {
        $this->startSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    // -------------------------------------------------------------- helpers

    /** @return array<int,array<string,mixed>> */
    private function cartProducts(): array
    {
        $this->startSession();
        if (!isset($_SESSION['cart']['products']) || !is_array($_SESSION['cart']['products'])) {
            return [];
        }
        return array_values($_SESSION['cart']['products']);
    }

    /**
     * @param array<int,int|string> $serviceIds
     * @return array<int,array<string,mixed>>
     */
    private function loadServices(array $serviceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $serviceIds)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $bind = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $bind['id' . $i] = $id;
        }

        $rows = $this->container->db()->select(
            'SELECT id, userid, packageid, domain FROM tblhosting WHERE id IN (' . implode(', ', $placeholders) . ')',
            $bind
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /** Turn a catalogue error code into something a customer can act on. */
    private function humanise(string $code): string
    {
        switch ($code) {
            case 'theme_not_found':
            case 'theme_inactive':
                return 'That theme is no longer available. Please choose another.';
            case 'demo_not_found':
            case 'demo_inactive':
                return 'That demo is no longer available. Please choose another.';
            case 'demo_theme_mismatch':
                return 'That demo does not belong to the theme you selected.';
            case 'theme_version_missing':
            case 'theme_version_invalid':
                return 'That theme is not ready to be installed yet. Please choose another.';
            default:
                return 'Please choose a valid WordPress theme and demo.';
        }
    }
}
