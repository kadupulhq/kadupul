<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\Platform\Infrastructure\Symfony\Console\SignPackageCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

require_once __DIR__ . '/package_signing_functions.php';

/**
 * Packages signed by kadupul:package:sign must pass the same trust and
 * signature checks the web importer, preview diff and installer run, and
 * anything those checks rejected before must still be rejected.
 */
final class PackageSigningTest extends TestCase
{
    private const string KEY_VARIABLE = 'KADUPUL_PACKAGE_SIGNING_KEY';
    private const string BUNDLED = __DIR__ . '/../../install/templates/';

    /** @var array<int, \OpenSSLAsymmetricKey> */
    private static array $keys = [];

    private string $directory;

    public static function setUpBeforeClass(): void
    {
        PackageSigning\load();
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-package-signing-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $GLOBALS['package_signing'] = ['logs' => [], 'templates' => []];
        $GLOBALS['config']['base_path'] = $this->directory;
        $this->anchor(self::key(4096));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['package_signing'], $_SESSION['sess_import_package']);
        putenv(self::KEY_VARIABLE);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testResignedCactiPackageImportsUnderTheKadupulAnchor(): void
    {
        $output = $this->directory . '/Apache_Webserver.xml.gz';
        [$status, $display] = $this->sign(self::BUNDLED . 'Apache_Webserver.xml.gz', $output, self::pem(self::key(4096)));

        self::assertSame(0, $status, $display);
        self::assertStringContainsString('Signing key SHA-256: ' . self::fingerprint(self::key(4096)), $display);
        self::assertSame(self::publicKey(self::key(4096)), PackageSigning\import_package_get_public_key($output));
        self::assertIsArray(PackageSigning\import_package($output, 1, false, false, true, false, false), $this->logs());
        self::assertContains('NOTE: File is Signed Correctly', $GLOBALS['package_signing']['logs']);
        self::assertSame([$this->template(self::BUNDLED . 'Apache_Webserver.xml.gz')], $GLOBALS['package_signing']['templates']);
    }

    public function testUnsignedPackageIsSignedFromABase64DerKey(): void
    {
        $input = $this->write('unsigned.xml.gz', $this->unsigned());
        $output = $this->directory . '/signed.xml.gz';
        openssl_pkey_export(self::key(4096), $pem);
        $der = base64_encode(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem)));

        [$status, $display] = $this->sign($input, $output, $der);

        self::assertSame(0, $status, $display);
        self::assertIsArray(PackageSigning\import_package($output, 1, false, false, true, false, false), $this->logs());
        self::assertSame(2, count(preg_grep('/^NOTE: File OK: /', $GLOBALS['package_signing']['logs'])));
        self::assertSame(['<template/>'], $GLOBALS['package_signing']['templates']);
    }

    public function testSigningInPlaceReplacesThePackage(): void
    {
        $package = $this->write('in-place.xml.gz', $this->unsigned());

        [$status, $display] = $this->sign($package, null, self::pem(self::key(4096)));

        self::assertSame(0, $status, $display);
        self::assertIsArray(PackageSigning\import_read_package_data($package, $key), $this->logs());
        self::assertSame([], glob($this->directory . '/.*.tmp*') ?: []);
    }

    public function testATamperedFileIsRejectedEvenWithAValidPackageSignature(): void
    {
        $output = $this->directory . '/signed.xml.gz';
        $this->sign($this->write('unsigned.xml.gz', $this->unsigned()), $output, self::pem(self::key(4096)));
        $xml = str_replace(base64_encode('<?php echo 1;'), base64_encode('<?php echo 2;'), gzdecode(file_get_contents($output)));
        file_put_contents($output, gzencode(self::signPackageOnly($xml, self::key(4096))));

        self::assertIsArray(PackageSigning\import_read_package_data($output, $key), $this->logs());
        self::assertFalse(PackageSigning\import_package($output, 1, false, false, true, false, false));
        self::assertContains('FATAL: Could not Verify Signature for file: scripts/ss_test.php', $GLOBALS['package_signing']['logs']);
        self::assertSame([], $GLOBALS['package_signing']['templates']);
    }

    public function testTamperedPackageXmlIsRejected(): void
    {
        $output = $this->directory . '/signed.xml.gz';
        $this->sign(self::BUNDLED . 'Apache_Webserver.xml.gz', $output, self::pem(self::key(4096)));
        file_put_contents($output, gzencode(str_replace('<name>Apache Webserver</name>', '<name>Apache Webserver 2</name>', gzdecode(file_get_contents($output)))));

        self::assertFalse(PackageSigning\import_package($output, 1, false, false, true, false, false));
        self::assertContains('FATAL: File has been Tampered with.', $GLOBALS['package_signing']['logs']);
    }

    public function testAPackageSignedByAnUntrustedKeyIsRejected(): void
    {
        $output = $this->directory . '/signed.xml.gz';
        $this->sign($this->write('unsigned.xml.gz', $this->unsigned()), $output, self::pem(self::key(2048)));

        self::assertFalse(PackageSigning\import_package($output, 1, false, false, true, false, false));
        self::assertStringContainsString('not a built-in trusted key', $this->logs());
    }

    public function testClaimingTheTrustedKeyWithoutItsPrivateKeyIsRejected(): void
    {
        $output = $this->directory . '/signed.xml.gz';
        $this->sign($this->write('unsigned.xml.gz', $this->unsigned()), $output, self::pem(self::key(2048)));
        $xml = gzdecode(file_get_contents($output));
        $xml = preg_replace('#<publickey>[^<]*</publickey>#', '<publickey>' . base64_encode(self::publicKey(self::key(4096))) . '</publickey>', $xml);
        file_put_contents($output, gzencode($xml));

        self::assertFalse(PackageSigning\import_package($output, 1, false, false, true, false, false));
        self::assertContains('FATAL: File has been Tampered with.', $GLOBALS['package_signing']['logs']);
    }

    public function testAKeyBelow2048BitsIsRejectedEvenAsTheAnchor(): void
    {
        $weak = self::key(1024);
        $this->anchor($weak);
        $xml = str_replace('</xml>', '<publickey>' . base64_encode(self::publicKey($weak)) . "</publickey>\n   <signature></signature>\n</xml>", $this->unsigned());
        foreach (['<?php echo 1;', '<template/>'] as $file) {
            openssl_sign($file, $signature, $weak, OPENSSL_ALGO_SHA256);
            $xml = str_replace('<data>' . base64_encode($file) . '</data>', '<data>' . base64_encode($file) . '</data><filesignature>' . base64_encode($signature) . '</filesignature>', $xml);
        }
        $package = $this->write('weak.xml.gz', self::signPackageOnly($xml, $weak));

        self::assertFalse(PackageSigning\import_package($package, 1, false, false, true, false, false));
        self::assertStringContainsString('signing key is 1024 bits (minimum 2048 required)', $this->logs());
    }

    public function testTheCommandRefusesAKeyBelow2048Bits(): void
    {
        $output = $this->directory . '/signed.xml.gz';

        [$status, $display] = $this->sign($this->write('unsigned.xml.gz', $this->unsigned()), $output, self::pem(self::key(1024)));

        self::assertSame(1, $status);
        self::assertStringContainsString('at least 2048 bits', $display);
        self::assertFileDoesNotExist($output);
    }

    public function testTheCommandNeverPrintsTheKey(): void
    {
        $output = $this->directory . '/signed.xml.gz';
        $input = $this->write('unsigned.xml.gz', $this->unsigned());

        [$missing, $missingDisplay] = $this->sign($input, $output, null);
        $secret = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASC' . str_repeat('A', 40);
        [$invalid, $invalidDisplay] = $this->sign($input, $output, $secret);
        $pem = self::pem(self::key(4096));
        [$signed, $signedDisplay] = $this->sign($input, $output, $pem);

        self::assertSame([1, 1, 0], [$missing, $invalid, $signed]);
        self::assertStringContainsString(self::KEY_VARIABLE . ' is not set', $missingDisplay);
        self::assertStringContainsString(self::KEY_VARIABLE . ' is not a PEM or base64 DER RSA private key', $invalidDisplay);
        self::assertStringNotContainsString('MIIEvQIBADANBgkqhkiG9w0BAQEFAASC', $invalidDisplay);
        self::assertStringNotContainsString(substr(explode("\n", $pem)[3], 0, 32), $signedDisplay);
        self::assertStringContainsString('is not the built-in Kadupul package key', $signedDisplay);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPackages(): iterable
    {
        yield 'two signature lines' => ["<xml>\n<info><name>x</name></info>\n<files><file><name>t.xml</name><data>PHQvPg==</data></file></files>\n<signature></signature>\n<![CDATA[<signature>]]>\n</xml>"];
        yield 'document type' => ["<!DOCTYPE xml [<!ENTITY e SYSTEM \"file:///etc/passwd\">]>\n<xml><info><name>&e;</name></info><files><file><name>t.xml</name><data>PHQvPg==</data></file></files></xml>"];
        yield 'no files' => ["<xml><info><name>x</name></info><files></files></xml>"];
        yield 'not xml' => ['not xml'];
    }

    #[DataProvider('refusedPackages')]
    public function testTheCommandRefusesPackagesTheImporterCannotRead(string $xml): void
    {
        $output = $this->directory . '/signed.xml.gz';

        [$status, $display] = $this->sign($this->write('bad.xml.gz', $xml), $output, self::pem(self::key(4096)));

        self::assertSame(1, $status, $display);
        self::assertFileDoesNotExist($output);
    }

    public function testTheCommandRefusesAnUncompressedPackage(): void
    {
        $input = $this->directory . '/plain.xml.gz';
        file_put_contents($input, $this->unsigned());
        $output = $this->directory . '/signed.xml.gz';

        [$status, $display] = $this->sign($input, $output, self::pem(self::key(4096)));

        self::assertSame(1, $status, $display);
        self::assertStringContainsString('as a gzip package', $display);
        self::assertFileDoesNotExist($output);
    }

    public function testThePreviewDiffReadsOnlyVerifiedFiles(): void
    {
        $output = $this->directory . '/signed.xml.gz';
        $this->sign($this->write('unsigned.xml.gz', $this->unsigned()), $output, self::pem(self::key(4096)));
        $_SESSION['sess_import_package'] = file_get_contents($output);

        self::assertSame('<?php echo 1;', PackageSigning\package_file_get_contents('scripts/ss_test.php'));

        $xml = gzdecode($_SESSION['sess_import_package']);
        $xml = preg_replace('#(<filesignature>)[^<]{8}#', '$1AAAAAAAA', $xml, 1);
        $_SESSION['sess_import_package'] = gzencode(self::signPackageOnly($xml, self::key(4096)));

        self::assertFalse(PackageSigning\package_file_get_contents('scripts/ss_test.php'));
    }

    public function testTheUploadTrustGateAcceptsOnlyTheKadupulKey(): void
    {
        $trusted = $this->directory . '/trusted.xml.gz';
        $untrusted = $this->directory . '/untrusted.xml.gz';
        $input = $this->write('unsigned.xml.gz', $this->unsigned());
        $this->sign($input, $trusted, self::pem(self::key(4096)));
        $this->sign($input, $untrusted, self::pem(self::key(2048)));

        self::assertTrue(PackageSigning\package_validate_signature($trusted));
        self::assertFalse(PackageSigning\package_validate_signature($untrusted));
    }

    /** @return iterable<string, array{string}> */
    public static function bundledPackages(): iterable
    {
        foreach (glob(self::BUNDLED . '*.xml.gz') as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('bundledPackages')]
    public function testBundledCactiPackagesStillVerify(string $path): void
    {
        unset($GLOBALS['package_signing']['anchor']);

        self::assertIsArray(PackageSigning\import_package($path, 1, false, false, true, false, false), $this->logs());
        self::assertNotContains('FATAL: File has been Tampered with.', $GLOBALS['package_signing']['logs']);
    }

    public function testTheProductionKadupulKeyIsTrustedAndVerifiedWithSha256(): void
    {
        unset($GLOBALS['package_signing']['anchor']);
        $pem = PackageSigning\production_public_key_kadupul();
        $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
        $fingerprint = hash('sha256', base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem)));

        self::assertSame('7a9479b973d128752276d80b903ac2715a96fcab8b2ef2c58ac43bb09f59fc60', $fingerprint);
        self::assertSame((new \ReflectionClassConstant(SignPackageCommand::class, 'KADUPUL_KEY_SHA256'))->getValue(), $fingerprint);
        self::assertSame(4096, $details['bits']);
        // import_read_package_data() and import_package() pick SHA1 below 200 bytes.
        self::assertGreaterThanOrEqual(200, strlen($pem));
        self::assertTrue(PackageSigning\is_cacti_public_key($pem . "\n"));
        self::assertTrue(PackageSigning\is_cacti_public_key(PackageSigning\get_public_key_sha256()));
        self::assertFalse(PackageSigning\is_cacti_public_key(self::publicKey(self::key(2048))));
    }

    /** @return array{int, string} */
    private function sign(string $input, ?string $output, ?string $key): array
    {
        putenv($key === null ? self::KEY_VARIABLE : self::KEY_VARIABLE . '=' . $key);
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            $arguments = ['command' => 'kadupul:package:sign', 'package' => $input];
            if ($output !== null) {
                $arguments['output'] = $output;
            }
            $status = $tester->run($arguments);

            return [$status, $tester->getDisplay()];
        } finally {
            putenv(self::KEY_VARIABLE);
            $kernel->shutdown();
        }
    }

    private function anchor(\OpenSSLAsymmetricKey $key): void
    {
        $GLOBALS['package_signing']['anchor'] = trim(self::publicKey($key));
    }

    private function unsigned(): string
    {
        return '<xml><info><name>Unsigned</name></info><files>'
            . '<file><name>scripts/ss_test.php</name><data>' . base64_encode('<?php echo 1;') . '</data></file>'
            . '<file><name>Test.xml</name><type>template</type><data>' . base64_encode('<template/>') . '</data></file>'
            . '</files></xml>';
    }

    private function write(string $name, string $xml): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, gzencode($xml));

        return $path;
    }

    private function template(string $package): string
    {
        $xml = new \SimpleXMLElement(gzdecode(file_get_contents($package)), LIBXML_NONET);
        foreach ($xml->files->file as $file) {
            if ((string) $file->type === 'template') {
                return base64_decode((string) $file->data);
            }
        }
        self::fail('No template in ' . $package);
    }

    private function logs(): string
    {
        return implode(PHP_EOL, $GLOBALS['package_signing']['logs']);
    }

    /** Signs the package XML the way import_read_package_data() rebuilds it, leaving file signatures alone. */
    private static function signPackageOnly(string $xml, \OpenSSLAsymmetricKey $key): string
    {
        $lines = explode("\n", $xml);
        $signed = array_map(static fn(string $line): string => str_contains($line, '<signature>') ? '   <signature></signature>' : $line, $lines);
        openssl_sign(implode("\n", $signed), $signature, $key, OPENSSL_ALGO_SHA256);

        return str_replace('   <signature></signature>', '   <signature>' . base64_encode($signature) . '</signature>', implode("\n", $signed));
    }

    private static function key(int $bits): \OpenSSLAsymmetricKey
    {
        return self::$keys[$bits] ??= openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private static function pem(\OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private static function publicKey(\OpenSSLAsymmetricKey $key): string
    {
        return openssl_pkey_get_details($key)['key'];
    }

    private static function fingerprint(\OpenSSLAsymmetricKey $key): string
    {
        return hash('sha256', base64_decode(preg_replace('/-----[^-]+-----|\s/', '', self::publicKey($key))));
    }
}
