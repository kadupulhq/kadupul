# phpseclib branch policy

- Main: Composer-managed phpseclib `^4.0` (currently 4.0.1), PHP >=8.1.
- `lts/1.2`: Composer-managed phpseclib `^3.0`, existing PHP >=8.0 support.

The installer floor and Composer platform resolution on main are both 8.1.0.
Main now requires PHP 8.4; CI validates that floor. Do not backport this major upgrade
or its PHP floor to LTS.

The application RSA key-generation helper uses the `phpseclib4` namespace.
Regression tests execute that real helper in isolation, verify generation,
fingerprints, signing/encryption, PEM import and OpenSSL interoperability, and
prove that existing stored keys are not replaced. No production keys are used.

This upgrades the Composer-managed package, not every historical copy: the
separate manually bundled `include/vendor/phpseclib/Crypt` tree remains. Legacy
RRD proxy code references a different, apparently invalid historical namespace;
that pre-existing path needs a separately tested protocol migration. Do not
claim that this update validates encrypted legacy RRD proxy interoperability.

Upstream manifest: https://github.com/phpseclib/phpseclib/blob/4.0.1/composer.json
