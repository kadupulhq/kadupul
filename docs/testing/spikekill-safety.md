# Spike-removal filesystem safety

Run spike removal as the account that owns the RRD and backup directories.
The working directories must belong to that account or root and must not be
group- or world-writable. Their ancestors must also prevent other accounts
from replacing them; a sticky temporary root is allowed when the child belongs
to the running account or root. Root must not process files through directories
owned by an unprivileged account. Prefer the poller account over root.

These checks are necessary because a pathname identity check is not an atomic
filesystem operation. Exclusive creation prevents replacement of an existing
file, but does not make an attacker-controlled parent safe. Directory identities
are also checked before and after creation. If POSIX account identity is not
available, creation fails closed; Windows ACL validation is not implemented.

Restore writes an exclusive sibling first. A failed, empty or partial restore
leaves the live RRD unchanged. On success the original owner, group and mode are
preserved, and the validated sibling replaces the live directory entry with
rename. Rename replaces a swapped symlink itself rather than following its target.
Requested recovery snapshots remain available after success or failure.
