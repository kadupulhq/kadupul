<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native mailer composes multipart mail and resets shared translations without delivering mail', function (bool $configured): void {
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
            array(PHP_BINARY, '-d', 'sendmail_path=' . ($configured ? $fallback : $sendmail), '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . 'require ' . var_export($capture, true) . ';', $root, $dir, $configured ? $sendmail : ''),
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
})->with(array(true, false));

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

test('XOAUTH2 accepts only successful final SMTP replies without opening a connection', function ($length, $replies, $expected): void {
    require_once dirname(__DIR__, 2) . '/include/vendor/phpmailer/vendor/autoload.php';
    $provider = new class ($length) implements PHPMailer\PHPMailer\OAuthTokenProvider {
        private int $length;

        public function __construct(int $length)
        {
            $this->length = $length;
        }

        public function getOauth64()
        {
            return str_repeat('a', $this->length);
        }
    };
    $smtp = new class ($replies) extends PHPMailer\PHPMailer\SMTP {
        public array $replies;

        public function __construct(array $replies)
        {
            $this->replies = $replies;
            $this->server_caps = array('EHLO' => 'fixture', 'AUTH' => array('XOAUTH2'));
        }

        protected function sendCommand($command, $commandstring, $expect)
        {
            $code = array_shift($this->replies);
            $this->last_reply = $code . ' fixture reply';

            return in_array($code, (array) $expect, true);
        }
    };
    expect($smtp->authenticate('fixture', '', 'XOAUTH2', $provider))->toBe($expected)
        ->and($smtp->replies)->toBe(array());
})->with(array(
    array(500, array(334, 235), true),
    array(500, array(334, 334, 235), true),
    array(500, array(334, 334, 535), false),
    array(500, array(535), false),
    array(500, array(334, 535), false),
    array(20, array(235), true),
    array(20, array(535), false),
    array(0, array(235), true)
));

test('SMTP DATA generator retains the first line and normalizes message data without a connection', function ($message, $expected): void {
    require_once dirname(__DIR__, 2) . '/include/vendor/phpmailer/vendor/autoload.php';
    $smtp = new class () extends PHPMailer\PHPMailer\SMTP {
        public string $wire = '';
        public array $commands = array();

        protected function sendCommand($command, $commandstring, $expect)
        {
            $this->commands[] = $command;
            $this->last_reply = $expect . ' fixture reply';

            return true;
        }

        public function client_send($data, $command = '')
        {
            $this->wire .= $data;

            return strlen($data);
        }
    };
    $smtp->Timelimit = 3;
    expect($smtp->data($message))->toBeTrue()
        ->and($smtp->wire)->toBe($expected)
        ->and($smtp->commands)->toBe(array('DATA', 'DATA END'))
        ->and($smtp->Timelimit)->toBe(3);
})->with(array(
    array("Subject: fixture\r\n\r\n.first\rsecond\nthird\r\n", "Subject: fixture\r\n\r\n..first\r\nsecond\r\nthird\r\n\r\n"),
    array('', "\r\n"),
    array('Body without headers', "Body without headers\r\n")
));
