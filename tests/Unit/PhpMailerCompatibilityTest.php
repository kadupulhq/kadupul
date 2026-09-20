<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native mailer composes multipart mail and resets shared translations without delivering mail', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('The inert sendmail capture uses a POSIX shell.');
    }
    $root = dirname(__DIR__, 2);
    $dir = sys_get_temp_dir() . '/mailer-capture-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $capture = $root . '/tests/Fixtures/mailer-capture.php';
    $sendmail = $dir . '/sendmail';
    file_put_contents($sendmail, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($capture) . ' --capture ' . escapeshellarg($dir . '/message') . "\n");
    chmod($sendmail, 0700);
    $fallback = $dir . '/fallback-sendmail';
    file_put_contents($fallback, "#!/bin/sh\nexit 23\n");
    chmod($fallback, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("MAILER_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'sendmail_path=' . $fallback, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . 'require ' . var_export($capture, true) . ';', $root, $dir, $sendmail),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $results = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        expect(array_column($results, 'error'))->toBe(array('', '', ''))
            ->and($results[0]['language'])->not->toBe($results[1]['language'])
            ->and($results[1]['language'])->toBe('SMTP Error: Could not authenticate.')
            ->and($results[2]['language'])->toBe($results[1]['language']);
        $message = file_get_contents($dir . '/message');
        expect($message)->toContain('Subject: Compatibility')
            ->toContain('multipart/mixed')->toContain('multipart/alternative')
            ->toContain('sample.txt')->toContain(base64_encode('sample attachment'));
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

test('PHPMailer validates encoding and prevents extra headers while generating MIME only', function (): void {
    require_once dirname(__DIR__, 2) . '/include/vendor/phpmailer/vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer();
    expect($mail::VERSION)->toBe('7.1.1');
    $mail->setFrom('sender@example.invalid', 'Kadupul');
    $mail->addAddress('recipient@example.invalid');
    $mail->Subject = 'Compatibility';
    $mail->Body = 'Body';
    $mail->XMailer = "Kadupul\r\nX-Injected: unsafe";
    $mail->Encoding = 'BASE64';
    expect($mail->preSend())->toBeTrue()
        ->and($mail->getSentMIMEMessage())->not->toContain("\r\nX-Injected:");
    $mail->Encoding = 'unsupported';
    expect($mail->preSend())->toBeFalse();
});
