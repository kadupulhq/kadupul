<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A device's editable settings, identified by a fixed id.
 * Changes go through revise(), which rejects stale edits and invalid values.
 */
final class Device
{
    private ?DeviceSnmpChange $snmpChange = null;

    /**
     * Loads a device from stored values; no validation runs until revise() is called.
     */
    public function __construct(public readonly int $id, private string $description, private string $hostname, private string $notes, private bool $enabled, private string $location, private string $externalId, private int $siteId = 0, private array $polling = [], private array $snmp = []) {}
    /**
     * Returns the device name shown in lists.
     */
    public function description(): string
    {
        return $this->description;
    }
    /**
     * Returns the host name or IP address used to reach the device.
     */
    public function hostname(): string
    {
        return $this->hostname;
    }
    /**
     * Returns the free-text notes for the device.
     */
    public function notes(): string
    {
        return $this->notes;
    }
    /**
     * Returns whether polling of the device is enabled.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }
    /**
     * Returns the free-text location (at most 40 characters).
     */
    public function location(): string
    {
        return $this->location;
    }
    /**
     * Returns the identifier that an outside inventory uses for the device, at most 40 characters.
     */
    public function externalId(): string
    {
        return $this->externalId;
    }
    /**
     * Returns the id of the site the device belongs to, where 0 means no site.
     */
    public function siteId(): int
    {
        return $this->siteId;
    }
    /**
     * Returns the polling fields merged over the defaults, with every value cast to a string.
     *
     * @return array Map from string to string.
     */
    public function polling(): array
    {
        return array_map(static fn($value): string => (string) $value, array_replace(DevicePolling::DEFAULTS, $this->polling));
    }
    /**
     * Returns the non-secret SNMP fields merged over the defaults, with every value cast to a string.
     * An empty authentication or privacy protocol is reported as "[None]". Credentials are never included.
     *
     * @return array Map from string to string.
     */
    public function snmp(): array
    {
        $fields = array_replace(DeviceSnmpConfiguration::PUBLIC_DEFAULTS, array_intersect_key($this->snmp, DeviceSnmpConfiguration::PUBLIC_DEFAULTS));
        foreach (['snmp_auth_protocol', 'snmp_priv_protocol'] as $key) {
            if ($fields[$key] === '' || $fields[$key] === null) {
                $fields[$key] = '[None]';
            }
        }
        return array_map(static fn($value): string => (string) $value, $fields);
    }
    /**
     * Returns the pending SNMP change from the last revise() call.
     * When none is pending, returns a change that keeps the stored credentials.
     */
    public function snmpChange(): DeviceSnmpChange
    {
        return $this->snmpChange ?? new DeviceSnmpChange(['keep_credentials' => true] + $this->snmp() + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
    }
    /**
     * Returns a SHA-256 token over the current values, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->hostname, $this->notes, $this->enabled, $this->location, $this->externalId, $this->siteId, $this->polling(), $this->snmp()], JSON_THROW_ON_ERROR));
    }
    /**
     * Applies an edit after checking that the caller saw the current revision and that every value is valid.
     * A null $siteId, $polling or $snmp leaves that part unchanged. Nothing is modified when a check fails.
     *
     * @param string $expectedRevision Token from revision() at the time the form was loaded.
     * @param array|null $polling Polling fields to replace; null keeps the current ones.
     * @param array|null $snmp SNMP fields, including credentials; null keeps the current ones.
     * @throws DeviceEditConflict When the device changed since $expectedRevision was issued.
     * @throws \InvalidArgumentException When a value is out of range, malformed or not valid UTF-8.
     */
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
