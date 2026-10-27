# Ten identity relationship views

Status: implemented. This slice depends on the relationship browsing
infrastructure. All paths below are under `app.php/relationships/`.

| Path | Owner | Legacy operation tab |
| --- | --- | --- |
| `user-group-memberships/{id}` | User | `permsgr` |
| `group-members/{id}` | Group | `members` |
| `user-graphs-permissions/{id}` | User | `permsg` |
| `user-devices-permissions/{id}` | User | `permsd` |
| `user-templates-permissions/{id}` | User | `permste` |
| `user-trees-permissions/{id}` | User | `permstr` |
| `group-graphs-permissions/{id}` | Group | `permsg` |
| `group-devices-permissions/{id}` | Group | `permsd` |
| `group-templates-permissions/{id}` | Group | `permste` |
| `group-trees-permissions/{id}` | Group | `permstr` |

The user and group catalogs link to these views and keep their existing edit
links. Every view requires console realm 8 and user administration realm 1,
using the existing current-account and enabled-group authorization checks.
Authorization precedes filter parsing and owner lookup. Parent names are read
from username or group name; account passwords, password history and recovery
metadata never enter the view model.

These are recorded memberships and resource exceptions, not an effective access
report. Permission types retain their existing storage meanings: graph 1,
tree 2, device 3, graph template 4. The displayed default policy comes from the
corresponding owner column: 1 allows by default and 2 denies by default. The
reader does not combine user/group policies or evaluate graph authorization
modes. A recorded exception can remain visible when a different authorization
mode is selected. Manage assignments, policies and active modes on the existing
operation pages.

User memberships retain disabled groups. Group members retain the recorded user
ID, username, authentication realm and enabled state. Missing referenced users,
groups and resources do not silently remove stored associations. Permission
rows show whether their resource exists. Graph titles are reduced to one value
per graph when duplicate metadata rows exist, preserving exception identity.

Readers perform one owner lookup and one bounded child query, using the shared
validated list filters. Search, pagination and sorting preserve parent identity.
All names and descriptions are escaped by Twig. English and French interface
catalogs cover the new text.

## Deployment and rollback

Deploy after the prerequisite relationship slice. No dependency or schema
change is needed. Deploy source/templates/translations together and rebuild the
normal Symfony cache/assets. Rollback restores the previous application revision
and rebuilds its cache/assets. There are no new writes or queued operations.
Existing permission and membership writers remain active throughout deployment.

## Verification and release limits

The kernel route suite covers all twenty relationship views, including the ten
added here. It exercises authentication/feature denial before parsing, request
validation, escaping, parent identity, HEAD/POST behavior, locale selection,
subdirectory links and exactly one catalog owner per view.

Native MariaDB tests load the shipped schema into disposable databases. They
exercise all ten new readers, every sort direction, owner/resource partitioning,
orphan preservation, duplicate graph metadata, default-policy changes, stable
pagination, credential omission and the two-query budget. Authorization tests
cover direct grants, enabled-group grants, disabled groups/accounts, locked
accounts, required password changes, missing console grants and anonymity.
Browser regressions cover all ten views across seven themes under the real
response CSP, with keyboard navigation and GET filter submission.

Installed-session and collector HTTP acceptance remain release checks. Fixture
admission does not establish production session behavior. This migration changes
presentation only; it does not retire legacy writers or globals, grant access,
or verify external device and poller behavior.
