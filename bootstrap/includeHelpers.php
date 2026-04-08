<?php

$files = glob(__DIR__.'/helpers/*.php');
foreach ($files as $file) {
    require $file;
}
// resync-marker 2026-04-08
