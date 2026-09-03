<?php
namespace Wtp\Tests;

use Wtp\Shared\Plan\ImportPlan;
use Wtp\Shared\Plan\PlanException;

final class ImportPlanTest extends TestCase
{
    public function testAcceptsAValidPlan(): void
    {
        $plan = ImportPlan::fromArray(PlanFixture::valid());
        $this->assertSame('astra', $plan->theme()['slug']);
        $this->assertSame('agency', $plan->demo()['slug']);
        $this->assertCount(2, $plan->plugins());
        $this->assertCount(2, $plan->steps());
        $this->assertTrue($plan->hasStep('content.import'));
        $this->assertFalse($plan->hasStep('customizer.import'));
    }

    public function testJsonRoundTripIsStable(): void
    {
        $a = ImportPlan::fromArray(PlanFixture::valid());
        $b = ImportPlan::fromJson($a->toJson());
        $this->assertSame($a->toJson(), $b->toJson());
        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testFingerprintChangesWithTheDemo(): void
    {
        $a = ImportPlan::fromArray(PlanFixture::valid());
        $b = ImportPlan::fromArray(PlanFixture::with('demo.slug', 'corporate'));
        $this->assertFalse($a->fingerprint() === $b->fingerprint());
    }

    public function testRejectsUnknownPlanVersion(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('version', 99));
        }, 'Unsupported plan version');
    }

    public function testRejectsMissingTheme(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::without('theme'));
        }, 'missing the theme');
    }

    public function testRejectsNonSlugThemeIdentifier(): void
    {
        foreach (['../evil', 'Astra Pro', 'astra/../..', ''] as $bad) {
            $this->assertThrows(PlanException::class, static function () use ($bad) {
                ImportPlan::fromArray(PlanFixture::with('theme.slug', $bad));
            });
        }
    }

    public function testAssetPackagesRequireAChecksum(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('theme.package.sha256', 'not-a-hash'));
        }, 'sha256 is required');
    }

    public function testUrlPackagesMustBeHttpsAndChecksummed(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('theme.package', [
                'kind' => 'url',
                'url' => 'http://cdn.example.com/astra.zip',
                'sha256' => str_repeat('a', 64),
            ]));
        }, 'absolute https URL');

        $this->assertDoesNotThrow(static function () {
            ImportPlan::fromArray(PlanFixture::with('theme.package', [
                'kind' => 'url',
                'url' => 'https://cdn.example.com/astra.zip',
                'sha256' => str_repeat('a', 64),
                'filename' => 'astra.zip',
            ]));
        });
    }

    public function testRejectsUnknownPackageKind(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('theme.package.kind', 'ftp'));
        }, 'kind must be one of');
    }

    public function testRejectsAssetIdThatIsNotAHandle(): void
    {
        foreach (['../../etc/passwd', 'da:', '88', 'da:abc'] as $bad) {
            $this->assertThrows(PlanException::class, static function () use ($bad) {
                ImportPlan::fromArray(PlanFixture::with('theme.package.asset_id', $bad));
            });
        }
    }

    public function testRejectsExecutableAssetFilenames(): void
    {
        foreach (['shell.php', 'x.phar', 'y.sh', '.htaccess'] as $bad) {
            $this->assertThrows(PlanException::class, static function () use ($bad) {
                ImportPlan::fromArray(PlanFixture::with('theme.package.filename', $bad));
            });
        }
    }

    public function testRejectsPathTraversalInFilenames(): void
    {
        $plan = ImportPlan::fromArray(PlanFixture::with('theme.package.filename', '../../wp-config.zip'));
        // basename() strips the traversal rather than accepting it
        $this->assertSame('wp-config.zip', $plan->theme()['package']['filename']);
    }

    public function testRejectsUnknownImporter(): void
    {
        $plan = PlanFixture::valid();
        $plan['steps'][0]['importer'] = 'exec';
        $this->assertThrows(PlanException::class, static function () use ($plan) {
            ImportPlan::fromArray($plan);
        }, 'not a known importer');
    }

    public function testRejectsDuplicateStepKeys(): void
    {
        $plan = PlanFixture::valid();
        $plan['steps'][1]['key'] = 'content.import';
        $this->assertThrows(PlanException::class, static function () use ($plan) {
            ImportPlan::fromArray($plan);
        }, 'Duplicate step key');
    }

    public function testRejectsTooManySteps(): void
    {
        $plan = PlanFixture::valid();
        $plan['steps'] = [];
        for ($i = 0; $i < 60; $i++) {
            $plan['steps'][] = ['key' => 'step.' . $i, 'importer' => 'options'];
        }
        $this->assertThrows(PlanException::class, static function () use ($plan) {
            ImportPlan::fromArray($plan);
        }, 'Too many steps');
    }

    public function testRejectsNestedValuesInOptions(): void
    {
        $plan = PlanFixture::valid();
        $plan['steps'][0]['options'] = ['nested' => ['a' => 1]];
        $this->assertThrows(PlanException::class, static function () use ($plan) {
            ImportPlan::fromArray($plan);
        }, 'must be a scalar');
    }

    public function testRejectsMaliciousPermalinkStructure(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('site.permalink_structure', '/<script>/'));
        }, 'Invalid permalink');
    }

    public function testStripsControlCharactersFromText(): void
    {
        $plan = ImportPlan::fromArray(PlanFixture::with('site.title', "Acme\x00\x07 Ltd"));
        $this->assertSame('Acme Ltd', $plan->site()['title']);
    }

    public function testRejectsInvalidLocale(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('site.language', '../../etc'));
        }, 'Invalid locale');
    }

    public function testPolicyBoundsAreEnforced(): void
    {
        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('policy.step_time_budget', 9999));
        }, 'between');

        $this->assertThrows(PlanException::class, static function () {
            ImportPlan::fromArray(PlanFixture::with('policy.max_download_bytes', 10));
        });
    }

    public function testDefaultsAreAppliedForOmittedBlocks(): void
    {
        $plan = ImportPlan::fromArray([
            'version' => 1,
            'theme' => [
                'slug' => 'twentytwentyfour',
                'package' => ['kind' => 'wporg', 'slug' => 'twentytwentyfour'],
            ],
            'demo' => ['slug' => 'default'],
        ]);
        $this->assertSame([], $plan->plugins());
        $this->assertSame([], $plan->steps());
        $this->assertSame(20, $plan->policy()['step_time_budget']);
        $this->assertTrue($plan->options()['replace_urls']);
    }

    public function testPlanCarriesNoSecrets(): void
    {
        $json = ImportPlan::fromArray(PlanFixture::valid())->toJson();
        foreach (['secret', 'token', 'password', 'sig=', 'Authorization'] as $forbidden) {
            $this->assertNotContains($forbidden, $json);
        }
    }
}
