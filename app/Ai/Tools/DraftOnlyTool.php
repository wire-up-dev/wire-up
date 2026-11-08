<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;

final class DraftOnlyTool extends RoleCheckedTool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = parent::schema($schema);

        unset($properties['publish']);

        return $properties;
    }

    protected function run(Request $request): string
    {
        return parent::run(new Request([...$request->toArray(), 'publish' => false]));
    }
}
