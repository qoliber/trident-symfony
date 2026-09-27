<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Config;

use Qoliber\Trident\Config\Settings;
use Qoliber\Trident\Config\SettingsResolver;
use Qoliber\Trident\Security\TokenVault;
use Qoliber\TridentSymfony\Storage\DbalSettingsStore;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The current {@see Settings}: environment + stored admin settings, resolved
 * once per request or worker message (`kernel.reset` clears it, and so does a
 * save), so a rotated token or a new URL is used by the next message of a
 * long-running worker.
 *
 * The stored token is opened here, for the stored URL only ({@see TokenVault});
 * the key is HKDF of `TRIDENT_TOKEN_KEY`, or of the kernel secret.
 */
class SettingsProvider implements ResetInterface
{
    public const ENV = [
        'TRIDENT_INSTANCES', 'TRIDENT_API_URL', 'TRIDENT_API_TOKEN', 'TRIDENT_ALLOWED_API_HOSTS',
        'TRIDENT_PURGE_MODE', 'TRIDENT_TAG_PREFIX', 'TRIDENT_DEBUG_HEADERS', 'TRIDENT_TOKEN_KEY',
    ];
    public const TOKEN_ERROR = 'The stored API token does not open for the current API URL (the URL or the secret changed): re-enter the token';

    public const READ_FAILED = Settings::READ_FAILED;

    private ?Settings $settings = null;
    private ?TokenVault $vault = null;
    private readonly \Closure $env;

    /**
     * @param (callable(string): (string|false|null))|null $env variable name => value (tests pass a fake)
     */
    public function __construct(
        private readonly DbalSettingsStore $store,
        private readonly string $kernelSecret,
        private readonly string $tokenInfo = TokenVault::DEFAULT_INFO,
        ?callable $env = null,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
    ) {
        $this->env = $env !== null ? \Closure::fromCallable($env) : static function (string $name): string|false|null {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

            return is_scalar($value) ? (string) $value : null;
        };
    }

    public function get(): Settings
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        try {
            $stored = $this->stored();
        } catch (\Throwable $e) {
            // Not cached: the next request reads again.
            $this->logger?->critical('Trident: ' . self::READ_FAILED, ['error' => $e->getMessage()]);

            return SettingsResolver::resolve($this->environment(), ['read_failed' => true]);
        }

        return $this->settings = SettingsResolver::resolve($this->environment(), $stored);
    }

    /**
     * The admin screen's view of the stored settings: never the token.
     *
     * @return array{api_url: ?string, has_token: bool, token_error: ?string, purge_mode: string}
     */
    public function adminView(): array
    {
        $raw = $this->store->all();
        $stored = $this->stored();

        return [
            'api_url' => $raw['api_url'] ?? null,
            'has_token' => isset($raw['api_token']) && $raw['api_token'] !== '',
            'token_error' => is_string($stored['token_error'] ?? null) ? $stored['token_error'] : null,
            'purge_mode' => ($raw['purge_mode'] ?? Settings::MODE_SOFT) === Settings::MODE_HARD ? Settings::MODE_HARD : Settings::MODE_SOFT,
        ];
    }

    /**
     * Save the admin settings. A new token is sealed to the URL it is saved
     * with; saving a new URL without a token keeps the old sealed value, which
     * then no longer opens (the admin is told to re-enter it).
     */
    public function save(?string $apiUrl, ?string $token, ?string $mode): void
    {
        $apiUrl = $apiUrl !== null ? trim($apiUrl) : null;
        if ($apiUrl !== null) {
            $this->store->set('api_url', $apiUrl === '' ? null : $apiUrl);
        }
        if ($token !== null && trim($token) !== '') {
            $url = $apiUrl ?? ($this->store->all()['api_url'] ?? '');
            $this->store->set('api_token', $this->vault()->seal(trim($token), $url));
        }
        if ($mode !== null) {
            $this->store->set('purge_mode', $mode === Settings::MODE_HARD ? Settings::MODE_HARD : Settings::MODE_SOFT);
        }
        $this->reset();
    }

    public function vault(): TokenVault
    {
        if ($this->vault === null) {
            $key = ($this->env)('TRIDENT_TOKEN_KEY');
            $this->vault = new TokenVault(is_string($key) && $key !== '' ? $key : $this->kernelSecret, $this->tokenInfo);
        }

        return $this->vault;
    }

    public function reset(): void
    {
        $this->settings = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $raw = $this->store->all();
        $stored = ['api_url' => $raw['api_url'] ?? null, 'purge_mode' => $raw['purge_mode'] ?? null, 'api_token' => null];
        $sealed = $raw['api_token'] ?? null;
        if (is_string($sealed) && $sealed !== '') {
            $token = TokenVault::isSealed($sealed) ? $this->vault()->open($sealed, (string) ($raw['api_url'] ?? '')) : null;
            if ($token === null) {
                $stored['token_error'] = self::TOKEN_ERROR;
            }
            $stored['api_token'] = $token;
        }

        return $stored;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = [];
        foreach (self::ENV as $name) {
            $value = ($this->env)($name);
            if (is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
