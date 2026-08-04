<?php

declare(strict_types=1);

function loadOltProfiles(): array
{
    $path = __DIR__ . '/../config/olt_profiles.json';
    $json = file_get_contents($path);

    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['profiles']) || !is_array($data['profiles'])) {
        return [];
    }

    return $data['profiles'];
}

function findOltProfile(string $brand, string $model): ?array
{
    foreach (loadOltProfiles() as $profile) {
        if (
            strcasecmp((string) ($profile['brand'] ?? ''), $brand) === 0
            && strcasecmp((string) ($profile['model'] ?? ''), $model) === 0
        ) {
            return $profile;
        }
    }

    return null;
}

function getOltProfileCommand(?array $profile, string $commandName, string $fallback = ''): string
{
    $commands = getOltProfileCommands($profile, $commandName, $fallback);

    return $commands[0] ?? $fallback;
}

function getOltProfileCommands(?array $profile, string $commandName, string $fallback = ''): array
{
    if (!$profile || !isset($profile['commands']) || !is_array($profile['commands'])) {
        return $fallback !== '' ? [$fallback] : [];
    }

    $value = $profile['commands'][$commandName] ?? '';

    if (is_array($value)) {
        return array_values(array_filter(array_map(static function ($command): string {
            return trim((string) $command);
        }, $value), static function (string $command): bool {
            return $command !== '';
        }));
    }

    $command = trim((string) $value);

    if ($command !== '') {
        return [$command];
    }

    return $fallback !== '' ? [$fallback] : [];
}
