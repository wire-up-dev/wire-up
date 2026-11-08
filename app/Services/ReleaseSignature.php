<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

final class ReleaseSignature
{
    public const string PRINCIPAL = 'release@wire-up.dev';

    public const string NAMESPACE = 'wireup-release';

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
