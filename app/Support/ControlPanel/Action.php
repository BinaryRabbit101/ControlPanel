<?php

namespace App\Support\ControlPanel;

/**
 * Immutable description of one predefined action. Actions are declared in code
 * (ActionRegistry), never built from user input — this is the allowlist.
 */
class Action
{
    public function __construct(
        public string $id,
        public string $label,
        public string $category,          // Windows | Mini-PC
        public string $handler,           // wol | script | ssh | inline
        public ?string $script = null,    // wrapper-script basename, or inline op name
        public string $runAs = 'none',    // root | gemini | none
        public string $argKind = 'none',  // none | site | device | project
        public bool $destructive = false,
        public bool $async = false,
        public string $description = '',
        public int $timeout = 30,
        public bool $enabled = true,      // set by registry from config('control_panel.disabled')
    ) {
    }
}
