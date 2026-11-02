# Installer usability acceptance

Use a disposable installation with synthetic data, no real devices and discovery
disabled. Never ask participants to supply production credentials. This protocol
records first-time operator evidence separately from automated browser tests.

Recruit operators who have not used this installer. Include keyboard-only use
and at least one supported translated interface. Give participants the deployment
prerequisites, an eligible test account and a task, without pointing out controls.

| Task | Acceptance criteria |
| --- | --- |
| Diagnose a blocked prerequisite | Identify the failing check and its remedy without starting installation. |
| Configure a local installation | Select a profile, retain discovery disabled and reach the reviewed plan without opening unrelated advanced groups. |
| Explain the plan | Identify the version change, selected templates, discovery scope and backup requirements before confirming. |
| Recover from an injected failure | Identify the last recorded stage, preserve diagnostics and explain why a live worker must not be retried. |
| Verify completion | Distinguish recorded installation completion from the operational checks still requiring evidence. |
| Request support | Download diagnostics and identify which requirement numbers failed without exporting secrets. |

Record completion, assistance needed, wrong turns, inaccessible controls and the
participant's explanation of the next action. Record application revision,
runtime, locale, browser and input method. Do not record passwords, session
tokens, configuration files or identifying infrastructure details. Store only
consented, sanitized observations.

Treat an accidental execution, an unreachable keyboard control, misunderstood
partial failure or undisclosed secret as a release blocker. Fix the observed
cause and repeat the affected task with a new participant. Timing alone is not
an acceptance criterion: preserve explicit review and execution consent.

## Evidence status

Automated DOM and native-browser assertions cover selected controls, semantics,
explicit consent and locale wiring. They do not establish first-time usability
or screen-reader compatibility. Human sessions and assistive-technology testing
must be recorded before those capabilities are marked verified.

## Record and assess observations

Create a pending report for the exact tested commit using the existing Python
3.12 runtime. Replace `COMMIT_SHA` with its full forty-character Git hash:

```sh
python tools/installer_usability.py --template --revision COMMIT_SHA > /protected/installer-observations.json
python tools/installer_usability.py --report /protected/installer-observations.json
```

The template has no participant sessions and cannot pass acceptance. Record the
observed `environment.php` patch version and `environment.browser` (`chromium`,
`firefox`, `safari` or `edge`). Add consented sessions with these fields:

| Field | Meaning |
| --- | --- |
| `id` | Random anonymous 32-character lowercase hexadecimal identifier, unique per session. Never a name or account identifier. |
| `consent`, `first_time`, `human` | Boolean declarations recorded only after the facilitator confirms each condition. Synthetic browser tests do not qualify. |
| `locale` | Actual selected `en`, `en-US`, `fr` or `fr-FR` interface. |
| `input_method` | `keyboard`, `pointer` or `assistive`. |
| `tasks` | All six task keys: `prerequisites`, `configure`, `review`, `recover`, `verify`, `support`. |

Each task contains `outcome` (`unassisted`, `assisted`, `blocked` or `not_run`) and
an `issues` list. Allowed codes are `unintended_execution`, `inaccessible_control`,
`misunderstood_partial_failure`, `secret_disclosure` and `confusing_guidance`.
Do not put private notes, participant names, hostnames, recordings or credentials
in the report. Keep separately consented qualitative observations in the approved
private research store. Reports are capped at 64 KiB and twenty sessions.

The assessor requires at least two distinct sessions, keyboard use, a French
interface and every task observed. It reports blockers and incomplete evidence
without printing participant identifiers. Exit `0` means declarations are
complete and await independent review; `1` means more sessions or review are
needed, or a blocker exists; `2` means the report is invalid. Every summary keeps
`release_accepted: false`: a local validator cannot authenticate participants or
substitute for independent review. No human sessions are supplied by the test
fixtures or this implementation.
