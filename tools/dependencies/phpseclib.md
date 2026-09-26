# phpseclib branch policy

- Main: Composer-managed phpseclib `^4.0` (currently 4.0.1), PHP >=8.4.
- `lts/1.2`: Composer-managed phpseclib `^3.0`, existing PHP >=8.1 support.

The installer floor and Composer platform resolution on main are both 8.4.0.
Main now requires PHP 8.4; CI validates that floor. Do not backport this major upgrade
or its PHP floor to LTS.

The application RSA key-generation helper uses the `phpseclib4` namespace.
Regression tests execute that real helper in isolation, verify generation,
fingerprints, signing/encryption, PEM import and OpenSSL interoperability, and
prove that existing stored keys are not replaced. No production keys are used.

This upgrades the Composer-managed package, not every historical copy: the
separate manually bundled `include/vendor/phpseclib/Crypt` tree remains.

The RRDtool proxy client in `lib/rrd.php` uses the `phpseclib4` namespace
through `Kadupul\Graphing\Infrastructure\Rrd\ProxyCipher`, which writes the
frame rrdproxy 54aad57 reads: RSA-OAEP with SHA-256 and MGF1-SHA-256 around a
32-byte key, then AES-256-CBC with a zero IV. `RrdProxyInteropTest` checks
that format against the OpenSSL command line on every run, and against
rrdproxy's own `encrypt()` and `decrypt()` when `RRDPROXY_SOURCE` names a
checkout. Only rrdproxy on phpseclib `^4.0` has been tested. The older
client sent a 192-byte key, which does not fit SHA-256 OAEP under a 2048-bit
key, so a proxy that still expects that frame is not supported.

Upstream manifest: https://github.com/phpseclib/phpseclib/blob/4.0.1/composer.json
