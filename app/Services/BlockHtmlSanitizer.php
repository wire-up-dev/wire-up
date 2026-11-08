<?php

declare(strict_types=1);

namespace App\Services;

use Dom\Element;
use Dom\HTMLDocument;
use RuntimeException;

final class BlockHtmlSanitizer
{
    /**
     * @var list<string>
     */
    private const array FORBIDDEN_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form',
        'link', 'meta', 'base', 'applet', 'frame', 'frameset', 'noscript',
    ];

    /**
     * @var list<string>
     */
    private const array ANIMATION_ELEMENTS = ['animate', 'set', 'animatemotion', 'animatetransform'];

    /**
     * @var array<string, list<string>>
     */
    private const array VERBATIM_FIELDS = [
        'code' => ['code'],
    ];

    /**
     * @param  array<array-key, mixed>  $content
     * @return array<array-key, mixed>
     */
    public static function forBlock(string $type, array $content): array
    {
        $verbatim = self::VERBATIM_FIELDS[$type] ?? [];
        $sanitized = [];

        foreach ($content as $key => $value) {
            $sanitized[$key] = in_array($key, $verbatim, true) ? $value : self::sanitized($value);
        }

        return $sanitized;
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @return array<array-key, mixed>
     */
    public static function content(array $content): array
    {
        $sanitized = [];

        foreach ($content as $key => $value) {
            $sanitized[$key] = self::sanitized($value);
        }

        return $sanitized;
    }

    public static function value(string $value): string
    {
        if (self::isDangerousUrl($value)) {
            return '';
        }

        if (! str_contains($value, '<')) {
            return $value;
        }

        return self::withoutDangerousMarkup($value);
    }

    private static function sanitized(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => self::content($value),
            is_string($value) => self::value($value),
            default => $value,
        };
    }

    private static function isDangerousUrl(string $value): bool
    {
        $normalized = mb_strtolower((string) preg_replace(['/^[\x00-\x20]+/', '/[\t\n\r]/'], '', $value));

        if (preg_match('/^(?:javascript|vbscript|data):/', $normalized) !== 1) {
            return false;
        }

        return ! str_starts_with($normalized, 'data:image/');
    }

    private static function withoutDangerousMarkup(string $value): string
    {
        $document = HTMLDocument::createFromString('<!doctype html><html><head></head><body>'.$value, LIBXML_NOERROR);

        $body = $document->body;

        throw_unless($body instanceof Element, RuntimeException::class, 'The sanitizer body element went missing.');

        $removed = false;

        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (! $element->isConnected) {
                continue;
            }

            if (self::isForbidden($element)) {
                $element->remove();
                $removed = true;

                continue;
            }

            $removed = self::stripDangerousAttributes($element) || $removed;
        }

        if (! $removed) {
            return $value;
        }

        $html = '';

        foreach ($body->childNodes as $child) {
            $html .= $document->saveHtml($child);
        }

        return $html;
    }

    private static function isForbidden(Element $element): bool
    {
        $name = mb_strtolower($element->localName);

        if (in_array($name, self::FORBIDDEN_ELEMENTS, true)) {
            return true;
        }

        return in_array($name, self::ANIMATION_ELEMENTS, true)
            && str_ends_with(mb_strtolower(mb_trim((string) $element->getAttribute('attributeName'))), 'href');
    }

    private static function stripDangerousAttributes(Element $element): bool
    {
        $removed = false;

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (str_starts_with(mb_strtolower($attribute->localName), 'on') || self::isDangerousUrl($attribute->value)) {
                $element->removeAttributeNode($attribute);
                $removed = true;
            }
        }

        return $removed;
    }
}
