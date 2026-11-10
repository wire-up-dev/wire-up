<?php

declare(strict_types=1);

use App\Actions\StoreMediaFileAction;
use App\Enums\MediaType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config()->string('filesystems.media'));
    $this->scratch = sys_get_temp_dir().'/wireup-media-'.bin2hex(random_bytes(6));
    mkdir($this->scratch);
});

afterEach(function (): void {
    array_map(File::delete(...), glob($this->scratch.'/*') ?: []);
    rmdir($this->scratch);
});

function storedScratchFile(string $name, string $contents): string
{
    $path = test()->scratch.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

function storedPixelPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true);
}

it('stores an image with its dimensions and detected type', function (): void {
    $media = resolve(StoreMediaFileAction::class)->handle(storedScratchFile('Photo Of A Cat.png', storedPixelPng()), 'Photo Of A Cat.png');

    expect($media->type)->toBe(MediaType::IMAGE)
        ->and($media->filename)->toBe('Photo Of A Cat.png')
        ->and($media->alt_text)->toBe('Photo Of A Cat')
        ->and($media->width)->toBe(1)
        ->and($media->height)->toBe(1)
        ->and($media->mime_type)->toBe('image/png')
        ->and($media->source)->toContain('photo-of-a-cat.png');

    Storage::disk(config()->string('filesystems.media'))->assertExists($media->source);
});

it('sanitises an svg and keeps its own mime type', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="4" height="4"/></svg>';

    $media = resolve(StoreMediaFileAction::class)->handle(storedScratchFile('logo.svg', $svg), 'logo.svg');

    $stored = Storage::disk(config()->string('filesystems.media'))->get($media->source);

    expect($media->mime_type)->toBe('image/svg+xml')
        ->and($media->type)->toBe(MediaType::IMAGE)
        ->and($stored)->not->toContain('<script')
        ->and($stored)->toContain('<rect');
});

it('returns the existing media row when the same bytes are stored twice', function (): void {
    $action = resolve(StoreMediaFileAction::class);

    $first = $action->handle(storedScratchFile('a.png', storedPixelPng()), 'a.png');
    $second = $action->handle(storedScratchFile('b.png', storedPixelPng()), 'b.png');

    expect($second->id)->toBe($first->id);
});

it('stores a thumbnail supplied as a data uri', function (): void {
    $thumbnail = 'data:image/png;base64,'.base64_encode(storedPixelPng());

    $media = resolve(StoreMediaFileAction::class)->handle(
        storedScratchFile('clip.mp4', 'not really an mp4'),
        'clip.mp4',
        ['thumbnail' => $thumbnail, 'duration' => 12, 'mime_type' => 'video/mp4'],
    );

    expect($media->type)->toBe(MediaType::VIDEO)
        ->and($media->duration)->toBe(12)
        ->and($media->thumbnail)->toContain('_thumb.png');

    Storage::disk(config()->string('filesystems.media'))->assertExists((string) $media->thumbnail);
});

it('ignores a thumbnail that is not a data uri or carries no payload', function (string $thumbnail): void {
    $media = resolve(StoreMediaFileAction::class)->handle(
        storedScratchFile('doc-'.md5($thumbnail).'.png', storedPixelPng().$thumbnail),
        'doc.png',
        ['thumbnail' => $thumbnail],
    );

    expect($media->thumbnail)->toBeNull();
})->with([
    'not a data uri' => ['https://example.com/thumb.png'],
    'empty payload' => ['data:image/png;base64,'],
    'not an image subtype' => ['data:image/php;base64,'.base64_encode('<?php echo 1;')],
]);

it('sanitises svg content stored under another extension and saves it as svg', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script><rect width="4" height="4"/></svg>';

    $media = resolve(StoreMediaFileAction::class)->handle(storedScratchFile('promo.html', $svg), 'promo.html');

    $stored = Storage::disk(config()->string('filesystems.media'))->get($media->source);

    expect($media->source)->toEndWith('.svg')
        ->and($media->filename)->toBe('promo.svg')
        ->and($stored)->not->toContain('<script');
});

it('stores a file under the extension of its detected type, not the name it was given', function (): void {
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true).'<?php system($_GET["c"]); ?>';

    $media = resolve(StoreMediaFileAction::class)->handle(storedScratchFile('shell.php', $gif), 'shell.php');

    expect($media->source)->toEndWith('.gif')
        ->and($media->filename)->toBe('shell.gif')
        ->and($media->mime_type)->toBe('image/gif');
});

it('refuses a file whose detected type is not an allowed media type', function (): void {
    resolve(StoreMediaFileAction::class)->handle(
        storedScratchFile('page.html', '<!doctype html><html><body><script>alert(1)</script></body></html>'),
        'page.html',
    );
})->throws(RuntimeException::class, 'This kind of file can’t be uploaded.');

it('prefers a caller supplied mime type over sniffing the file', function (): void {
    $media = resolve(StoreMediaFileAction::class)->handle(
        storedScratchFile('sheet.xlsx', 'PK'.str_repeat('x', 40)),
        'sheet.xlsx',
        ['mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    );

    expect($media->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($media->type)->toBe(MediaType::DOCUMENT);
});

it('fails with a friendly message when a heic image cannot be converted', function (): void {
    resolve(StoreMediaFileAction::class)->handle(storedScratchFile('broken.heic', (string) file_get_contents(base_path('tests/Fixtures/invalid.heic'))), 'broken.heic');
})->throws(RuntimeException::class, 'The image could not be converted. Upload it as a JPG or PNG instead.');

it('keeps a rotated phone photo upright when converting it', function (): void {
    $media = resolve(StoreMediaFileAction::class)->handle(storedScratchFile('portrait.heic', (string) file_get_contents(base_path('tests/Fixtures/rotated.heic'))), 'portrait.heic');

    $stored = new Imagick();
    $stored->readImageBlob((string) Storage::disk(config()->string('filesystems.media'))->get($media->source));
    $top = $stored->getImagePixelColor(16, 4)->getColor();

    expect($media->mime_type)->toBe('image/jpeg')
        ->and($media->filename)->toBe('portrait.jpg')
        ->and([$media->width, $media->height])->toBe([32, 64])
        ->and($stored->getImageOrientation())->toBe(Imagick::ORIENTATION_TOPLEFT)
        ->and($top['r'])->toBeGreaterThan(200)
        ->and($top['b'])->toBeLessThan(60);
});

it('stores a png renamed to heic as the png it is, without converting it', function (): void {
    $media = resolve(StoreMediaFileAction::class)->handle(storedScratchFile('photo.heic', storedPixelPng()), 'photo.heic');

    expect($media->mime_type)->toBe('image/png')
        ->and($media->filename)->toBe('photo.png');
});

it('refuses a disallowed file renamed to heic instead of converting it', function (): void {
    resolve(StoreMediaFileAction::class)->handle(storedScratchFile('page.heic', '<!doctype html><html><body><script>alert(1)</script></body></html>'), 'page.heic');
})->throws(RuntimeException::class, 'This kind of file can’t be uploaded.');
