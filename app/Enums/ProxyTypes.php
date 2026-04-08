<?php

namespace App\Enums;

enum ProxyTypes: string
{
    case NONE = 'NONE';
    case TRAEFIK = 'TRAEFIK';
    case NGINX = 'NGINX';
    case CADDY = 'CADDY';
}

enum ProxyStatus: string
{
    case EXITED = 'exited';
    case RUNNING = 'running';
}
// resync-marker 2026-04-08
