# Retained controller inputs

`controller-inputs.zip` contains the 19 exact source inputs from clean controller
revision `bfd33d4962a9947587e4c449ae73b911e26a124a`. Each archive member was read
with `git show <revision>:<path>` and checked against the SHA-256 recorded in
`first.json`; `repeat.json` records the same inputs. ZIP member timestamps are
fixed to 2026-01-01 for reproducibility. No observation or recorded hash changed.

The historical selftest checks all archived bytes against both manifests. Current
captures still hash the live controller and build files; the comparison command
rejects a historical/current controller mismatch. This archive does not establish
behavioral parity for a newer controller.

The upgrade rehearsal uses the verified historical Dockerfile and its matching
Docker ignore rules to build the old vendored release. The candidate uses the
current Composer/npm Dockerfile. Both selected historical build-file hashes are
recorded in rehearsal evidence; no candidate dependencies are overlaid onto the
old application. Runtime fixtures remain candidate-owned as before.
