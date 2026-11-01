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
