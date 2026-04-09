<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SetupWordPress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600;

    public Service $service;

    public function __construct(Service $service)
    {
        $this->service = $service;
        $this->onQueue('high');
    }

    public function handle(): void
    {
        // Refresh service to get latest data
        $this->service->refresh();

        $server = $this->service->server;
        $wordpressApplications = $this->detectWordPressApplications($this->service);

        if (empty($wordpressApplications)) {
            \Log::info('SetupWordPress: No WordPress applications found', [
                'service_id' => $this->service->id,
                'service_uuid' => $this->service->uuid,
            ]);
            return;
        }

        \Log::info('SetupWordPress: Starting setup', [
            'service_id' => $this->service->id,
            'service_uuid' => $this->service->uuid,
            'applications_count' => count($wordpressApplications),
        ]);

        foreach ($wordpressApplications as $application) {
            try {
                $this->setupWordPressContainer($application, $server, $this->service);
            } catch (\Throwable $e) {
                \Log::error('SetupWordPress: Failed to setup container', [
                    'application_id' => $application->id,
                    'application_name' => $application->name,
                    'error' => $e->getMessage(),
                ]);
                // Continue with next application
            }
        }
    }

    private function detectWordPressApplications(Service $service): array
    {
        $wordpressApps = [];
        
        foreach ($service->applications as $application) {
            if ($this->isWordPressContainer($application)) {
                $wordpressApps[] = $application;
            }
        }

        return $wordpressApps;
    }

    private function isWordPressContainer(ServiceApplication $application): bool
    {
        // Check if image contains wordpress
        if (str_contains(strtolower($application->image ?? ''), 'wordpress')) {
            return true;
        }

        // Check environment variables
        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            if (str_contains(strtoupper($envVar->key ?? ''), 'WORDPRESS')) {
                return true;
            }
        }

        return false;
    }

    private function setupWordPressContainer(ServiceApplication $application, $server, Service $service): void
    {
        $containerName = $application->name.'-'.$service->uuid;
        $escapedContainer = escapeshellarg($containerName);

        try {
            // Wait for container to be ready
            $this->waitForContainerReady($server, $containerName);

            // Install WP-CLI if not present
            $this->installWpCli($server, $containerName);

            // Configure wp-config.php automatically
            $this->configureWpConfig($application, $server, $containerName, $service);

            // Fix permissions
            $this->fixPermissions($server, $containerName);

            // Seed the recommended php.ini defaults so the user never
            // sees the stock 2M/8M values. The defaults come from
            // WordPressManager::WP_PHP_INI_DEFAULTS (single source of
            // truth — the manual "Aplicar defaults" button in the UI
            // reads the same constant). We write them as
            // LocalFileVolume overrides in conf.d/99-custom-*.ini so
            // they survive redeploys, without touching the base
            // php.ini or requiring a custom image.
            $this->seedRecommendedPhpIniDefaults($application, $server, $containerName);
        } catch (\Throwable $e) {
            \Log::error('Failed to setup WordPress container', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Creates a LocalFileVolume per php.ini directive declared in
     * WordPressManager::WP_PHP_INI_DEFAULTS and docker-cp's the
     * content into /usr/local/etc/php/conf.d inside the container.
     *
     * Idempotent: if a volume already exists with the same mount
     * path, we just refresh its content instead of creating a
     * duplicate row. Safe to run on every redeploy.
     *
     * Why not just call WordPressManager::updatePhpIniSetting() per
     * key? Because that method is a Livewire component action that
     * depends on session/auth context, and this action runs from the
     * queue worker without an authenticated user. The LocalFileVolume
     * model + docker cp flow below is the same underlying mechanism,
     * just without the Livewire plumbing.
     */
    private function seedRecommendedPhpIniDefaults(ServiceApplication $application, $server, string $containerName): void
    {
        $defaults = \App\Livewire\Project\Service\WordPressManager::WP_PHP_INI_DEFAULTS;
        $escapedContainer = escapeshellarg($containerName);

        foreach ($defaults as $key => $value) {
            try {
                $fileName = '99-custom-'.$key.'.ini';
                $mountPath = '/usr/local/etc/php/conf.d/'.$fileName;
                $content = "{$key} = {$value}\n";

                // Upsert the LocalFileVolume so the override persists
                // across redeploys — Coolify mounts these as bind
                // volumes on every start.
                $volume = \App\Models\LocalFileVolume::firstOrNew([
                    'resource_type' => \App\Models\ServiceApplication::class,
                    'resource_id' => $application->id,
                    'mount_path' => $mountPath,
                ]);
                $volume->fs_path = $mountPath;
                $volume->content = $content;
                $volume->is_directory = false;
                $volume->save();

                // Also push the file INTO the running container so
                // the change takes effect before the next deploy.
                // Writing via tee + heredoc works for the stock
                // WordPress image which runs as root internally.
                $escapedMount = escapeshellarg($mountPath);
                $b64 = escapeshellarg(base64_encode($content));
                $writeCmd = "docker exec {$escapedContainer} sh -c 'mkdir -p /usr/local/etc/php/conf.d && echo {$b64} | base64 -d > {$escapedMount}'";
                if ($server->isNonRoot()) {
                    $writeCmd = "sudo {$writeCmd}";
                }
                instant_remote_process([$writeCmd], $server, false);
            } catch (\Throwable $e) {
                \Log::warning('seedRecommendedPhpIniDefaults: per-key failure (non-fatal)', [
                    'container' => $containerName,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Soft reload of PHP-FPM to pick up the new conf.d files
        // without restarting the container. SIGUSR2 is the canonical
        // FPM reload signal. `|| true` so a container without
        // php-fpm (pure CLI WordPress, exotic configurations) does
        // not fail the whole setup.
        $reloadCmd = "docker exec {$escapedContainer} sh -lc 'pkill -USR2 php-fpm 2>/dev/null || pkill -USR2 php 2>/dev/null || true'";
        if ($server->isNonRoot()) {
            $reloadCmd = "sudo {$reloadCmd}";
        }
        try {
            instant_remote_process([$reloadCmd], $server, false);
        } catch (\Throwable $e) {
            // Non-fatal: the conf.d files are on disk, next container
            // restart picks them up.
        }
    }

    private function waitForContainerReady($server, string $containerName): void
    {
        $maxAttempts = 60; // Increase attempts but reduce sleep time
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -d /var/www/html && echo ready || echo notready' 2>&1";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                if ($output === 'ready') {
                    \Log::info('SetupWordPress: Container ready', ['container' => $containerName]);
                    return;
                }
            } catch (\Throwable $e) {
                // Container might not exist yet, continue waiting
                \Log::debug('SetupWordPress: Container not ready yet', [
                    'container' => $containerName,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Reduce sleep time to 1 second for faster response
            sleep(1);
            $attempt++;
        }
        
        \Log::warning('SetupWordPress: Container did not become ready in time', [
            'container' => $containerName,
            'max_attempts' => $maxAttempts,
        ]);
        // Don't throw exception, just log and continue - container might start later
    }

    private function installWpCli($server, string $containerName): void
    {
        $escapedContainer = escapeshellarg($containerName);
        
        // Check if WP-CLI is already installed
        $checkCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && which wp || echo notfound'";
        if ($server->isNonRoot()) {
            $checkCommand = "sudo {$checkCommand}";
        }
        
        $wpCliCheck = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
        
        if ($wpCliCheck !== 'notfound' && ! empty($wpCliCheck)) {
            return; // WP-CLI already installed
        }

        // Install WP-CLI (most WordPress images already have it, but we install as fallback)
        $installCommand = "docker exec {$escapedContainer} sh -c 'curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar 2>&1 && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp 2>&1 || true'";
        if ($server->isNonRoot()) {
            $installCommand = "sudo {$installCommand}";
        }
        
        instant_remote_process([$installCommand], $server, false);
    }

    private function configureWpConfig(ServiceApplication $application, $server, string $containerName, Service $service): void
    {
        $escapedContainer = escapeshellarg($containerName);
        
        // Check if wp-config.php already exists
        $checkCommand = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/wp-config.php && echo exists || echo notexists'";
        if ($server->isNonRoot()) {
            $checkCommand = "sudo {$checkCommand}";
        }
        
        $configExists = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
        
        if ($configExists === 'exists') {
            // wp-config.php exists, but we can still update database settings if needed
            $this->updateWpConfigDatabaseSettings($application, $server, $containerName);
            return;
        }

        // Get database configuration from environment variables
        $dbHost = $this->getEnvVar($application, 'WORDPRESS_DB_HOST', 'mysql');
        $dbUser = $this->getEnvVar($application, 'WORDPRESS_DB_USER', 'wordpress');
        $dbPassword = $this->getEnvVar($application, 'WORDPRESS_DB_PASSWORD', '');
        $dbName = $this->getEnvVar($application, 'WORDPRESS_DB_NAME', 'wordpress');
        
        // Generate wp-config.php using WP-CLI
        $wpConfigCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && wp config create --dbname=".escapeshellarg($dbName)." --dbuser=".escapeshellarg($dbUser)." --dbpass=".escapeshellarg($dbPassword)." --dbhost=".escapeshellarg($dbHost)." --allow-root --skip-check 2>&1 || true'";
        if ($server->isNonRoot()) {
            $wpConfigCommand = "sudo {$wpConfigCommand}";
        }
        
        instant_remote_process([$wpConfigCommand], $server, false);
        
        // Get site URL from environment or service and set it
        $siteUrl = $this->getSiteUrl($application, $service);
        if ($siteUrl) {
            $this->setSiteUrl($server, $containerName, $siteUrl);
        }
    }

    private function updateWpConfigDatabaseSettings(ServiceApplication $application, $server, string $containerName): void
    {
        $dbHost = $this->getEnvVar($application, 'WORDPRESS_DB_HOST');
        $dbUser = $this->getEnvVar($application, 'WORDPRESS_DB_USER');
        $dbPassword = $this->getEnvVar($application, 'WORDPRESS_DB_PASSWORD');
        $dbName = $this->getEnvVar($application, 'WORDPRESS_DB_NAME');
        
        if (! $dbHost && ! $dbUser && ! $dbPassword && ! $dbName) {
            return; // No database settings to update
        }
        
        $escapedContainer = escapeshellarg($containerName);
        
        // Update database settings using WP-CLI if they exist
        if ($dbName) {
            $updateDbName = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && wp config set DB_NAME ".escapeshellarg($dbName)." --allow-root 2>&1 || true'";
            if ($server->isNonRoot()) {
                $updateDbName = "sudo {$updateDbName}";
            }
            instant_remote_process([$updateDbName], $server, false);
        }
        
        if ($dbUser) {
            $updateDbUser = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && wp config set DB_USER ".escapeshellarg($dbUser)." --allow-root 2>&1 || true'";
            if ($server->isNonRoot()) {
                $updateDbUser = "sudo {$updateDbUser}";
            }
            instant_remote_process([$updateDbUser], $server, false);
        }
        
        if ($dbPassword) {
            $updateDbPass = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && wp config set DB_PASSWORD ".escapeshellarg($dbPassword)." --allow-root 2>&1 || true'";
            if ($server->isNonRoot()) {
                $updateDbPass = "sudo {$updateDbPass}";
            }
            instant_remote_process([$updateDbPass], $server, false);
        }
        
        if ($dbHost) {
            $updateDbHost = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && wp config set DB_HOST ".escapeshellarg($dbHost)." --allow-root 2>&1 || true'";
            if ($server->isNonRoot()) {
                $updateDbHost = "sudo {$updateDbHost}";
            }
            instant_remote_process([$updateDbHost], $server, false);
        }
    }

    private function setSiteUrl($server, string $containerName, string $siteUrl): void
    {
        $escapedContainer = escapeshellarg($containerName);
        $setUrlCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && wp option update siteurl ".escapeshellarg($siteUrl)." --allow-root 2>&1 && wp option update home ".escapeshellarg($siteUrl)." --allow-root 2>&1 || true'";
        if ($server->isNonRoot()) {
            $setUrlCommand = "sudo {$setUrlCommand}";
        }
        instant_remote_process([$setUrlCommand], $server, false);
    }

    private function getEnvVar(ServiceApplication $application, string $key, string $default = ''): string
    {
        $envVar = $application->environment_variables()->where('key', $key)->first();
        if ($envVar) {
            return $envVar->value ?? $default;
        }

        // Also check service-level environment variables
        $serviceEnvVar = $application->service->environment_variables()->where('key', $key)->first();
        if ($serviceEnvVar) {
            return $serviceEnvVar->value ?? $default;
        }

        return $default;
    }

    private function getSiteUrl(ServiceApplication $application, Service $service): ?string
    {
        // Try to get URL from application FQDN
        if ($application->fqdn) {
            $urls = explode(',', $application->fqdn);
            $firstUrl = trim($urls[0] ?? '');
            if ($firstUrl) {
                return 'https://'.$firstUrl;
            }
        }

        // Try SERVICE_URL_WORDPRESS environment variable
        $serviceUrl = $this->getEnvVar($application, 'SERVICE_URL_WORDPRESS');
        if ($serviceUrl) {
            return $serviceUrl;
        }

        return null;
    }

    private function fixPermissions($server, string $containerName): void
    {
        $escapedContainer = escapeshellarg($containerName);
        $commands = [
            "docker exec {$escapedContainer} sh -c 'chown -R www-data:www-data /var/www/html/wp-content 2>&1 || true'",
            "docker exec {$escapedContainer} sh -c 'find /var/www/html/wp-content -type d -exec chmod 755 {} \\; 2>&1 || true'",
            "docker exec {$escapedContainer} sh -c 'find /var/www/html/wp-content -type f -exec chmod 644 {} \\; 2>&1 || true'",
        ];

        foreach ($commands as $command) {
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            instant_remote_process([$command], $server, false);
        }
    }
}
// resync-marker 2026-04-08
