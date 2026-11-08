<?php

declare(strict_types=1);

use App\Services\ReleaseSignature;
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

it('verifies release tags with git', function (): void {
    $repository = "{$this->directory}/repository";
    $git = fn (string ...$arguments) => Process::path($repository)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', '-c', 'gpg.format=ssh', ...$arguments])->throw();

    File::ensureDirectoryExists($repository);
    $git('init', '--quiet');
    $git('commit', '--quiet', '--allow-empty', '-m', 'Release');
    $git('-c', "user.signingkey={$this->directory}/trusted", 'tag', '-s', 'v1.0.0', '-m', 'v1.0.0');
    $git('-c', "user.signingkey={$this->directory}/stranger", 'tag', '-s', 'v1.0.1', '-m', 'v1.0.1');
    $git('tag', '-a', 'v1.0.2', '-m', 'v1.0.2');

    $verifies = fn (string $tag): bool => Process::path($repository)->run(new ReleaseSignature()->verifyTagCommand($tag))->successful();

    expect($verifies('v1.0.0'))->toBeTrue()
        ->and($verifies('v1.0.1'))->toBeFalse()
        ->and($verifies('v1.0.2'))->toBeFalse();
});
