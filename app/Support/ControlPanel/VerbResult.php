<?php

namespace App\Support\ControlPanel;

/**
 * What one of the fixed verbs ({@see ShortcutVerbs}) answered: whether it
 * worked, the one sentence a phone speaks, the HTTP status the Shortcut API
 * replies with, and any extra detail (the action log, a list of choices).
 * The Shortcut API turns it into JSON; the Household MCP tools turn it into
 * a tool reply. Both say the same sentence.
 */
final class VerbResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $data = [],
        public readonly int $status = 200,
    ) {}

    /**
     * `ok`, `message`, then the detail — the Shortcut API's JSON body.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, ...$this->data];
    }
}
