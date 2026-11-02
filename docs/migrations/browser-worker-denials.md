# Native browser-worker realm denials

Feature workers whose Symfony parent renders access errors may add a final
`'realm_denial' => true` entry to their trusted `$consoleHandoff` descriptor,
after `input_limit` when present. The boolean defaults to false and is never
accepted from command input. Existing console and menu descriptors stay unchanged.

Only the native core realm-denied branch opts into this result. It still invokes
`custom_denied` first and honors `OPER_MODE_RESKIN` or a plugin-owned exit. After a
normal hook return, a private CLI marker replaces the core HTML renderer. Web
requests retain the original renderer, hook order and headers.

The handoff catches that marker only during bootstrap and only for the opted-in
worker. It verifies that the actual native session is active, that its actor and
credential match the private command, and that native session close succeeds.
The close helper treats native handler write warnings as failure, delegates unrelated errors to the prior handler and restores it. It discards buffered bootstrap output before returning the fixed actor-bound
`outcome: denied` envelope. A mismatch or unconfirmed session close remains a
fixed failed transport result; no actor is authenticated by the marker alone.

A reskin or hook-owned exit retains its original output and session behavior.
The private transport refuses that non-JSON result; it does not infer denial from
HTML or synthesize plugin HTTP behavior. Features requiring such behavior use the
actual web compatibility path. Installation redirects, password-change flows,
login/cookie/Basic-auth transitions and session-eligibility exits also retain
their existing bootstrap behavior and are not converted into typed realm results.

There is no schema, dependency or runtime change. Deploy the helper classes,
`include/auth.php` and handoff together before opting a feature worker in. Revert
feature opt-ins before reverting this integration. Installed acceptance covers
both native session backends and real plugin dispatch; complete feature HTTP and
browser acceptance remains with each migration.
