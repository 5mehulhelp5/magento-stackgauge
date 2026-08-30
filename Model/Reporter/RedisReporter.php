<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Credis_Client;
use Magento\Framework\App\DeploymentConfig;
use StackNuts\ViewGento\Api\Field\ArrayField;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Reachability + version for every Redis-backed cache frontend and the session backend,
 * checked separately since the doc's own data-collection list calls out that cache and
 * session can be entirely different Redis instances. Uses Credis_Client rather than the
 * phpredis extension directly - Credis is a transitive dependency of magento/framework
 * itself (via colinmollenhour/php-redis-session-abstract), present on every real Magento
 * install whether or not phpredis is compiled in, and it already prefers the native
 * extension when available and falls back to plain sockets otherwise.
 */
class RedisReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '2.0';

    /**
     * A slow/unreachable Redis must not stall the whole report - this is collection-time
     * I/O, not the final HTTP send, so the same "never hang" discipline applies here too.
     */
    private const CONNECT_TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function getName(): string
    {
        return 'redis';
    }

    public function getLabel(): string
    {
        return 'Redis';
    }

    public function getDescription(): string
    {
        return 'Reachability and version of Redis-backed cache and session backends, checked separately.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $backends = [];

        foreach ((array)$this->deploymentConfig->get('cache/frontend', []) as $frontendId => $frontend) {
            if (($frontend['backend'] ?? null) === 'redis') {
                $backends[] = $this->checkBackend(
                    'cache_' . $frontendId,
                    (array)($frontend['backend_options'] ?? [])
                );
            }
        }

        $session = (array)$this->deploymentConfig->get('session', []);
        if (($session['save'] ?? null) === 'redis') {
            $backends[] = $this->checkBackend('session', (array)($session['redis'] ?? []));
        }

        return ['backends' => Field::array('Backends', $backends)];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function checkBackend(string $purpose, array $options): ArrayField
    {
        $host = (string)($options['server'] ?? $options['host'] ?? '');
        $port = (int)($options['port'] ?? 6379);
        $database = (int)($options['database'] ?? 0);
        $password = $options['password'] ?? null;

        if ($host === '') {
            return $this->backendField($purpose, false, null);
        }

        try {
            $client = new Credis_Client($host, $port, self::CONNECT_TIMEOUT_SECONDS, '', $database, $password ?: null);
            $client->setMaxConnectRetries(0);
            $info = $client->info();

            return $this->backendField($purpose, true, $info['redis_version'] ?? null);
        } catch (Throwable) {
            return $this->backendField($purpose, false, null);
        }
    }

    private function backendField(string $purpose, bool $reachable, ?string $version): ArrayField
    {
        return Field::array($purpose, [
            'purpose' => Field::varchar('Purpose', $purpose),
            'reachable' => Field::bool('Reachable', $reachable),
            'version' => Field::varchar('Version', $version ?? ''),
        ]);
    }
}
