<?php

namespace App\Mcp\Tools;

use App\Support\ControlPanel\HouseholdApps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_reminder')]
#[Title('Set a reminder')]
#[Description('Create a reminder in the Reminders app. Without a date or time it uses the app\'s defaults (today / the account\'s default hour). Dates are YYYY-MM-DD, times 24-hour HH:MM in the household\'s local time.')]
#[IsDestructive(false)]
class CreateReminder extends HouseholdTool
{
    public function handle(Request $request, HouseholdApps $apps): Response|ResponseFactory
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'string', 'max:20'],
            'list' => ['nullable', 'string', 'max:100'],
            'is_shared' => ['nullable', 'boolean'],
        ]);
        $this->user($request);

        // Only the fields that were given: Reminders reads a sent-but-empty
        // date or time as a mistake, and an absent one as "use my default".
        $payload = array_filter([
            'title' => trim($data['title']),
            'notes' => $data['notes'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'due_time' => $data['due_time'] ?? null,
            'list' => $data['list'] ?? null,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        if (array_key_exists('is_shared', $data) && $data['is_shared'] !== null) {
            $payload['is_shared'] = (bool) $data['is_shared'];
        }

        return $this->reply($apps->createReminder($payload));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('What to be reminded of.')->required(),
            'notes' => $schema->string()->description('Optional longer notes.'),
            'due_date' => $schema->string()->format('date')->description('Optional date, YYYY-MM-DD.'),
            'due_time' => $schema->string()->description('Optional time, 24-hour HH:MM (e.g. 17:30).'),
            'list' => $schema->string()->description('Optional list name in the Reminders app.'),
            'is_shared' => $schema->boolean()->description('Optional: true to share it with the household.'),
        ];
    }
}
