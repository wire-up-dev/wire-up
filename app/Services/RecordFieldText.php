<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Media;
use App\Models\Record;

final class RecordFieldText
{
    /**
     * @param  array<string, mixed>  $field
     */
    public static function column(Record $record, array $field, bool $publishedOnly = false): string
    {
        $type = FieldType::tryFrom($field['type']);
        $key = $field['key'];
        $locale = app()->getLocale();

        if ($type === FieldType::RELATIONSHIP) {
            $titles = $record->linkedRecords($field, $publishedOnly)->map(fn (Record $linked): string => $linked->title)->implode(', ');

            return $titles !== '' ? str($titles)->limit(60)->value() : '—';
        }

        if ($type?->isMedia()) {
            return (string) $record->media
                ->filter(fn (Media $media): bool => $media->pivot->role === $key)
                ->count();
        }

        $value = ($field['translatable'] ?? false)
            ? data_get($record->data, "{$key}.{$locale}")
            : data_get($record->data, $key);

        if (is_array($value)) {
            $value = implode(', ', array_filter($value, is_scalar(...)));
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            FieldType::BOOLEAN => $value ? __('Yes') : __('No'),
            FieldType::MONEY => SettingsService::current()->formatMoney(is_numeric($value) ? $value : null),
            FieldType::DATE => str($value)->substr(0, 10)->value(),
            FieldType::DATETIME => str($value)->replace('T', ' ')->substr(0, 16)->value(),
            FieldType::RICH_TEXT => str(strip_tags((string) $value))->squish()->limit(60)->value(),
            default => str((string) $value)->limit(60)->value(),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function export(Record $record, array $field): string
    {
        $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
        $key = (string) ($field['key'] ?? '');

        if ($type === FieldType::RELATIONSHIP) {
            return $record->linkedRecords($field, publishedOnly: false)->map(fn (Record $linked): string => $linked->title)->implode('; ');
        }

        if ($type?->isMedia() ?? false) {
            return $record->media
                ->filter(fn (Media $media): bool => $media->pivot->role === $key)
                ->sortBy(fn (Media $media): int => (int) $media->pivot->position)
                ->map(fn (Media $media): string => $media->filename ?? basename($media->source))
                ->unique()
                ->implode('; ');
        }

        $value = $record->fieldValue($key, (bool) ($field['translatable'] ?? false));

        if (is_array($value)) {
            return implode('; ', array_filter($value, is_scalar(...)));
        }

        if (! is_scalar($value) || $value === '') {
            return '';
        }

        return match ($type) {
            FieldType::BOOLEAN => $value ? __('Yes') : __('No'),
            FieldType::DATETIME => str_replace('T', ' ', (string) $value),
            FieldType::RICH_TEXT => str(html_entity_decode(strip_tags((string) preg_replace('/<\/(?:p|div|li|h[1-6]|blockquote)>|<br\s*\/?>/i', '$0 ', (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))->squish()->value(),
            default => (string) $value,
        };
    }
}
