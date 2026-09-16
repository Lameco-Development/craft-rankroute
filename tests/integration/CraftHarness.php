<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Session;
use craft\migrations\Install as CraftInstall;
use craft\models\Site;
use FilesystemIterator;
use lameco\rankroute\Plugin;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use yii\base\Event;
use yii\caching\ArrayCache;

/**
 * Boots a real Craft console app against a disposable test database, the way Craft's own
 * `craft\test\TestSetup` does it, minus the Codeception layer (see ADR 0001).
 *
 * Once per process: bootstrap Craft, drop every table, run Craft's install migration and
 * install this plugin. Fixtures (sections, fields) are seeded by the tests that need them.
 * Per test: rebuild the app from the captured config — cheap, and it
 * resets every memoised service — while the test wraps itself in a rolled-back DB
 * transaction, which is the same cleanup strategy Codeception's Yii2 module uses.
 */
final class CraftHarness
{
    /** @var array<string, mixed>|null */
    private static ?array $appConfig = null;
    private static bool $bootstrapped = false;
    private static bool $installed = false;

    /**
     * Called from `craft_modify_app_config()` while Craft's own bootstrap runs.
     *
     * @param array<string, mixed> $config
     */
    public static function captureAppConfig(array &$config): void
    {
        // The one app id craft\mutex\Mutex special-cases to a NullMutex — the same id
        // Craft's own TestSetup runs under. Any other id gets the DB mutex, whose own
        // extra connection outlives every torn-down app, still holding whatever project
        // config locked, until the suite deadlocks itself.
        $config['id'] = 'craft-test';

        // A per-app in-memory cache instead of Craft's default: every rebuilt app starts
        // with nothing cached, so no listing or folder cache survives into the next test.
        $config['components']['cache'] = ['class' => ArrayCache::class];

        self::$appConfig = $config;
    }

    /**
     * Boot Craft and (re)install the test schema. Idempotent; the schema is rebuilt from
     * scratch once per PHPUnit process so every run starts from a known state.
     */
    public static function ensureInstalled(): void
    {
        if (self::$installed) {
            return;
        }

        $testsDir = dirname(__DIR__);
        self::loadEnvFile($testsDir . '/.env');
        self::applyEnvDefaults($testsDir);
        self::guardDatabaseName();

        // Storage is wiped, not just created. Installing a plugin writes it into the
        // persisted project config under storage/config-deltas, which outlives the process
        // (storage is gitignored but not cleaned). On the next run installSchema() drops
        // every table and Craft then replays that config during install, so SEOmatic boots
        // and queries seomatic_metabundles before its own migration has created it. CI never
        // sees this because a fresh checkout has no storage; locally it fails every other run.
        self::resetDirectory($testsDir . '/_craft/storage');

        if (!is_dir($testsDir . '/_craft/templates')) {
            mkdir($testsDir . '/_craft/templates', 0775, true);
        }

        // No Yii error handler: PHPUnit must keep owning the process's error handling, or
        // every test that boots Craft is flagged risky for swapping the global handlers.
        defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);

        // Dropped before Craft boots, not inside installSchema(). A previous run leaves
        // seomatic in the plugins table; the first boot would load it and register its
        // class-level Event handlers, which are global and survive every later freshApp().
        // Dropping the schema afterwards leaves those handlers live, and they then query
        // seomatic_metabundles during the next install, before its migration recreates it.
        // With an empty database here, no plugin is ever loaded from stale state.
        self::dropAllTables();

        // Craft's bootstrap requires Yii.php unconditionally, with no include-once guard. If
        // installSchema() below throws, $installed stays false and the next test's setUp()
        // calls back in here — a second require then fatals with "Cannot redeclare class Yii"
        // and takes the process down, hiding the original failure. Tracking the require
        // separately from the install keeps that first error readable.
        if (!self::$bootstrapped) {
            require dirname(__DIR__, 2) . '/vendor/craftcms/cms/bootstrap/console.php';

            if (self::$appConfig === null) {
                throw new RuntimeException('Craft booted without calling craft_modify_app_config() — is tests/bootstrap.php the PHPUnit bootstrap?');
            }

            self::$bootstrapped = true;
        }

        self::installSchema();
        self::$installed = true;
    }

    /**
     * Tear down the current app and build a fresh one from the captured config. Every
     * memoised service state goes with the old instance.
     */
    public static function freshApp(): void
    {
        self::teardownApp();
        Craft::createObject(self::$appConfig);
    }

    public static function teardownApp(): void
    {
        if (Craft::$app === null) {
            return;
        }

        try {
            Craft::$app->getDb()->close();
        } catch (Throwable) {
            // A test that broke the connection should not also break the teardown.
        }

        Event::offAll();
        Craft::setLogger(null);
        // The statics Craft's own CraftConnector resets between tests. Db above all: the
        // Db helper memoises a Connection, and letting it outlive the app splits writes
        // over two connections whose transactions then block each other.
        Db::reset();
        Session::reset();
        /** @phpstan-ignore assign.propertyType (between apps there is genuinely no app; Craft's own TestSetup does the same) */
        Craft::$app = null;

        // The app graph is cyclic, so anything it still holds open — connections, file
        // handles — only goes away once the cycle collector runs. Waiting for an arbitrary
        // later GC would leak resources into the next test's app.
        gc_collect_cycles();
    }

    /**
     * Replaces the console app's `request` and `response` components with their
     * `craft\web` equivalents.
     *
     * The harness boots Craft as a console app (see the class docblock), so
     * `Craft::$app->getRequest()`/`getResponse()` are `craft\console` objects by default —
     * neither has `getHeaders()`/`asJson()`'s `format`, which `craft\web\Controller` and our
     * actions both need. Dispatching one of this plugin's actions needs the real things.
     */
    public static function useWebRequest(): void
    {
        $_SERVER['HTTP_HOST'] = 'rankroute.test';
        $_SERVER['SERVER_NAME'] = 'rankroute.test';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['QUERY_STRING'] = '';

        Craft::$app->set('request', array_merge(App::webRequestConfig(), [
            'isConsoleRequest' => false,
        ]));
        Craft::$app->set('response', App::webResponseConfig());

        // The app booted as a console app, so craft\base\Plugin guessed a console controller
        // namespace when it first loaded the plugin (vendor/craftcms/cms/src/base/Plugin.php:116-121).
        // Point it at the web controllers for the dispatch under test; production never hits
        // this because a real /actions/ request boots a web app.
        self::plugin()->controllerNamespace = 'lameco\\rankroute\\controllers';
    }

    public static function plugin(): Plugin
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('rankroute');

        if (!$plugin instanceof Plugin) {
            throw new RuntimeException('The rankroute plugin is not installed in the test app.');
        }

        return $plugin;
    }

    /**
     * Drop every table with a plain PDO connection, before any Craft class is loaded.
     * MySQL-only on purpose: the suite targets the MySQL service container CI runs.
     */
    private static function dropAllTables(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            App::env('CRAFT_DB_SERVER'),
            App::env('CRAFT_DB_PORT'),
            App::env('CRAFT_DB_DATABASE'),
        );

        $pdo = new PDO($dsn, App::env('CRAFT_DB_USER'), App::env('CRAFT_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function installSchema(): void
    {
        $db = Craft::$app->getDb();

        $migration = new CraftInstall([
            'db' => $db,
            'username' => 'tester',
            'password' => 'rankroute-tests-2026!!',
            'email' => 'dev@lameco.nl',
            'site' => new Site([
                'name' => 'RankRoute test site',
                'handle' => 'default',
                'hasUrls' => true,
                'baseUrl' => 'https://rankroute.test/',
                'language' => 'en-US',
                'primary' => true,
            ]),
        ]);
        $migration->up(true);

        // The app cached "not installed" before the migration ran.
        self::freshApp();

        if (!Craft::$app->getPlugins()->installPlugin('rankroute')) {
            throw new RuntimeException('Could not install the rankroute plugin into the test schema.');
        }

        // SEOmatic is installed here, before this plugin's own init() runs again (via the
        // freshApp() below), because Plugin::init() only registers the SEOmatic field
        // handler when the seomatic plugin is already installed and enabled.
        if (!Craft::$app->getPlugins()->installPlugin('seomatic')) {
            throw new RuntimeException('Could not install the seomatic plugin into the test schema.');
        }

        // CKEditor is a dev dependency only: the text flow detects its field class by name,
        // and the text-flow fixture needs real CKEditor fields to prove HTML handling.
        if (!Craft::$app->getPlugins()->installPlugin('ckeditor')) {
            throw new RuntimeException('Could not install the ckeditor plugin into the test schema.');
        }

        // Craft only persists project config changes when a request ends, and nothing here
        // ever ends a request — without this flush the plugin would evaporate with the app
        // instance that installed it.
        Craft::$app->getProjectConfig()->saveModifiedConfigData();

        self::freshApp();
    }

    /**
     * Delete a directory's contents and leave it empty, creating it when absent.
     */
    private static function resetDirectory(string $path): void
    {
        if (is_dir($path)) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                /** @var SplFileInfo $entry */
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }

            return;
        }

        mkdir($path, 0775, true);
    }

    /**
     * Minimal KEY=VALUE loader so the harness needs no dotenv dependency. Existing
     * environment variables win, which is how CI overrides the local file.
     */
    private static function loadEnvFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            self::setEnv($name, trim(trim($value), '"\''));
        }
    }

    private static function applyEnvDefaults(string $testsDir): void
    {
        $defaults = [
            'CRAFT_BASE_PATH' => $testsDir . '/_craft',
            'CRAFT_DOTENV_PATH' => $testsDir . '/.env',
            'CRAFT_SECURITY_KEY' => 'rankroute-tests-not-a-secret',
            // Ephemeral keeps Craft from writing project config YAML and a license key
            // file — the test database alone holds the seeded schema.
            'CRAFT_EPHEMERAL' => '1',
        ];

        foreach ($defaults as $name => $value) {
            if (getenv($name) === false) {
                self::setEnv($name, $value);
            }
        }
    }

    /**
     * All three channels, because Craft's App::env() reads $_SERVER before getenv().
     */
    public static function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public static function unsetEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    /**
     * The harness drops every table in the configured database, so refuse anything that
     * does not announce itself as a test database. This is what stands between a typo in
     * tests/.env and an emptied development database.
     */
    private static function guardDatabaseName(): void
    {
        $database = getenv('CRAFT_DB_DATABASE');

        if ($database === false || $database === '') {
            throw new RuntimeException(
                'Integration tests need a database: copy tests/.env.example to tests/.env, '
                . 'create the database it names, or export the CRAFT_DB_* variables.',
            );
        }

        if (!str_contains($database, 'test')) {
            throw new RuntimeException(
                "Refusing to run against '{$database}': the harness drops every table in the "
                . "database, so its name must contain 'test'.",
            );
        }
    }
}
