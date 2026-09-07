<?php
namespace Wtp\Tests;

use Wtp\Addon\Admin\AdminController;
use Wtp\Addon\Admin\View;

/**
 * The admin screens.
 *
 * They are pure rendering, which is exactly why they need covering: a missing
 * variable or a mistyped template path produces no error until an operator
 * opens the page. And two of the values on these screens — theme names and
 * customer domains — come from outside, so escaping is not optional.
 */
final class AdminScreensTest extends TestCase
{
    /** @var Harness */
    private $h;
    /** @var AdminController */
    private $controller;

    public function setUp(): void
    {
        $this->h = new Harness();
        $this->h->seedCatalogue();
        $this->h->seedSelection();
        $this->h->createJob();

        $this->controller = new AdminController(
            $this->h->container,
            new View(WTP_ADDON_DIR . '/templates/admin'),
            'addonmodules.php?module=wpthemeprovisioner'
        );
    }

    public function tearDown(): void
    {
        $this->h->cleanup();
    }

    /** @param array<string,mixed> $extra */
    private function render(string $action, array $extra = [], string $method = 'GET'): string
    {
        return $this->controller->handle(array_merge(
            ['action' => $action, '_method' => $method],
            $extra
        ));
    }

    public function testEveryScreenRenders(): void
    {
        $job = $this->h->job();

        $screens = [
            'dashboard' => [],
            'themes' => [],
            'theme' => ['id' => $this->h->ids['theme']],
            'demo' => ['id' => $this->h->ids['demo']],
            'products' => [],
            'jobs' => [],
            'job' => ['job' => (int) $job['id']],
            'settings' => [],
        ];

        foreach ($screens as $action => $extra) {
            $html = $this->render($action, $extra);

            $this->assertNotContains('Missing admin template', $html, $action);
            $this->assertNotContains('could not be rendered', $html, $action);
            $this->assertGreaterThan(400, strlen($html), $action . ' rendered suspiciously little');
            $this->assertContains('wtp-admin', $html, $action . ' is missing its layout');
        }
    }

    public function testAnUnknownScreenFallsBackToTheDashboard(): void
    {
        $html = $this->render('nonsense');
        $this->assertContains('wtp-admin', $html);
        $this->assertNotContains('could not be rendered', $html);
    }

    public function testANewThemeFormRendersWithoutATheme(): void
    {
        $html = $this->render('theme');
        $this->assertContains('New theme', $html);
    }

    public function testTheJobScreenShowsItsTimeline(): void
    {
        $html = $this->render('job', ['job' => (int) $this->h->job()['id']]);

        $this->assertContains('Provisioning status', $html);
        $this->assertContains('Create hosting account', $html);
        $this->assertContains('Event log', $html);

        // A job where nothing has run yet offers no per-step retry, which is
        // right: there is nothing to retry.
        $this->assertNotContains('Retry this step', $html);
    }

    public function testAFailedStepOffersARetry(): void
    {
        $jobId = (int) $this->h->job()['id'];
        $this->h->container->steps()->start($jobId, 'account.create');
        $this->h->container->steps()->fail($jobId, 'account.create', 'cpanel_busy', 'The panel was busy');

        $html = $this->render('job', ['job' => $jobId]);

        $this->assertContains('Retry this step', $html);
        $this->assertContains('Retry from here', $html);
        $this->assertContains('The panel was busy', $html);
    }

    public function testAMissingJobIsReportedNotFatal(): void
    {
        $html = $this->render('job', ['job' => 999999]);
        $this->assertContains('No such provisioning job', $html);
    }

    // ------------------------------------------------------------------ XSS

    public function testOperatorSuppliedTextIsEscaped(): void
    {
        $this->h->container->catalog()->updateTheme($this->h->ids['theme'], [
            'name' => '<script>alert(1)</script>',
            'slug' => 'astra',
            'description' => '"><img src=x onerror=alert(2)>',
        ]);

        $html = $this->render('themes');

        $this->assertNotContains('<script>alert(1)</script>', $html);
        $this->assertNotContains('onerror=alert(2)', $html);
        // It is still shown — escaped, not silently dropped.
        $this->assertContains('&lt;script&gt;', $html);
    }

    public function testACustomerDomainIsEscaped(): void
    {
        $this->h->db->update(
            'mod_wtp_jobs',
            ['domain' => '"><svg onload=alert(3)>'],
            ['id' => (int) $this->h->job()['id']]
        );

        $html = $this->render('jobs');

        // The payload's *text* legitimately survives; what must not survive is
        // the markup around it. Asserting on "onload=" alone would be a test
        // that passes for the wrong reason, or fails for no reason.
        $this->assertNotContains('<svg', $html);
        $this->assertContains('&lt;svg', $html);
        $this->assertNotContains('"><svg', $html);
    }

    public function testAHostileImageUrlCannotBecomeJavascript(): void
    {
        $this->assertSame('', View::url('javascript:alert(1)'));
        $this->assertSame('', View::url('data:text/html;base64,PHN2Zz4='));
        $this->assertSame('https://cdn.example/x.png', View::url('https://cdn.example/x.png'));
        $this->assertSame('?a=b&amp;c=d', View::url('?a=b&c=d'));
    }

    // ----------------------------------------------------------------- CSRF

    public function testAForgedPostChangesNothing(): void
    {
        $html = $this->render('themes', [
            'do' => 'delete-theme',
            'id' => $this->h->ids['theme'],
            'token' => 'not-the-real-token',
        ], 'POST');

        $this->assertNotNull(
            $this->h->container->catalog()->findTheme($this->h->ids['theme']),
            'a forged POST deleted a theme'
        );
        $this->assertContains('session token', $html);
    }

    public function testAPostWithoutATokenChangesNothing(): void
    {
        $this->render('products', [
            'do' => 'save-product',
            'product_id' => Harness::PRODUCT_ID,
            'enabled' => '1',
        ], 'POST');

        // The seeded product settings must be untouched by an unauthenticated post.
        $settings = $this->h->container->productSettings()->forProduct(Harness::PRODUCT_ID);
        $this->assertNotNull($settings);
        $this->assertSame(1, (int) $settings['enabled'], 'seeded value should be unchanged');
    }

    // ------------------------------------------------------------- helpers

    public function testViewHelpersFormatSensibly(): void
    {
        $this->assertSame('1.0 KB', View::bytes(1024));
        $this->assertSame('2.0 MB', View::bytes(2097152));
        $this->assertSame('512 B', View::bytes(512));

        $this->assertSame('—', View::ago(null));
        $this->assertSame('just now', View::ago(gmdate('Y-m-d H:i:s')));
        $this->assertContains('minute', View::ago(gmdate('Y-m-d H:i:s', time() - 120)));

        $this->assertSame('success', View::tone('completed'));
        $this->assertSame('danger', View::tone('failed'));
        $this->assertSame('warning', View::tone('needs_attention'));
    }
}
