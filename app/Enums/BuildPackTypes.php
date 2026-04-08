<?php

namespace App\Enums;

enum BuildPackTypes: string
{
    case NIXPACKS = 'nixpacks';
    case STATIC = 'static';
    case DOCKERFILE = 'dockerfile';
    case DOCKERCOMPOSE = 'dockercompose';
}
// resync-marker 2026-04-08
