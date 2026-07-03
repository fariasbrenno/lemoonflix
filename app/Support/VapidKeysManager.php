<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Minishlink\WebPush\VAPID;

class VapidKeysManager
{
    public function __construct(
        private ?string $envPath = null,
        private ?string $sharedVapidPath = null,
    ) {}

    /**
     * @return array{
     *     configured: bool,
     *     public_key: string|null,
     *     env_writable: bool,
     *     env_exists: bool,
     *     shared_file_exists: bool
     * }
     */
    public function status(): array
    {
        $envPath = $this->resolveEnvPath();
        $envExists = is_file($envPath);
        $content = $envExists ? (string) file_get_contents($envPath) : '';
        $public = $this->readEnvValue($content, 'PWA_VAPID_PUBLIC');
        $private = $this->readEnvValue($content, 'PWA_VAPID_PRIVATE');
        $configured = VapidEnvKeys::normalizedPairLooksValid($public, $private);

        return [
            'configured' => $configured,
            'public_key' => $configured ? VapidEnvKeys::normalize($public) : null,
            'env_writable' => $envExists && is_writable($envPath),
            'env_exists' => $envExists,
            'shared_file_exists' => is_file($this->resolveSharedVapidPath()),
        ];
    }

    /**
     * @return array{
     *     success: bool,
     *     configured?: bool,
     *     already_configured?: bool,
     *     public_key?: string|null,
     *     message: string,
     *     error?: string
     * }
     */
    public function generate(bool $force = false): array
    {
        $envPath = $this->resolveEnvPath();
        if (! is_file($envPath)) {
            return [
                'success' => false,
                'message' => 'Arquivo .env não encontrado.',
                'error' => 'env_missing',
            ];
        }

        if (! is_writable($envPath)) {
            return [
                'success' => false,
                'message' => 'Sem permissão para gravar o arquivo .env. Rode php artisan pwa:vapid no servidor.',
                'error' => 'env_not_writable',
            ];
        }

        $content = (string) file_get_contents($envPath);
        $existingPublic = $this->readEnvValue($content, 'PWA_VAPID_PUBLIC');
        $existingPrivate = $this->readEnvValue($content, 'PWA_VAPID_PRIVATE');

        if (! $force && VapidEnvKeys::normalizedPairLooksValid($existingPublic, $existingPrivate)) {
            return [
                'success' => true,
                'already_configured' => true,
                'configured' => true,
                'public_key' => VapidEnvKeys::normalize($existingPublic),
                'message' => 'Chaves VAPID já configuradas e válidas.',
            ];
        }

        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'generation_failed',
            ];
        }

        $publicKey = $keys['publicKey'];
        $privateKey = $keys['privateKey'];

        try {
            VAPID::validate([
                'subject' => 'mailto:validate@example.invalid',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Chaves geradas falharam na validação: '.$e->getMessage(),
                'error' => 'validation_failed',
            ];
        }

        $written = $this->writeKeysToEnv($content, $publicKey, $privateKey);
        if (! $written) {
            return [
                'success' => false,
                'message' => 'Falha ao gravar chaves no .env.',
                'error' => 'write_failed',
            ];
        }

        $this->syncSharedVapidFile($publicKey, $privateKey);
        $this->refreshRuntimeConfig();

        return [
            'success' => true,
            'configured' => true,
            'public_key' => $publicKey,
            'message' => $force
                ? 'Chaves VAPID regeneradas e salvas no .env. Usuários com push ativo devem reativar notificações no painel.'
                : 'Chaves VAPID geradas e salvas automaticamente no .env.',
        ];
    }

    private function writeKeysToEnv(string $content, string $publicKey, string $privateKey): bool
    {
        $hasPublic = (bool) preg_match('/^PWA_VAPID_PUBLIC=/m', $content);
        $hasPrivate = (bool) preg_match('/^PWA_VAPID_PRIVATE=/m', $content);

        $publicEscaped = '"'.str_replace('"', '\\"', $publicKey).'"';
        $privateEscaped = '"'.str_replace('"', '\\"', $privateKey).'"';

        if ($hasPublic) {
            $content = (string) preg_replace('/^PWA_VAPID_PUBLIC=.*/m', 'PWA_VAPID_PUBLIC='.$publicEscaped, $content);
        } else {
            $content .= "\n# PWA Painel: chaves VAPID (geradas via php artisan pwa:vapid)\n";
            $content .= 'PWA_VAPID_PUBLIC='.$publicEscaped."\n";
        }
        if ($hasPrivate) {
            $content = (string) preg_replace('/^PWA_VAPID_PRIVATE=.*/m', 'PWA_VAPID_PRIVATE='.$privateEscaped, $content);
        } else {
            $content .= 'PWA_VAPID_PRIVATE='.$privateEscaped."\n";
        }

        return file_put_contents($this->resolveEnvPath(), $content) !== false;
    }

    private function syncSharedVapidFile(string $publicKey, string $privateKey): void
    {
        $sharedPath = $this->resolveSharedVapidPath();
        $out = 'PWA_VAPID_PUBLIC="'.str_replace('"', '\\"', $publicKey)."\"\n";
        $out .= 'PWA_VAPID_PRIVATE="'.str_replace('"', '\\"', $privateKey)."\"\n";

        $dir = dirname($sharedPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents($sharedPath, $out);
    }

    private function refreshRuntimeConfig(): void
    {
        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
            // ignore
        }

        if (DockerSetupState::isDocker()) {
            try {
                Artisan::call('queue:restart');
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    private function readEnvValue(string $content, string $key): ?string
    {
        if (! preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+)\s*$/mi', $content, $m)) {
            return null;
        }

        $value = trim((string) ($m[1] ?? ''), " \t\n\r\0\x0B\"'`");

        return $value !== '' ? $value : null;
    }

    private function resolveEnvPath(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    private function resolveSharedVapidPath(): string
    {
        return $this->sharedVapidPath ?? base_path('.docker/pwa_vapid.env');
    }
}
