<?php

namespace Phasync\Doctor;

/** The scanned application: its root, and the framework and swerve adapter its composer.lock names. */
final class Project
{
    public const GUIDE = 'https://phasync.github.io/guides/concurrent-requests-in-your-application/';

    private const SYNCHRONIZED  = 'one request at a time per worker, behind a phasync\Util\Synchronized lock';
    private const ONE_AT_A_TIME = ' A request that waits (a query, an API call) holds its worker, also with ext-phasync: capacity scales with --workers, sized as PHP-FPM children. Statics, settings and services still outlive the request: what one request leaves behind, the next one sees.';

    /**
     * Recognised frameworks, in detection order: package => [name, adapter package, concurrency, note].
     *
     * @var array<string, array{string, string, string, string}>
     */
    private const FRAMEWORKS = [
        'laravel/framework' => ['Laravel', 'phasync/swerve-laravel', 'concurrent',
            'swerve-laravel runs requests concurrently, each in an application of its own from a pool (phasync\Util\Pool), reset by Octane\'s listeners; a lock is held only around echoing streamed responses. Laravel\'s own state is per application; statics and globals in your code are not reset. Views buffer output: a wait while a view renders (a lazily loaded relation, with ext-phasync) mixes the output of requests that overlap there.'],
        'symfony/framework-bundle' => ['Symfony', 'phasync/swerve-symfony', 'concurrent',
            'swerve-symfony runs requests concurrently, each on a kernel of its own from a pool (phasync\Util\Pool); kernel.reset services are reset between requests. Statics of your own are shared by the kernels of a worker. PHP\'s session module is one per process: use Swerve\Symfony\SessionStorageFactory.'],
        'mezzio/mezzio' => ['Mezzio', 'phasync/swerve-psr15', 'concurrent',
            'swerve-psr15 runs PSR-15 requests concurrently in one worker: a middleware or handler keeping request state in a property, or a container definition handing out one shared instance (a PDO connection), is shared by overlapping requests.'],
        'slim/slim' => ['Slim', 'phasync/swerve-psr15', 'concurrent',
            'swerve-psr15 runs PSR-15 requests concurrently in one worker: a middleware or handler keeping request state in a property, or a container definition handing out one shared instance (a PDO connection), is shared by overlapping requests.'],
        'yiisoft/yii-http' => ['Yii 3', 'phasync/swerve-yii', 'one-at-a-time',
            'swerve-yii runs ' . self::SYNCHRONIZED . ': Yii keeps a request\'s state in shared container services (reset by StateResetter after each request) and the session is PHP\'s native one per process. A service of your own that keeps request state needs a `reset` callback in its definition.' . self::ONE_AT_A_TIME],
        'codeigniter4/framework' => ['CodeIgniter 4', 'phasync/swerve-codeigniter', 'one-at-a-time',
            'swerve-codeigniter runs ' . self::SYNCHRONIZED . ': CodeIgniter shares its services, config() and model() instances, the default locale, $_SESSION and one database connection per worker. Services in WorkerMode::$persistentServices live as long as the worker.' . self::ONE_AT_A_TIME],
        'cakephp/cakephp' => ['CakePHP', 'phasync/swerve-cakephp', 'one-at-a-time',
            'swerve-cakephp runs ' . self::SYNCHRONIZED . ': Router\'s current request, Configure, the global event manager, the table registry, the locale, the time zone, the session and the output buffers are CakePHP\'s per-process state; the adapter restores them after boot for each request.' . self::ONE_AT_A_TIME],
        'laminas/laminas-mvc' => ['Laminas MVC', 'phasync/swerve-laminas', 'one-at-a-time',
            'swerve-laminas runs ' . self::SYNCHRONIZED . ': $_SERVER, PHP\'s session and $_SESSION, laminas-session\'s default session manager and view-helper statics belong to the worker, not the request. Mezzio runs concurrently on swerve-psr15.' . self::ONE_AT_A_TIME],
        'spiral/framework' => ['Spiral', 'phasync/swerve-spiral', 'one-at-a-time',
            'swerve-spiral runs ' . self::SYNCHRONIZED . ': output buffers around controllers and views, PHP\'s native session, the ORM heap and the http scope\'s current request are per process; ContainerScope is swapped per request with phasync::onScopeChange().' . self::ONE_AT_A_TIME],
    ];

    /**
     * @param array{name: string, package: string, version: ?string}|null                         $framework
     * @param array{package: string, version: ?string, installed: bool, concurrency: string}|null $adapter
     */
    public function __construct(
        public readonly ?string $root = null,
        public readonly ?array $framework = null,
        public readonly ?array $adapter = null,
        /** @var list<string> real paths of composer.json's autoload-dev entries: test code, never loaded by a worker */
        public readonly array $devPaths = [],
    ) {
    }

    /**
     * Finds the project of a scanned path: the nearest directory above it, outside any vendor/
     * directory, with a composer.json or composer.lock, and reads the packages from the lock file
     * (or from composer.json when there is no lock file).
     */
    public static function detect(string $path): self
    {
        $start = \realpath($path);
        if (false === $start) {
            return new self();
        }
        $start = \is_dir($start) ? $start : \dirname($start);
        $root  = self::nearest($start);
        if (null === $root) {
            return new self();
        }

        $devPaths = [];
        if (\is_file("$root/composer.json")) {
            $dev = \json_decode((string) \file_get_contents("$root/composer.json"), true)['autoload-dev'] ?? [];
            foreach ([...$dev['psr-4'] ?? [], ...$dev['psr-0'] ?? [], ...$dev['classmap'] ?? [], ...$dev['files'] ?? []] as $paths) {
                foreach ((array) $paths as $p) {
                    $real = \realpath("$root/$p");
                    if (false !== $real && !\str_contains("$real/", '/vendor/')) {
                        $devPaths[] = $real;
                    }
                }
            }
        }

        $packages = [];
        if (\is_file("$root/composer.lock")) {
            $lock = \json_decode((string) \file_get_contents("$root/composer.lock"), true);
            foreach ([...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []] as $p) {
                $packages[$p['name']] = $p['version'] ?? null;
            }
        } else {
            $json = \json_decode((string) \file_get_contents("$root/composer.json"), true);
            foreach ([...$json['require'] ?? [], ...$json['require-dev'] ?? []] as $name => $constraint) {
                $packages[$name] = null;
            }
        }

        foreach (self::FRAMEWORKS as $package => [$name, $adapter, $concurrency]) {
            if (\array_key_exists($package, $packages)) {
                return new self(
                    $root,
                    ['name' => $name, 'package' => $package, 'version' => $packages[$package]],
                    ['package' => $adapter, 'version' => $packages[$adapter] ?? null, 'installed' => \array_key_exists($adapter, $packages), 'concurrency' => $concurrency],
                    $devPaths,
                );
            }
        }
        foreach ($packages as $package => $version) {
            if (\str_starts_with($package, 'phasync/swerve-')) {
                return new self($root, null, ['package' => $package, 'version' => $version, 'installed' => true, 'concurrency' => 'concurrent'], $devPaths);
            }
        }

        return new self($root, devPaths: $devPaths);
    }

    /** True when the adapter runs one request at a time per worker. */
    public function oneAtATime(): bool
    {
        return 'one-at-a-time' === ($this->adapter['concurrency'] ?? null);
    }

    /** What the framework and adapter mean for the findings, in a few sentences. */
    public function note(): string
    {
        if (null === $this->framework) {
            return null === $this->adapter
                ? 'No framework recognised. Under swerve, requests run concurrently in one worker unless an adapter serialises them; every finding below applies.'
                : $this->adapter['package'] . ' is installed; requests may run concurrently in one worker.';
        }
        $note = self::FRAMEWORKS[$this->framework['package']][3];
        if (!$this->adapter['installed']) {
            $note .= ' The adapter is not installed: composer require ' . $this->adapter['package'] . '.';
        }

        return $note;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'root'      => $this->root,
            'framework' => $this->framework,
            'adapter'   => $this->adapter,
            'note'      => $this->note(),
            'guide'     => self::GUIDE,
        ];
    }

    private static function nearest(string $dir): ?string
    {
        for (;; $dir = \dirname($dir)) {
            if ((\is_file("$dir/composer.json") || \is_file("$dir/composer.lock")) && !\str_contains("$dir/", '/vendor/')) {
                return $dir;
            }
            if (\dirname($dir) === $dir) {
                return null;
            }
        }
    }
}
