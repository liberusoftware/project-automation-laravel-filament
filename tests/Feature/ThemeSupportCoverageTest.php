<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\ViewFinderInterface;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\Theme\Cache\ThemeCache;
use Liberu\Foundation\Theme\Discovery\ThemeDiscovery;
use Liberu\Foundation\Theme\Exceptions\InvalidTheme;
use Liberu\Foundation\Theme\Manifests\ThemeManifest;
use Liberu\Foundation\Theme\Providers\ThemeServiceProvider;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\Theme\Tests\Fixtures\ComposerAvailability;

function makeTempThemeDir(string $dirName, array $manifestOverrides = [], ?array $composerOverrides = [], bool $createCss = true): string
{
    $base = sys_get_temp_dir().'/liberu-theme-cov-'.bin2hex(random_bytes(5));
    $themeDir = $base.'/'.$dirName;
    File::ensureDirectoryExists($themeDir.'/resources/css');
    File::ensureDirectoryExists($themeDir.'/resources/views');

    if ($createCss) {
        File::put($themeDir.'/resources/css/theme.css', 'body {}');
    }

    $manifest = array_merge([
        'name' => $dirName,
        'display_name' => 'Temp Theme',
        'version' => '1.0.0',
        'type' => 'public',
        'provider' => ThemeServiceProvider::class,
        'parent' => null,
        'optimized_for' => ['web'],
        'tested_with' => ['laravel'],
        'required_capabilities' => [],
        'optional_capabilities' => ['analytics'],
        'supports' => ['web'],
        'assets' => ['css' => 'resources/css/theme.css'],
    ], $manifestOverrides);

    foreach ($manifest as $k => $v) {
        if ($v === '__UNSET__') {
            unset($manifest[$k]);
        }
    }

    File::put($themeDir.'/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    if ($composerOverrides !== null) {
        $composer = array_merge([
            'name' => 'liberusoftware/theme-'.$dirName,
            'type' => 'liberu-theme',
            'extra' => ['liberu' => ['name' => $dirName]],
        ], $composerOverrides);
        File::put($themeDir.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    }

    return $base;
}

it('covers ThemeCache unexpected nested objects and write/clear failures', function () {
    $tmp = sys_get_temp_dir().'/theme-cache-'.bin2hex(random_bytes(4)).'.cache';
    File::put($tmp, serialize(['nested' => [new stdClass()]]));

    $cache = new ThemeCache();
    expect(fn () => $cache->load($tmp))->toThrow(InvalidTheme::class);
    @unlink($tmp);

    $failingFs = Mockery::mock(Filesystem::class);
    $failingFs->shouldReceive('put')->andReturn(false);
    $failingFs->shouldReceive('isFile')->andReturn(true);
    $failingFs->shouldReceive('delete')->andReturn(false);

    $failingCache = new ThemeCache($failingFs);
    expect(fn () => $failingCache->write([], '/tmp/x.cache'))->toThrow(InvalidTheme::class, 'Unable to write')
        ->and(fn () => $failingCache->clear('/tmp/x.cache'))->toThrow(InvalidTheme::class, 'Unable to clear');
});

it('covers ThemeManifest validation errors and getters', function () {
    $base = makeTempThemeDir('good-theme');
    $manifest = ThemeManifest::fromFile($base.'/good-theme/theme.json');
    expect($manifest->version())->toBe('1.0.0')
        ->and($manifest->type())->toBe('public')
        ->and($manifest->optionalCapabilities())->toBe(['analytics']);
    File::deleteDirectory($base);

    $badCases = [
        ['dir' => 'missing-key', 'over' => ['display_name' => '__UNSET__'], 'css' => true],
        ['dir' => 'Bad_Name', 'over' => ['name' => 'Bad_Name'], 'css' => true],
        ['dir' => 'bad-type', 'over' => ['type' => 'email'], 'css' => true],
        ['dir' => 'bad-asset', 'over' => ['assets' => ['css' => '../escape.css']], 'css' => false],
    ];

    foreach ($badCases as $case) {
        $dir = makeTempThemeDir($case['dir'], $case['over'], [], $case['css']);
        expect(fn () => ThemeManifest::fromFile($dir.'/'.$case['dir'].'/theme.json'))->toThrow(InvalidTheme::class);
        File::deleteDirectory($dir);
    }
});

it('covers ThemeDiscovery collision, composer, provider, and empty errors', function () {
    // 1. Directory/name mismatch
    $b1 = makeTempThemeDir('dir-one', ['name' => 'other-name']);
    expect(fn () => (new ThemeDiscovery([]))->discover($b1))->toThrow(InvalidTheme::class);
    File::deleteDirectory($b1);

    // 2. Duplicate theme name across tracked + installed paths
    $b2 = makeTempThemeDir('dup-theme');
    $b2b = makeTempThemeDir('dup-theme');
    expect(fn () => (new ThemeDiscovery([$b2b.'/dup-theme']))->discover($b2))->toThrow(InvalidTheme::class);
    File::deleteDirectory($b2);
    File::deleteDirectory($b2b);

    // 3. Missing composer.json
    $b3 = makeTempThemeDir('no-composer', [], null);
    expect(fn () => (new ThemeDiscovery([]))->discover($b3))->toThrow(InvalidTheme::class);
    File::deleteDirectory($b3);

    // 4. Inconsistent composer.json
    $b4 = makeTempThemeDir('bad-composer', [], ['type' => 'library']);
    expect(fn () => (new ThemeDiscovery([]))->discover($b4))->toThrow(InvalidTheme::class);
    File::deleteDirectory($b4);

    // 5. Missing provider class
    $b5 = makeTempThemeDir('bad-provider', ['provider' => 'App\\NonExistentThemeProvider']);
    expect(fn () => (new ThemeDiscovery([]))->discover($b5))->toThrow(InvalidTheme::class);
    File::deleteDirectory($b5);

    // 6. Empty themes directory
    $empty = sys_get_temp_dir().'/empty-themes-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($empty);
    expect(fn () => (new ThemeDiscovery([]))->discover($empty))->toThrow(InvalidTheme::class);
    File::deleteDirectory($empty);

    // 7. Composer InstalledVersions unavailable branch (line 87) + directory without theme.json (line 45)
    ComposerAvailability::$available = false;
    $b7 = makeTempThemeDir('no-composer-versions');
    File::ensureDirectoryExists($b7.'/not-a-theme');
    expect((new ThemeDiscovery())->discover($b7))->toHaveKey('no-composer-versions');
    ComposerAvailability::$available = true;
    File::deleteDirectory($b7);
});

it('covers ThemeManager branches and theme_helpers', function () {
    $manager = app(ThemeManager::class);

    // theme_helpers.php guards + theme_layout
    require base_path('modules/theme-support/src/Support/theme_helpers.php');
    expect(theme_layout('app'))->toBeString();

    // selectForSurface
    config()->set('theme.surfaces.web', 'default');
    expect($manager->selectForSurface('web', 'site-1', 'tenant-1'))->toBe('default');

    // getThemeAssetPath
    expect($manager->getThemeAssetPath('views'))->toBeString();

    // activeEntries + getThemeJs (returns null when no js asset -> covers line 230)
    expect($manager->activeEntries())->toBeArray();
    $manager->getThemeJs();

    // viteHasAsset false branch (line 244)
    expect($manager->viteHasAsset('resources/css/nonexistent-xyz.css'))->toBeFalse();

    // unsafe asset path (line 212)
    expect(fn () => $manager->assetUrl('../secret.css'))->toThrow(InvalidTheme::class);

    // hasCustomLayout false branch (line 278)
    expect($manager->hasCustomLayout('nonexistent-layout-xyz'))->toBeFalse();

    // themeIsCompatible with required_capabilities (lines 133, 288-305 + cache hit)
    $capBase = makeTempThemeDir('cap-theme', ['required_capabilities' => ['some-cap']]);
    $capManifest = ThemeManifest::fromFile($capBase.'/cap-theme/theme.json');
    $refThemesProp = new ReflectionProperty($manager, 'themes');
    $savedThemes = $refThemesProp->getValue($manager);
    $refThemesProp->setValue($manager, array_merge($savedThemes, ['cap-theme' => $capManifest]));
    expect($manager->themeIsCompatible('cap-theme'))->toBeFalse();
    // Second call hits capabilitySnapshot cache (line 298)
    expect($manager->themeIsCompatible('cap-theme'))->toBeFalse();
    // Missing js asset returns null (line 230)
    expect($manager->getThemeJs('cap-theme'))->toBeNull();

    // enabledCapabilities when ModuleRegistry is unbound (lines 288-289)
    $prevReg = app(ModuleRegistry::class);
    app()->offsetUnset(ModuleRegistry::class);
    expect($manager->themeIsCompatible('cap-theme'))->toBeFalse();
    app()->instance(ModuleRegistry::class, $prevReg);
    $refThemesProp->setValue($manager, $savedThemes);
    File::deleteDirectory($capBase);

    // viteHasAsset when manifest file does not exist (line 244)
    $manifestPath = public_path('build/manifest.json');
    $manifestBackup = File::isFile($manifestPath) ? File::get($manifestPath) : null;
    if ($manifestBackup !== null) {
        File::delete($manifestPath);
    }
    expect($manager->viteHasAsset('resources/css/app.css'))->toBeFalse();
    if ($manifestBackup !== null) {
        File::put($manifestPath, $manifestBackup);
    }

    // clearCache (lines 264-267)
    $cacheFile = sys_get_temp_dir().'/theme-mgr-test-'.bin2hex(random_bytes(4)).'.cache';
    config()->set('theme.cache_path', $cacheFile);
    $manager->clearCache();

    // Fallback theme missing throws InvalidTheme (line 37)
    $origFallback = config('theme.fallback');
    config()->set('theme.fallback', 'missing-fallback-xyz');
    expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class);
    config()->set('theme.fallback', $origFallback);

    // Cache enabled with missing cache throws InvalidTheme (line 48), with valid cache loads (line 50)
    config()->set('theme.cache', true);
    expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class);

    (new ThemeCache())->write((new ThemeDiscovery())->discover((string) config('theme.path', base_path('themes'))), $cacheFile);
    $cachedMgr = new ThemeManager();
    expect($cachedMgr->getThemes())->not->toBeEmpty();
    @unlink($cacheFile);
    config()->set('theme.cache', false);

    // Inheritance cycle detection (line 166)
    $cycleBase = makeTempThemeDir('cycle-a', ['parent' => 'cycle-b']);
    $cycleB = $cycleBase.'/cycle-b';
    File::ensureDirectoryExists($cycleB.'/resources/css');
    File::put($cycleB.'/resources/css/theme.css', 'body{}');
    File::put($cycleB.'/theme.json', json_encode([
        'name' => 'cycle-b',
        'display_name' => 'Cycle B',
        'version' => '1.0.0',
        'type' => 'public',
        'provider' => ThemeServiceProvider::class,
        'parent' => 'cycle-a',
        'optimized_for' => ['web'],
        'tested_with' => ['laravel'],
        'required_capabilities' => [],
        'optional_capabilities' => [],
        'supports' => ['web'],
        'assets' => ['css' => 'resources/css/theme.css'],
    ], JSON_THROW_ON_ERROR));
    File::put($cycleB.'/composer.json', json_encode([
        'name' => 'liberusoftware/theme-cycle-b',
        'type' => 'liberu-theme',
        'extra' => ['liberu' => ['name' => 'cycle-b']],
    ], JSON_THROW_ON_ERROR));

    $cycleManifests = (new ThemeDiscovery([]))->discover($cycleBase);
    $refThemes = new ReflectionProperty($manager, 'themes');
    $origThemes = $refThemes->getValue($manager);
    $refThemes->setValue($manager, $cycleManifests);
    expect(fn () => $manager->inheritanceChain('cycle-a'))->toThrow(InvalidTheme::class);
    $refThemes->setValue($manager, $origThemes);
    File::deleteDirectory($cycleBase);

    // registerThemePaths early return when finder is not FileViewFinder (line 180)
    $origFinder = View::getFinder();
    View::setFinder(Mockery::mock(ViewFinderInterface::class));
    $manager->registerThemePaths();
    View::setFinder($origFinder);

    // enabledCapabilities when ModuleRegistry is unbound (lines 288-289)
    $prevReg = app(ModuleRegistry::class);
    app()->offsetUnset(ModuleRegistry::class);
    $freshMgr = new ThemeManager();
    expect($freshMgr->themeIsCompatible('default'))->toBeTrue();
    app()->instance(ModuleRegistry::class, $prevReg);
});
