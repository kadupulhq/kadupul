<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

use phpseclib4\Crypt\Rijndael;
use phpseclib4\Crypt\RSA;

/**
 * One RRDtool proxy frame, as encrypt() and decrypt() in rrdproxy's
 * lib/functions.php write and read it at 54aad57:
 *
 * - three hex digits giving the length of the next field;
 * - base64 of a random 32-byte key under RSA-OAEP, SHA-256 and MGF1-SHA-256;
 * - base64 of the payload under AES-256-CBC with a zero IV and PKCS#7 padding.
 *
 * rrdproxy relies on the phpseclib 4 defaults for RSA; they are spelled out
 * here so a change of defaults cannot silently change the wire. The frame has
 * no MAC. Adding one needs a proxy release and a protocol version, so this
 * class reproduces the format as it is.
 */
final class ProxyCipher
{
    private const KEY_BYTES = 32;

    public function encrypt(string $payload, string $publicKey): string
    {
        $key = random_bytes(self::KEY_BYTES);
        $wrapped = base64_encode($this->publicKey($publicKey)->encrypt($key));
        if (strlen($wrapped) > 0xfff) {
            throw new \UnexpectedValueException('The RRDtool proxy key is too large for the frame header.');
        }

        return sprintf('%03x', strlen($wrapped)) . $wrapped . base64_encode($this->aes($key)->encrypt($payload));
    }

    public function decrypt(string $frame, string $privateKey): string
    {
        if (strlen($frame) < 4 || !ctype_xdigit(substr($frame, 0, 3))) {
            throw new \UnexpectedValueException('The RRDtool proxy frame has no length header.');
        }
        $length = (int) hexdec(substr($frame, 0, 3));
        if ($length < 1 || strlen($frame) <= 3 + $length) {
            throw new \UnexpectedValueException('The RRDtool proxy frame is truncated.');
        }
        $wrapped = base64_decode(substr($frame, 3, $length), true);
        $ciphertext = base64_decode(substr($frame, 3 + $length), true);
        if ($wrapped === false || $wrapped === '' || $ciphertext === false || $ciphertext === '') {
            throw new \UnexpectedValueException('The RRDtool proxy frame is not base64.');
        }

        $private = RSA::loadPrivateKey($privateKey);
        if (!$private instanceof RSA\PrivateKey) {
            throw new \UnexpectedValueException('The RRDtool proxy client key is not an RSA private key.');
        }
        $key = $private->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->decrypt($wrapped);
        // rrdproxy also accepts longer keys cut to 32 bytes for phpseclib 2
        // clients; it never sends one, so anything else here is not its frame.
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \UnexpectedValueException('The RRDtool proxy frame key has the wrong length.');
        }

        return $this->aes($key)->decrypt($ciphertext);
    }

    /** The colon-separated MD5 form that rrdproxy displays and Kadupul stores. */
    public function fingerprint(string $publicKey): string
    {
        return $this->publicKey($publicKey)->getFingerprint('md5');
    }

    private function publicKey(string $publicKey): RSA\PublicKey
    {
        $key = RSA::loadPublicKey($publicKey);
        if (!$key instanceof RSA\PublicKey) {
            throw new \UnexpectedValueException('The RRDtool proxy key is not an RSA public key.');
        }

        return $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
    }

    private function aes(string $key): Rijndael
    {
        $aes = new Rijndael('cbc');
        $aes->setKey($key);
        $aes->setIV(str_repeat("\0", 16));

        return $aes;
    }
}
