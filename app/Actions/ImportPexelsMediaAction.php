<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MediaType;
use App\Models\Media;
use App\Services\PublicUrlGuard;
use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ImportPexelsMediaAction
{
    private const array HOSTS = ['images.pexels.com', 'videos.pexels.com', 'player.vimeo.com'];

    private const int MAX_IMAGE_BYTES = 30 * 1024 * 1024;

    private const int MAX_VIDEO_BYTES = 150 * 1024 * 1024;

    /**
     * @var array<string, array{extension: string, type: MediaType}>
     */
    private const array ALLOWED_MIMES = [
        'image/jpeg' => ['extension' => 'jpg', 'type' => MediaType::IMAGE],
        'image/png' => ['extension' => 'png', 'type' => MediaType::IMAGE],
        'image/webp' => ['extension' => 'webp', 'type' => MediaType::IMAGE],
        'image/gif' => ['extension' => 'gif', 'type' => MediaType::IMAGE],
        'video/mp4' => ['extension' => 'mp4', 'type' => MediaType::VIDEO],
        'video/webm' => ['extension' => 'webm', 'type' => MediaType::VIDEO],
        'video/quicktime' => ['extension' => 'mov', 'type' => MediaType::VIDEO],
    ];

    public function __construct(private CreateMediaAction $createMedia, private PublicUrlGuard $urlGuard) {}

    /**
     * @param  array<string, mixed>  $item  Normalized Pexels result from PexelsService
     */
    public function handle(array $item): Media
    {
        $type = MediaType::tryFrom((string) ($item['type'] ?? '')) ?? MediaType::IMAGE;
        $pexelsId = (int) ($item['id'] ?? 0);

        $alreadyImported = Media::query()
            ->where('type', $type->value)
            ->where('metadata->source', 'pexels')
            ->where('metadata->pexels_id', $pexelsId)
            ->first();

        if ($alreadyImported instanceof Media) {
            return $alreadyImported;
        }

        $contents = $this->download((string) ($item['download_url'] ?? ''), $type === MediaType::VIDEO ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES);
        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $allowed = self::ALLOWED_MIMES[$mime] ?? null;

        throw_if($allowed === null || $allowed['type'] !== $type, InvalidArgumentException::class, "Pexels sent an unexpected file type ({$mime}).");

        $etag = md5($contents);

        $existingByEtag = Media::query()->where('etag', $etag)->first();

        if ($existingByEtag instanceof Media) {
            return $existingByEtag;
        }

        $disk = Storage::disk(config('filesystems.media'));
        $uuid = Str::uuid()->toString();
        $photographer = (string) ($item['photographer'] ?? '');
        $slug = Str::slug(($photographer !== '' ? $photographer.'-' : '').'pexels-'.$pexelsId);
        $extension = $allowed['extension'];
        $filename = $uuid.'_'.$slug.'.'.$extension;

        $disk->put("media/$filename", $contents, 'public');

        $thumbnail = null;

        if ($type === MediaType::VIDEO && ! empty($item['preview'])) {
            $thumbContents = rescue(fn (): string => $this->download((string) $item['preview'], self::MAX_IMAGE_BYTES), '', report: false);
            $thumbAllowed = self::ALLOWED_MIMES[(string) new finfo(FILEINFO_MIME_TYPE)->buffer($thumbContents)] ?? null;

            if ($thumbAllowed !== null && $thumbAllowed['type'] === MediaType::IMAGE) {
                $thumbnail = "media/{$uuid}_{$slug}_thumb.{$thumbAllowed['extension']}";
                $disk->put($thumbnail, $thumbContents, 'public');
            }
        }

        $altText = (string) ($item['alt'] ?? '');

        return $this->createMedia->handle([
            'type' => $type->value,
            'source' => "media/$filename",
            'etag' => $etag,
            'filename' => $slug.'.'.$extension,
            'alt_text' => $altText !== '' ? $altText : mb_trim('Photo by '.$photographer),
            'mime_type' => $mime,
            'thumbnail' => $thumbnail,
            'size' => mb_strlen($contents, '8bit'),
            'duration' => $item['duration'] ?? null,
            'width' => $item['width'] ?? null,
            'height' => $item['height'] ?? null,
            'metadata' => [
                'source' => 'pexels',
                'pexels_id' => $pexelsId,
                'photographer' => $photographer,
                'photographer_url' => (string) ($item['photographer_url'] ?? ''),
                'pexels_url' => (string) ($item['pexels_url'] ?? ''),
            ],
        ]);
    }

    private function download(string $url, int $maxBytes): string
    {
        throw_unless(
            parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(parse_url($url, PHP_URL_HOST), self::HOSTS, true),
            InvalidArgumentException::class,
            'Only files from Pexels can be imported this way.',
        );

        return $this->urlGuard->get($url, 60, $maxBytes)->throw()->body();
    }
}
