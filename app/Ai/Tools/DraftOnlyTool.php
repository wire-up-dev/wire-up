<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Ai\Tools\Request;

final class DraftOnlyTool extends RoleCheckedTool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = parent::schema($schema);

        unset($properties['publish'], $properties['status']);

        return $properties;
    }

    protected function run(Request $request): string
    {
        return parent::run(new Request(Arr::except($request->toArray(), ['publish', 'status'])));
    }
}
