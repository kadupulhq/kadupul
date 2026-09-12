#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
cd "$root"

printf 'branch\tproof_status\n' > "$scratch/matrix.tsv"
if bash tests/security/verify_private_advisory_matrix.sh "$scratch/matrix.tsv" > "$scratch/log" 2>&1; then
	echo 'FAIL: header-only matrix accepted' >&2
	exit 1
fi
grep -q 'empty matrix' "$scratch/log"

printf 'main\tPROVEN_TEST_BACKED\n' >> "$scratch/matrix.tsv"
bash tests/security/verify_private_advisory_matrix.sh "$scratch/matrix.tsv"
printf 'main\tNO_EVIDENCE\n' >> "$scratch/matrix.tsv"
if bash tests/security/verify_private_advisory_matrix.sh "$scratch/matrix.tsv" > "$scratch/log" 2>&1; then
	echo 'FAIL: unresolved advisory accepted' >&2
	exit 1
fi
grep -q 'NO_EVIDENCE' "$scratch/log"

# A mock gh proves bad branch requests fail before accessing the network.
mkdir "$scratch/bin"
cat > "$scratch/bin/gh" <<'MOCK'
#!/usr/bin/env bash
echo 'FAIL: gh was called for an invalid branch request' >&2
exit 99
MOCK
chmod +x "$scratch/bin/gh"
if PATH="$scratch/bin:$PATH" bash tests/security/build_private_advisory_matrix.sh \
	kadupulhq/kadupul "HEAD refs/heads/nonexistent-review-test-$$" "$scratch/output" > "$scratch/log" 2>&1; then
	echo 'FAIL: missing requested branch accepted' >&2
	exit 1
fi
grep -q 'ERROR: requested branch not found:' "$scratch/log"
test ! -e "$scratch/output"
echo 'PASS: advisory matrix rejects empty evidence and missing branches'
