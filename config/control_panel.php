<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wrapper-script directory (on the mini-PC)
    |--------------------------------------------------------------------------
    | Root-owned scripts that the app invokes via sudo (see provisioning/).
    | The app NEVER composes a shell string — it only calls these fixed paths.
    */
    'bin' => env('CP_BIN_DIR', '/opt/controlpanel/bin'),

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    | allowed_cidrs is defense-in-depth on top of the LAN-only UFW rule.
    | WoL magic packets are sent to the subnet-directed broadcast address.
    */
    'network' => [
        'allowed_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CP_ALLOWED_CIDRS', '192.168.0.0/24,127.0.0.1/32,::1/128'))
        ))),
        'broadcast' => env('CP_WOL_BROADCAST', '192.168.0.255'),
        'wol_port' => (int) env('CP_WOL_PORT', 9),
    ],

    /*
    |--------------------------------------------------------------------------
    | Windows PC (the machine controlled over SSH / woken via WoL)
    |--------------------------------------------------------------------------
    | host/user are used by the on-box wrapper scripts via config.env; the MAC
    | is used by the pure-PHP Wake-on-LAN handler.
    */
    'windows' => [
        'host' => env('CP_WIN_HOST', '192.168.0.100'),
        'user' => env('CP_WIN_USER', 'binar'),
        'mac' => env('CP_WIN_MAC', '00:00:00:00:00:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Site allowlist (for mini.deploy / mini.rebuild-cache)
    |--------------------------------------------------------------------------
    | The ONLY values accepted as the "site" argument. Validated in Laravel AND
    | re-validated inside the wrapper script.
    */
    'sites' => [
        'Navigation',
        'LittlePocketMeseum',
        'SingularCoalescence',
        'AiCampaignManager',
        'Budget',
        'ControlPanel',
        'StoryCampaign',
        'NorthernCall_v2',
        'PasswordVault',
    ],

    /*
    |--------------------------------------------------------------------------
    | VSCode projects for win.launch-claude
    |--------------------------------------------------------------------------
    | key => label. The key is passed to the wrapper, which triggers a Windows
    | Scheduled Task named "LaunchClaudeSession_<key>" on the interactive
    | desktop (that task runs `claude --remote-control` in the project dir).
    */
    'projects' => [
        'agent-manager' => 'AgentManager',
        'ai-campaign-manager' => 'AiCampaignManager',
        'artwork' => 'Artwork',
        'budget' => 'Budget',
        'charlotte' => 'Charlotte',
        'coloringbook' => 'ColoringBook',
        'controlpanel' => 'ControlPanel',
        'dnd' => 'DnD',
        'littlepocketmeseum' => 'LittlePocketMeseum',
        'magic-deck-builder' => 'Magic Deck Builder',
        'milerage' => 'MileRage',
        'mini-pc' => 'mini-pc',
        'models' => 'Models',
        'mods-nercodancer' => 'mods-nercodancer',
        'music' => 'Music',
        'navigation' => 'Navigation',
        'night-harvest' => 'night-harvest',
        'northerncall-v2' => 'NorthernCall_v2',
        'passwordvault' => 'PasswordVault',
        'powershell' => 'powershell',
        'reminders' => 'Reminders',
        'scriptables' => 'Scriptables',
        'singular-coalescence' => 'SingularCoalescence',
        'skullkeep' => 'SkullKeep',
        'story-campaign' => 'StoryCampaign',
        'tatertot' => 'TaterTot',
        'test' => 'Test',
        'universe' => 'Universe',
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude model choices (for win.launch-claude)
    |--------------------------------------------------------------------------
    | The ONLY values accepted as the "model" argument of a launch. Validated in
    | Laravel (by `id`) AND re-mapped to an actual model string inside the
    | Windows launcher (claude-session.ps1) — the id is passed over the wire, the
    | model string never is. `id` must be a [A-Za-z0-9_-] slug. The 'default'
    | entry (empty model) launches with the account's default model (no --model).
    */
    'models' => [
        ['id' => 'default', 'label' => 'Default', 'model' => ''],
        ['id' => 'opus-4-8', 'label' => 'Opus 4.8', 'model' => 'claude-opus-4-8'],
        ['id' => 'sonnet-5', 'label' => 'Sonnet 5', 'model' => 'claude-sonnet-5'],
        ['id' => 'fable-5', 'label' => 'Fable 5', 'model' => 'claude-fable-5'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Other LAN devices (for lan.wake / lan.ping)
    |--------------------------------------------------------------------------
    | id must be a slug. mac enables wake; ip enables ping. Edit to taste.
    | "localhost" is included so the read-only ping action is testable out of
    | the box — safe to remove.
    */
    'devices' => [
        ['id' => 'windows-pc', 'label' => 'Windows PC', 'mac' => '34:5A:60:BB:6F:81', 'ip' => '192.168.0.197'],
        ['id' => 'localhost', 'label' => 'This machine (test)', 'mac' => '', 'ip' => '127.0.0.1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Disabled actions
    |--------------------------------------------------------------------------
    | Action ids listed here render greyed-out and cannot be executed. Use this
    | to gate rollout (e.g. keep destructive/box actions off until provisioned).
    */
    'disabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CP_DISABLED_ACTIONS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Seeded admin (the only login; registration is disabled)
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'name' => env('CP_ADMIN_NAME', 'Admin'),
        'email' => env('CP_ADMIN_EMAIL', 'admin@controlpanel.local'),
        'password' => env('CP_ADMIN_PASSWORD', 'change-me-please'),
    ],
];
