<?php
namespace Wtp\Tests;

use Wtp\Addon\Catalog\CatalogException;
use Wtp\Shared\Plan\ImportPlan;

final class CatalogueTest extends TestCase
{
    /** @var Harness */
    private $h;

    public function setUp(): void
    {
        $this->h = new Harness();
        $this->h->seedCatalogue();
    }

    public function tearDown(): void
    {
        $this->h->cleanup();
    }

    public function testTheRealMigrationsCreateEveryTable(): void
    {
        foreach (\Wtp\Addon\Support\Installer::tables() as $table) {
            $this->assertTrue($this->h->db->tableExists($table), $table . ' should exist');
        }
    }

    public function testCatalogueRoundTrip(): void
    {
        $themes = $this->h->container->catalog()->listThemes(true);
        $this->assertCount(1, $themes);
        $this->assertSame('astra', $themes[0]['slug']);

        $demos = $this->h->container->catalog()->listDemos((int) $themes[0]['id'], true);
        $this->assertCount(1, $demos);
        $this->assertSame('agency', $demos[0]['slug']);
    }

    public function testSelectionResolutionRejectsAMismatchedDemo(): void
    {
        $catalog = $this->h->container->catalog();

        $otherTheme = $catalog->createTheme(['name' => 'Kadence', 'slug' => 'kadence']);
        $catalog->createVersion($otherTheme, [
            'version' => '1.0.0',
            'source_type' => 'wporg',
            'source_ref' => 'kadence',
            'is_default' => true,
        ]);

        $this->assertThrows(CatalogException::class, function () use ($otherTheme) {
            $this->h->container->catalog()->resolveSelection($otherTheme, $this->h->ids['demo']);
        }, 'demo_theme_mismatch');
    }

    public function testInactiveThemeIsRefused(): void
    {
        $this->h->container->catalog()->setThemeStatus($this->h->ids['theme'], 'inactive');

        $this->assertThrows(CatalogException::class, function () {
            $this->h->container->catalog()->resolveSelection($this->h->ids['theme'], $this->h->ids['demo']);
        }, 'theme_inactive');
    }

    public function testAThemeInUseCannotBeDeleted(): void
    {
        $this->h->seedSelection();

        $this->assertThrows(CatalogException::class, function () {
            $this->h->container->catalog()->deleteTheme($this->h->ids['theme']);
        }, 'referenced by 1 service');
    }

    public function testPlanIsBuiltFromTheCatalogue(): void
    {
        $this->h->seedSelection();
        $job = $this->h->createJob();

        $plan = $this->h->container->planResolver()->forJob($job);

        $this->assertSame('astra', $plan->theme()['slug']);
        $this->assertSame('4.6.1', $plan->theme()['version']);
        $this->assertSame('agency', $plan->demo()['slug']);
        $this->assertSame('asset', $plan->theme()['package']['kind']);
        $this->assertSame('tv:' . $this->h->ids['version'], $plan->theme()['package']['asset_id']);

        // Steps come from the assets that actually exist, not from a fixed list.
        $this->assertTrue($plan->hasStep('content.import'));
        $this->assertTrue($plan->hasStep('widgets.import'));
        $this->assertFalse($plan->hasStep('customizer.import'));

        $this->assertCount(1, $plan->plugins());
        $this->assertSame('elementor', $plan->plugins()[0]['slug']);

        // The demo's import_plan overrides reach the site block.
        $this->assertSame('Home', $plan->site()['front_page']);
        $this->assertSame('Main Menu', $plan->site()['menus']['primary']);
    }

    public function testPlanIsValidAgainstTheSharedValidator(): void
    {
        $this->h->seedSelection();
        $job = $this->h->createJob();
        $plan = $this->h->container->planResolver()->forJob($job);

        $this->assertDoesNotThrow(static function () use ($plan) {
            ImportPlan::fromArray($plan->toArray());
        });
    }

    public function testAllowedAssetHandlesMatchThePlan(): void
    {
        $this->h->seedSelection();
        $job = $this->h->createJob();

        $handles = $this->h->container->planResolver()->allowedAssetHandles($job);

        $this->assertTrue(in_array('tv:' . $this->h->ids['version'], $handles, true));
        $this->assertTrue(in_array('da:' . $this->h->ids['content_asset'], $handles, true));
        $this->assertTrue(in_array('da:' . $this->h->ids['widget_asset'], $handles, true));
        $this->assertCount(3, $handles);
    }

    public function testJobCreationIsIdempotentForAService(): void
    {
        $this->h->seedSelection();

        $first = $this->h->container->jobs()->openFor([
            'service_id' => Harness::SERVICE_ID,
            'domain' => Harness::DOMAIN,
        ]);
        $second = $this->h->container->jobs()->openFor([
            'service_id' => Harness::SERVICE_ID,
            'domain' => Harness::DOMAIN,
        ]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame((int) $first['job']['id'], (int) $second['job']['id']);
    }

    public function testEveryStepIsCreatedForANewJob(): void
    {
        $this->h->seedSelection();
        $job = $this->h->createJob();

        $steps = $this->h->container->steps()->forJob((int) $job['id']);
        $this->assertCount(count(\Wtp\Shared\Job\StepCatalog::all()), $steps);
        $this->assertSame('account.create', (string) $steps[0]['step_key']);
    }
}
