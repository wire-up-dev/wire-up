<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReleaseSignature
{
    public const string PRINCIPAL = 'release@wire-up.dev';

    public const string NAMESPACE = 'wireup-release';

    public function __construct(private ?string $repository = null) {}

    /**
     * @return list<string>
     */
    public function verifyTagCommand(string $tag): array
    {
        return [
            'git',
            '-c', 'gpg.format=ssh',
            '-c', 'gpg.ssh.allowedSignersFile='.$this->signersFile(),
            '-c', 'gpg.openpgp.program=false',
            '-c', 'gpg.x509.program=false',
            'verify-tag', $tag,
        ];
    }

    public function verifyTag(string $tag): void
    {
        $verification = Process::path($this->repository ?? base_path())->run($this->verifyTagCommand($tag));

        throw_if($verification->failed(), RuntimeException::class, mb_trim($verification->output()."\n".$verification->errorOutput()) ?: "The {$tag} tag is not signed by a trusted key.");

        $object = Process::path($this->repository ?? base_path())->run(['git', 'cat-file', 'tag', $tag]);
        $signedName = preg_match('/^tag (\S+)$/m', $object->output(), $match) === 1 ? $match[1] : null;

        throw_if($signedName !== $tag, RuntimeException::class, $signedName === null
            ? "The {$tag} tag could not be read, so the update did not start."
            : "The {$tag} tag was signed as {$signedName}, so the update did not start.");
    }

    public function verifies(string $message, string $signature): bool
    {
        if (mb_trim($signature) === '') {
            return false;
        }

        $file = config('wireup.updates_path').'/signature-'.Str::random(16).'.sig';

        try {
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $signature);

            return Process::input($message)->run([
                'ssh-keygen', '-Y', 'verify',
                '-f', $this->signersFile(),
                '-I', self::PRINCIPAL,
                '-n', self::NAMESPACE,
                '-s', $file,
            ])->successful();
        } finally {
            File::delete($file);
        }
    }

    private function signersFile(): string
    {
        return (string) config('wireup.release_signers');
    }
}
