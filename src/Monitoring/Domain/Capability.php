<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * One model on one device as seen through one protocol. The same model over
 * gNMI and NETCONF is two capabilities, because either transport can fail alone.
 */
final readonly class Capability
{
    public const PROTOCOLS = ['snmp', 'gnmi', 'redfish', 'netconf', 'restconf'];

    /** @var list<string> */
    public array $provides;

    /**
     * @param string $source the protocol that reported the model
     * @param list<string> $provides capability names the registry assigns to the model; empty until assessed
     */
    public function __construct(
        public ModelReference $model,
        public string $source,
        public CapabilityStatus $status,
        public ?string $reason = null,
        array $provides = [],
    ) {
        if (!in_array($source, self::PROTOCOLS, true)) {
            throw new InvalidCapability(sprintf('Unknown capability protocol "%s"; expected one of %s.', $source, implode(', ', self::PROTOCOLS)));
        }
        foreach ($provides as $name) {
            if (!is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $name) !== 1) {
                throw new InvalidCapability('Capability names must be tokens such as openconfig-interfaces or snmp:if-mib.');
            }
        }
        $this->provides = array_values(array_unique($provides));
    }

    public function key(): string
    {
        return $this->model->key() . '#' . $this->source;
    }

    /** @param list<string> $provides */
    public function assessed(CapabilityStatus $status, ?string $reason, array $provides): self
    {
        return new self($this->model, $this->source, $status, $reason, $provides);
    }
}
