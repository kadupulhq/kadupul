# Contracts

Approvals for deliberate behavioral changes between a baseline and a candidate.

`tests/bin/compare` classifies a differing scenario as `INTENTIONAL_CHANGE`
only when an entry here names the scenario, carries the digest of that exact
baseline-candidate pair, and gives a reason. The digest comes from
`comparison.json`. If either side changes afterwards the digest no longer
matches and the scenario reverts to `REGRESSION`, so an approval cannot be
reused to wave through a later change.

```json
{
  "auth/login-invalid": {
    "digest": "<digest from comparison.json>",
    "reason": "Login error text reworded during the auth rewrite; status unchanged."
  }
}
```

```sh
make compare BASELINE=cacti-1.2.31 CANDIDATE=kadupul APPROVALS=tests/Contracts/approvals.json
```

An approvals file is written when a rewrite intentionally diverges. There is
none yet, because nothing has diverged.
