# Agent instructions

## GitHub review feedback

Always address pull-request feedback using GitHub's native review workflow,
including feedback from people, Copilot, Sonar, and other automated checks.

- Read the current review threads, review summaries, and check results before
  making changes. Investigate findings rather than accepting or dismissing them
  automatically.
- For valid findings, add focused regression coverage where practical, implement
  the fix, run relevant checks, and push the fix to the PR branch.
- Reply in the original review thread with the fixing commit and verification
  evidence. For feedback without a thread, comment on the PR and link the finding
  or failed check. A chat summary is not a substitute for a GitHub response.
- Resolve a review thread only after its fix is pushed and verified, or after
  providing concrete evidence that the finding is stale or inapplicable. Leave
  uncertain findings open and ask a focused question in the thread. Never
  blanket-resolve threads or dismiss scanner alerts merely to obtain green checks.
- Let new scans confirm scanner fixes. If a false-positive dismissal is warranted,
  record the specific rationale in the scanner's native review mechanism when
  available; do not treat resolving a PR conversation as resolving a scan alert.
- Request re-review through GitHub's reviewer controls/API after addressing the
  feedback. Recheck new reviews and CI on the latest commit and iterate as needed.
- Merge only when authorized, required approvals are satisfied, and applicable CI
  and quality gates pass on the latest head. Do not bypass protections or
  self-approve. Report pending reviews, failed checks, and remaining findings
  honestly.
