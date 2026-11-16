<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\ControlPanel\VerbResult;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Shared shape for every Household tool: the reply's first text block is the
 * one speakable sentence (the same one the Shortcut API says), the second is
 * the detail as JSON (session ids, list items…) so Claude can act on it, and
 * the same detail rides along as structured content.
 */
abstract class HouseholdTool extends Tool
{
    /**
     * @param  bool  $errorWhenNotOk  false for questions whose "no" is an
     *                                answer, not a failure ("Gemini is asleep.")
     */
    protected function reply(VerbResult $result, bool $errorWhenNotOk = true): ResponseFactory
    {
        $detail = $this->detail($result);
        $sentence = $result->ok || ! $errorWhenNotOk
            ? Response::text($result->message)
            : Response::error($result->message);

        $responses = [$sentence];
        if (count($detail) > 2) {
            $responses[] = Response::json($detail);
        }

        return Response::make($responses)->withStructuredContent($detail);
    }

    /**
     * The signed-in account every action is attributed to in action_logs.
     */
    protected function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Sign in to ControlPanel first.');
        }

        return $user;
    }

    /**
     * `ok`, `message` and the detail, with the action log trimmed to what is
     * worth reading back (raw handler output can run to pages).
     *
     * @return array<string, mixed>
     */
    private function detail(VerbResult $result): array
    {
        $detail = $result->toArray();

        if (is_array($detail['action'] ?? null)) {
            $action = $detail['action'];
            $detail['action'] = [
                'log_id' => $action['log_id'] ?? null,
                'action_id' => $action['action_id'] ?? null,
                'arg' => $action['arg'] ?? null,
                'status' => $action['status'] ?? null,
                'output' => Str::limit((string) ($action['output'] ?? ''), 500),
                'error' => Str::limit((string) ($action['error'] ?? ''), 500),
            ];
        }

        return $detail;
    }
}
