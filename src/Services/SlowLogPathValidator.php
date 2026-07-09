<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Validates configured slow-log paths before reading from disk.
 */
class SlowLogPathValidator
{
    public function resolve(string $configuredPath): ?string
    {
        $configuredPath = trim($configuredPath);

        if ($configuredPath === '' || str_contains($configuredPath, "\0")) {
            return null;
        }

        if (! is_file($configuredPath) || ! is_readable($configuredPath)) {
            return null;
        }

        $realPath = realpath($configuredPath);

        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            return null;
        }

        if (! $this->isWithinAllowedPrefix($realPath)) {
            return null;
        }

        return $realPath;
    }

    private function isWithinAllowedPrefix(string $realPath): bool
    {
        $normalizedPath = $this->normalizePath($realPath);

        foreach ($this->allowedPrefixes() as $prefix) {
            $normalizedPrefix = $this->normalizePath($prefix);

            if ($normalizedPrefix === '') {
                continue;
            }

            if ($normalizedPath === $normalizedPrefix) {
                return true;
            }

            if (str_starts_with($normalizedPath, $normalizedPrefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedPrefixes(): array
    {
        $configured = config('index_advisor.slow_log_allowed_path_prefixes');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter(array_map(
                static fn ($prefix) => is_string($prefix) ? trim($prefix) : '',
                $configured
            )));
        }

        return [
            '/var/log',
            storage_path('logs'),
        ];
    }

    private function normalizePath(string $path): string
    {
        $resolved = realpath($path);

        return str_replace('\\', '/', $resolved ?: $path);
    }
}
