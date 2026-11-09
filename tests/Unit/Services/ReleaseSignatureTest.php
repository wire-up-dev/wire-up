<?php

declare(strict_types=1);

use App\Services\ReleaseSignature;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Process::preventStrayProcesses(false);

    $this->directory = storage_path('framework/testing/release-signature-'.Str::random(8));
    File::ensureDirectoryExists($this->directory);

    foreach (['trusted', 'stranger'] as $name) {
        Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', "{$this->directory}/{$name}"])->throw();
    }

    File::put("{$this->directory}/allowed_signers", ReleaseSignature::PRINCIPAL.' namespaces="git,'.ReleaseSignature::NAMESPACE.'" '.File::get("{$this->directory}/trusted.pub"));
    config()->set('wireup.release_signers', "{$this->directory}/allowed_signers");
    config()->set('wireup.updates_path', "{$this->directory}/updates");
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function signRelease(string $key, string $message, string $namespace = ReleaseSignature::NAMESPACE): string
{
    return Process::input($message)->run(['ssh-keygen', '-Y', 'sign', '-f', $key, '-n', $namespace])->throw()->output();
}

it('accepts a message signed by a trusted key', function (): void {
    $signature = signRelease("{$this->directory}/trusted", "v1.0.0\nabc\n");

    expect(new ReleaseSignature()->verifies("v1.0.0\nabc\n", $signature))->toBeTrue()
        ->and(File::files("{$this->directory}/updates"))->toBeEmpty();
});

it('rejects altered messages, other keys, other namespaces and missing signatures', function (string $case): void {
    $signature = match ($case) {
        'altered' => signRelease("{$this->directory}/trusted", "v1.0.0\nother\n"),
        'stranger' => signRelease("{$this->directory}/stranger", "v1.0.0\nabc\n"),
        'namespace' => signRelease("{$this->directory}/trusted", "v1.0.0\nabc\n", 'file'),
        default => '',
    };

    expect(new ReleaseSignature()->verifies("v1.0.0\nabc\n", $signature))->toBeFalse();
})->with(['altered', 'stranger', 'namespace', 'missing']);

/**
 * @return Closure(string ...): ProcessResult
 */
function signedTagRepository(string $directory): Closure
{
    $repository = "{$directory}/repository";
    $git = fn (string ...$arguments): ProcessResult => Process::path($repository)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', '-c', 'gpg.format=ssh', ...$arguments])->throw();

    File::ensureDirectoryExists($repository);
    $git('init', '--quiet');
    $git('commit', '--quiet', '--allow-empty', '-m', 'Release');
    $git('-c', "user.signingkey={$directory}/trusted", 'tag', '-s', 'v1.0.0', '-m', 'v1.0.0');
    $git('-c', "user.signingkey={$directory}/stranger", 'tag', '-s', 'v1.0.1', '-m', 'v1.0.1');
    $git('tag', '-a', 'v1.0.2', '-m', 'v1.0.2');

    return $git;
}

it('verifies release tags with git', function (): void {
    signedTagRepository($this->directory);

    $verifies = fn (string $tag): bool => Process::path("{$this->directory}/repository")->run(new ReleaseSignature()->verifyTagCommand($tag))->successful();

    expect($verifies('v1.0.0'))->toBeTrue()
        ->and($verifies('v1.0.1'))->toBeFalse()
        ->and($verifies('v1.0.2'))->toBeFalse();
});

it('accepts only a trusted signed tag that carries the name of the release being installed', function (): void {
    $git = signedTagRepository($this->directory);
    $git('update-ref', 'refs/tags/v2.0.0', mb_trim($git('rev-parse', 'v1.0.0')->output()));

    $signature = new ReleaseSignature("{$this->directory}/repository");
    $signature->verifyTag('v1.0.0');

    expect(fn () => $signature->verifyTag('v2.0.0'))->toThrow(RuntimeException::class, 'The v2.0.0 tag was signed as v1.0.0, so the update did not start.')
        ->and(fn () => $signature->verifyTag('v1.0.1'))->toThrow(RuntimeException::class, 'No principal matched')
        ->and(fn () => $signature->verifyTag('v1.0.2'))->toThrow(RuntimeException::class, 'no signature found');
});

it('explains a tag that cannot be verified or read', function (array $fakes, string $message): void {
    Process::fake($fakes);

    expect(fn () => new ReleaseSignature()->verifyTag('v1.0.0'))->toThrow(RuntimeException::class, $message);
})->with([
    'a silent verification failure' => [fn (): array => ['*verify-tag*' => Process::result(exitCode: 1)], 'The v1.0.0 tag is not signed by a trusted key.'],
    'an unreadable tag' => [fn (): array => ['*verify-tag*' => Process::result(), '*cat-file*' => Process::result(exitCode: 128)], 'The v1.0.0 tag could not be read, so the update did not start.'],
]);
