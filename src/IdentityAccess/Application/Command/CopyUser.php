<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\UserCopier;

/**
 * Copies a local account, with its permissions and settings, to a new username, as copy_user.php did.
 * Refuses a missing template and an existing new name; a dry run reads the same rows and writes nothing.
 */
final readonly class CopyUser
{
    private const string ACTION = 'user.copy';

    public function __construct(private UserCommandAccess $access, private UserCopier $copier) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false reads the same rows and changes none
     * @throws UserManagementDenied
     */
    public function __invoke(string $template, string $new, bool $local, ?string $operator, bool $apply): UserCopyReport
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, $apply);
        if (!$this->copier->localUserExists($scope->database, $template)) {
            return new UserCopyReport(UserCopyOutcome::TemplateMissing, $scope->database, !$apply);
        }
        // user_copy() refuses an existing name without overwrite, so no copy is attempted.
        if ($this->copier->localUserExists($scope->database, $new)) {
            return new UserCopyReport(UserCopyOutcome::NewUserExists, $scope->database, !$apply);
        }
        if (!$apply) {
            return new UserCopyReport(UserCopyOutcome::Planned, $scope->database, true);
        }
        $correlation = $this->access->correlation();
        $copied = $this->copier->copy($scope->database, $template, $new)
            && $this->copier->localUserExists($scope->database, $new);
        // The new name is operator input, so the audit id carries its hash.
        $this->access->change($correlation, $scope, self::ACTION, 'user-sha256', hash('sha256', $new), $copied);

        return new UserCopyReport($copied ? UserCopyOutcome::Copied : UserCopyOutcome::NotCopied, $scope->database, false);
    }
}
