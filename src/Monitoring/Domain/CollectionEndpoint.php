<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * Where and as whom a collector connects, by reference only. The device
 * address is resolved by the collector from the device id, and the secret
 * from its own store, so neither can leak through a plan.
 */
final readonly class CollectionEndpoint
{
    private const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D';

    /**
     * @param bool|null $tlsVerify null leaves the collector default, which verifies
     */
    public function __construct(
        public string $credentialRef,
        public ?int $port = null,
        public ?bool $tlsVerify = null,
        public ?string $caRef = null,
    ) {
        if (preg_match(self::REFERENCE, $credentialRef) !== 1 || strlen($credentialRef) > 128) {
            throw new InvalidCollectionPlan('A credential reference names an entry in the collector\'s secret store, such as snmp/edge-rtr-07; it is never the secret itself.');
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidCollectionPlan(sprintf('Port %d is outside 1 to 65535.', $port));
        }
        if ($caRef !== null && preg_match(self::REFERENCE, $caRef) !== 1) {
            throw new InvalidCollectionPlan('A CA reference names a certificate in the collector\'s store, such as ca/internal-root.');
        }
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        return ['credential_ref' => $this->credentialRef, 'port' => $this->port, 'tls_verify' => $this->tlsVerify, 'ca_ref' => $this->caRef];
    }
}
