<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final class Device
{
    private ?DeviceSnmpChange $snmpChange = null;

    public function __construct(public readonly int $id, private string $description, private string $hostname, private string $notes, private bool $enabled, private string $location, private string $externalId, private int $siteId = 0, private array $polling = [], private array $snmp = []) {}
    public function description(): string
    {
        return $this->description;
    }
    public function hostname(): string
    {
        return $this->hostname;
    }
    public function notes(): string
    {
        return $this->notes;
    }
    public function enabled(): bool
    {
        return $this->enabled;
    }
    public function location(): string
    {
        return $this->location;
    }
    public function externalId(): string
    {
        return $this->externalId;
    }
    public function siteId(): int
    {
        return $this->siteId;
    }
    public function polling(): array
    {
        return array_map(static fn($value): string => (string) $value, array_replace(DevicePolling::DEFAULTS, $this->polling));
    }
    public function snmp(): array
    {
        $fields = array_replace(DeviceSnmpConfiguration::PUBLIC_DEFAULTS, $this->snmp);
        foreach (['snmp_auth_protocol', 'snmp_priv_protocol'] as $key) {
            if ($fields[$key] === '' || $fields[$key] === null) {
                $fields[$key] = '[None]';
            }
        }
        return array_map(static fn($value): string => (string) $value, $fields);
    }
    public function snmpChange(): DeviceSnmpChange
    {
        return $this->snmpChange ?? new DeviceSnmpChange(['keep_credentials' => true] + $this->snmp() + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
    }
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->hostname, $this->notes, $this->enabled, $this->location, $this->externalId, $this->siteId, $this->polling(), $this->snmp()], JSON_THROW_ON_ERROR));
    }
    public function revise(string $description, string $hostname, string $notes, bool $enabled, string $location, string $externalId, string $expectedRevision, ?int $siteId = null, ?array $polling = null, #[\SensitiveParameter] ?array $snmp = null): void
    {
        if (!hash_equals($this->revision(), $expectedRevision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        $siteId ??= $this->siteId;
        if ($siteId < 0 || $siteId > 4294967295) {
            throw new \InvalidArgumentException('Select a valid site.');
        }
        $description = trim($description);
        $hostname = trim($hostname);
        if ($description === '' || !mb_check_encoding($description, 'UTF-8') || mb_strlen($description) > 150 || str_contains($description, "\0")) {
            throw new \InvalidArgumentException('The name must contain 1–150 characters.');
        }
        if ($hostname === '' || strlen($hostname) > 100 || !preg_match('/\A[a-zA-Z0-9._:\[\]%-]+\z/D', $hostname)) {
            throw new \InvalidArgumentException('Enter a hostname or IP address of at most 100 characters.');
        }
        if (strlen($notes) > 65535 || !mb_check_encoding($notes, 'UTF-8') || str_contains($notes, "\0")) {
            throw new \InvalidArgumentException('Notes must be valid text of at most 65,535 bytes.');
        }
        foreach (['Location' => $location, 'External ID' => $externalId] as $label => $value) {
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 40 || str_contains($value, "\0")) {
                throw new \InvalidArgumentException($label . ' must be valid text of at most 40 characters.');
            }
        }
        $polling = $polling === null ? $this->polling() : (new DevicePolling($polling))->fields;
        $snmpChange = $snmp === null ? null : new DeviceSnmpChange($snmp);
        $this->snmpChange = $snmpChange;
        $this->snmp = $snmpChange?->publicSettings() ?? $this->snmp();
        $this->polling = $polling;
        $this->siteId = $siteId;
        $this->description = $description;
        $this->hostname = $hostname;
        $this->notes = $notes;
        $this->enabled = $enabled;
        $this->location = $location;
        $this->externalId = $externalId;
    }
}
