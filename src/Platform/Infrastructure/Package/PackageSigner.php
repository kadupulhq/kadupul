<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Package;

/**
 * Signs template package XML the way lib/import.php verifies it: each file
 * over its decoded bytes, then the document exactly as
 * import_read_package_data() rebuilds it, with the signature line blanked.
 * Every RSA key of 2048 bits or more has a PEM over 200 bytes, so the
 * importer verifies these signatures with SHA-256.
 */
final readonly class PackageSigner
{
    /** import_read_package_data() replaces the whole signature line with this. */
    private const string BLANK_SIGNATURE = '   <signature></signature>';

    public function sign(string $xml, \OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new PackageSigningFailed('The signing key must be an RSA key of at least 2048 bits.');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->documentElement === null) {
            throw new PackageSigningFailed('The package is not well-formed XML.');
        }
        if ($document->doctype !== null) {
            throw new PackageSigningFailed('The package declares a document type.');
        }

        $root = $document->documentElement;
        $path = new \DOMXPath($document);
        $files = $path->query('/*/files/file');
        if ($path->query('/*/files')->length !== 1 || $files->length === 0) {
            throw new PackageSigningFailed('The package must have one files element with at least one file.');
        }
        foreach ($files as $file) {
            $this->signFile($document, $path, $file, $key);
        }

        $this->replace($document, $path, $root, 'publickey', base64_encode($details['key']));
        $signature = $this->replace($document, $path, $root, 'signature', '');
        $this->placeOnOwnLine($document, $signature);

        return $this->signDocument($document->saveXML($root, LIBXML_NOEMPTYTAG), $key);
    }

    private function signFile(\DOMDocument $document, \DOMXPath $path, \DOMElement $file, \OpenSSLAsymmetricKey $key): void
    {
        $data = $path->query('data', $file);
        if ($data->length !== 1) {
            throw new PackageSigningFailed('Every package file needs exactly one data element.');
        }
        // Decoded the same lenient way import_package() decodes it before verifying.
        if (!openssl_sign(base64_decode($data->item(0)->textContent), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PackageSigningFailed('OpenSSL could not sign a package file.');
        }
        $this->replace($document, $path, $file, 'filesignature', base64_encode($signature));
    }

    private function replace(\DOMDocument $document, \DOMXPath $path, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $existing = $path->query($name, $parent);
        if ($existing->length > 1) {
            throw new PackageSigningFailed('The package has more than one ' . $name . ' element.');
        }
        if ($existing->length === 1) {
            $element = $existing->item(0);
            $element->textContent = $value;

            return $element;
        }
        $element = $document->createElement($name);
        $element->textContent = $value;
        $parent->appendChild($element);
        $this->placeOnOwnLine($document, $element);

        return $element;
    }

    private function placeOnOwnLine(\DOMDocument $document, \DOMElement $element): void
    {
        $before = $element->previousSibling;
        if (!$before instanceof \DOMText || preg_match('/\n[ \t]*$/', $before->data) !== 1) {
            $element->parentNode->insertBefore($document->createTextNode("\n   "), $element);
        }
        $after = $element->nextSibling;
        if (!$after instanceof \DOMText || !str_starts_with($after->data, "\n")) {
            $element->parentNode->insertBefore($document->createTextNode("\n"), $after);
        }
    }

    private function signDocument(string $xml, \OpenSSLAsymmetricKey $key): string
    {
        $lines = explode("\n", $xml);
        // The importer blanks every line holding '<signature>', so only ours may.
        $marked = array_keys(array_filter($lines, static fn(string $line): bool => str_contains($line, '<signature>')));
        if (count($marked) !== 1 || trim($lines[$marked[0]]) !== '<signature></signature>') {
            throw new PackageSigningFailed('The package has text the importer would read as a second signature.');
        }
        $lines[$marked[0]] = self::BLANK_SIGNATURE;
        if (!openssl_sign(implode("\n", $lines), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PackageSigningFailed('OpenSSL could not sign the package.');
        }
        $lines[$marked[0]] = '   <signature>' . base64_encode($signature) . '</signature>';

        return implode("\n", $lines);
    }
}
