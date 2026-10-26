<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Infrastructure\Package\PackageSigner;
use Kadupul\Platform\Infrastructure\Package\PackageSigningFailed;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'kadupul:package:sign',
    description: 'Sign a template package with a Kadupul package signing key.',
    help: <<<'HELP'
        Signs each file in an unsigned or Cacti-signed <info>.xml.gz</info> package and
        the package itself, embeds the signing key's public key, and writes gzip output.
        Without an output path the package is replaced in place and keeps its file
        mode. A new output file takes its mode from the umask; the web server user
        must be able to read it for the installer to import it, so sign with umask
        022 or chmod the file afterwards.

        The private key comes only from the <info>KADUPUL_PACKAGE_SIGNING_KEY</info>
        environment variable, as PEM or base64 DER (PKCS#8). It is never read from a
        file and never printed. With the key in the macOS Keychain:

          <info>KADUPUL_PACKAGE_SIGNING_KEY="$(security find-generic-password -s kadupul-package-signing -a kadupul -w)" php bin/console kadupul:package:sign install/templates/Apache_Webserver.xml.gz</info>

        Installations import the result only when it is signed by the built-in Kadupul
        key, DER SHA-256 7a9479b973d128752276d80b903ac2715a96fcab8b2ef2c58ac43bb09f59fc60.
        The command warns when any other key is used.
        HELP,
)]
final readonly class SignPackageCommand
{
    private const string KEY_VARIABLE = 'KADUPUL_PACKAGE_SIGNING_KEY';

    /** DER SHA-256 of get_public_key_kadupul() in lib/import.php. */
    private const string KADUPUL_KEY_SHA256 = '7a9479b973d128752276d80b903ac2715a96fcab8b2ef2c58ac43bb09f59fc60';

    public function __construct(private PackageSigner $signer) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The .xml.gz package to sign.')]
        string $package,
        #[Argument('Where to write the signed package; defaults to replacing the input.')]
        ?string $output = null,
    ): int {
        try {
            $key = $this->privateKey();
            $compressed = is_file($package) ? file_get_contents($package) : false;
            // Checking the gzip magic first keeps gzdecode() from warning on plain input.
            $xml = $compressed === false || !str_starts_with($compressed, "\x1f\x8b") ? false : gzdecode($compressed);
            if ($xml === false) {
                throw new PackageSigningFailed('Cannot read ' . $package . ' as a gzip package.');
            }
            $signed = $this->signer->sign($xml, $key);
            $target = $output ?? $package;
            $this->write($target, gzencode($signed, 9));
        } catch (PackageSigningFailed $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $fingerprint = hash('sha256', base64_decode(preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($key)['key'])));
        $io->writeln('Signing key SHA-256: ' . $fingerprint);
        if ($fingerprint !== self::KADUPUL_KEY_SHA256) {
            $io->warning('This key is not the built-in Kadupul package key; Kadupul installations will reject the package.');
        }
        $io->success('Signed ' . $target);

        return Command::SUCCESS;
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $encoded = trim((string) getenv(self::KEY_VARIABLE));
        if ($encoded === '') {
            throw new PackageSigningFailed(self::KEY_VARIABLE . ' is not set.');
        }
        if (!str_starts_with($encoded, '-----BEGIN ')) {
            $der = base64_decode((string) preg_replace('/\s+/', '', $encoded), true);
            $encoded = $der === false || $der === '' ? '' : "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----\n";
        }
        $key = $encoded === '' ? false : openssl_pkey_get_private($encoded);
        // Drain the OpenSSL error queue so nothing about the input surfaces later.
        while (openssl_error_string() !== false);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new PackageSigningFailed(self::KEY_VARIABLE . ' is not a PEM or base64 DER RSA private key.');
        }
        if ($details['bits'] < 2048) {
            throw new PackageSigningFailed('The signing key must be at least 2048 bits; installations reject smaller keys.');
        }

        return $key;
    }

    /** Writes beside the target and renames, so a failure leaves the old package intact. */
    private function write(string $target, string $bytes): void
    {
        $directory = realpath(dirname($target));
        $temporary = $directory === false || !is_writable($directory) ? false : tempnam($directory, '.' . basename($target) . '.tmp');
        if ($temporary === false || realpath(dirname($temporary)) !== $directory) {
            if (is_string($temporary)) {
                unlink($temporary);
            }
            throw new PackageSigningFailed('Cannot create a temporary file beside ' . $target . '.');
        }
        // tempnam() creates the file 0600, which the web server could not read
        // once it replaced a bundled package. Keep the replaced package's mode,
        // or give a new one the mode a plain write under the umask would get.
        $existing = is_file($target) ? fileperms($target) : false;
        $mode = $existing === false ? 0666 & ~umask() : $existing & 0777;
        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, $mode) || !rename($temporary, $target)) {
            unlink($temporary);
            throw new PackageSigningFailed('Cannot write ' . $target . '.');
        }
    }
}
