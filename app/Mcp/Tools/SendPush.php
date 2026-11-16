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

#[Name('send_push')]
#[Title('Send a phone notification')]
#[Description('Send a push notification through the Reminders app: to "me" (the owner\'s phone) or "both" (the owner\'s and Emily\'s phones).')]
#[IsDestructive(false)]
class SendPush extends HouseholdTool
{
    public function handle(Request $request, HouseholdApps $apps): Response|ResponseFactory
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'to' => ['required', 'string', 'in:me,both'],
            'url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ]);
        $this->user($request);

        $payload = ['title' => trim($data['title']), 'body' => trim($data['body']), 'to' => $data['to']];
        if (($data['url'] ?? '') !== '') {
            $payload['url'] = $data['url'];
        }

        return $this->reply($apps->sendPush($payload));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The notification title.')->required(),
            'body' => $schema->string()->description('The notification text.')->required(),
            'to' => $schema->string()->enum(['me', 'both'])->description('"me" = the owner\'s phone, "both" = both phones.')->required(),
            'url' => $schema->string()->description('Optional link the notification opens.'),
        ];
    }
}
