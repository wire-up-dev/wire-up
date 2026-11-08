<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlockType;
use App\Enums\ContentStatus;
use Illuminate\Validation\Rule;

final class ContentRules
{
    public const array TITLE = ['string', 'min:3', 'max:255'];

    public const array SLUG = ['string', 'min:3', 'max:255', 'regex:/^[a-z0-9-]+$/'];

    public const array DESCRIPTION = ['nullable', 'string', 'max:160'];

    /**
     * @param  list<string>  $activeLocales
     * @return array<string, list<mixed>>
     */
    public static function publication(ContentStatus $status, array $activeLocales): array
    {
        return [
            'status' => ['required', Rule::enum(ContentStatus::class)],
            'published_at' => $status === ContentStatus::SCHEDULED ? ['required', 'date', 'after:now'] : ['nullable', 'date'],
            'publishedLocales' => ['array'],
            'publishedLocales.*' => ['string', Rule::in($activeLocales)],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function blocks(): array
    {
        return [
            'blocks' => ['array'],
            'blocks.*.type' => ['required', 'string', Rule::in(BlockType::values())],
            'blocks.*.content' => ['array'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function categories(): array
    {
        return [
            'categories' => ['array'],
            'categories.*' => ['integer', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * @param  list<string>  $backgroundImage
     * @return array<string, list<string>>
     */
    public static function layout(array $backgroundImage): array
    {
        return [
            'layout' => ['sometimes', 'array'],
            'layout.hideHeader' => ['boolean'],
            'layout.hideFooter' => ['boolean'],
            'layout.backgroundFixed' => ['boolean'],
            'layout.backgroundColor' => ['nullable', 'string', 'max:30'],
            'layout.backgroundImage' => $backgroundImage,
            'layout.customCss' => ['nullable', 'string', 'max:50000'],
            'layout.sidebar' => ['sometimes', 'array'],
            'layout.sidebar.menus' => ['array'],
            'layout.sidebar.menus.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function mediaItems(string $field): array
    {
        return [
            $field => ['array'],
            "{$field}.*" => ['array'],
            "{$field}.*.*" => ['array'],
            "{$field}.*.*.id" => ['required', 'integer', 'exists:media,id'],
            "{$field}.*.*.metadata" => ['nullable', 'array'],
            "{$field}.*.*.metadata.caption" => ['nullable', 'string', 'max:500'],
            "{$field}.*.*.metadata.alt" => ['nullable', 'string', 'max:255'],
        ];
    }
}
