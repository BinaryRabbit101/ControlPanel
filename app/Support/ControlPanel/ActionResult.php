<?php

namespace App\Support\ControlPanel;

/**
 * Normalized outcome of running an action, regardless of handler.
 */
class ActionResult
{
    public function __construct(
        public bool $ok,
        public ?int $exitCode,
        public string $output = '',
        public string $error = '',
    ) {
    }
}
