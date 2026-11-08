<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Mcp\Support\RemoteAccess;
use App\Models\User;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Server\Tool;

abstract class RoleCheckedTool extends McpServerTool
{
    /**
     * @param  array<array-key, mixed>  $arguments
     */
    final public static function problemFor(Tool $tool, array $arguments): ?string
    {
        $user = auth()->user();

        return $user instanceof User
            ? RemoteAccess::forUser($user)->problem($tool, $arguments)
            : __('Sign in to use the assistant.');
    }

    final public function handle(Request $request): string
    {
        $problem = $this->tool instanceof Tool ? self::problemFor($this->tool, $request->toArray()) : null;

        return $problem === null ? $this->run($request) : $this->errorMessage($problem);
    }

    protected function run(Request $request): string
    {
        return parent::handle($request);
    }
}
