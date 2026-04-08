<?php

namespace App\Support;

final class ContainerName
{
    public static function normalize(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return ltrim($name, '/');
    }
}

// resync-marker 2026-04-08
