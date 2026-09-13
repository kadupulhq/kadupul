#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
cd "$root"

header() {
	printf 'branch\tadvisory_key_hash\tstate\tseverity\tsummary\tcommit_count\ttest_hits\tchangelog_hits\tsecurity_hits\tcode_hits\tproof_status\n'
}

row() {
	printf '%s\t%s\tdraft\thigh\tsummary\t1\t1\t0\t0\t0\t%s\n' "$1" "$2" "$3"
}

rejects() {
	local file="$1" expect="$2" why="$3"
	if bash tests/security/verify_private_advisory_matrix.sh "$file" > "$scratch/log" 2>&1; then
		echo "FAIL: $why" >&2
		exit 1
	fi
	grep -q "$expect" "$scratch/log"
}

header > "$scratch/matrix.tsv"
rejects "$scratch/matrix.tsv" 'empty matrix' 'header-only matrix accepted'

row main a1b2c3d4e5f6 PROVEN_TEST_BACKED >> "$scratch/matrix.tsv"
bash tests/security/verify_private_advisory_matrix.sh "$scratch/matrix.tsv"
row main b2c3d4e5f6a1 NO_EVIDENCE >> "$scratch/matrix.tsv"
rejects "$scratch/matrix.tsv" 'NO_EVIDENCE' 'unresolved advisory accepted'

{ header; row main a1b2c3d4e5f6 UNKNOWN; } > "$scratch/unknown.tsv"
rejects "$scratch/unknown.tsv" 'unknown proof status' 'unknown proof status accepted'

{ header; row main a1b2c3d4e5f6 PROVEN_TEST_BACKED; printf 'garbage\n'; } > "$scratch/malformed.tsv"
rejects "$scratch/malformed.tsv" 'rows are malformed' 'malformed row accepted'

{ header; row main a1b2c3d4e5f6 PROVEN_TEST_BACKED; printf 'main\tPROVEN_TEST_BACKED\n'; } > "$scratch/truncated.tsv"
rejects "$scratch/truncated.tsv" 'rows are malformed' 'truncated row accepted'

{ header; printf 'main\ta1b2c3d4e5f6\tdraft\thigh\tsummary\tx\tx\tx\tx\tx\tPROVEN_TEST_BACKED\n'; } > "$scratch/counts.tsv"
rejects "$scratch/counts.tsv" 'rows are malformed' 'non-numeric evidence counts accepted'

{ header; printf 'main\ta1b2c3d4e5f6\t\thigh\tsummary\t1\t1\t0\t0\t0\tPROVEN_TEST_BACKED\n'; } > "$scratch/nostate.tsv"
rejects "$scratch/nostate.tsv" 'rows are malformed' 'row without a state accepted'

{ header; printf 'main\ta1b2c3d4e5f6\tdraft\t\tsummary\t1\t1\t0\t0\t0\tPROVEN_TEST_BACKED\n'; } > "$scratch/noseverity.tsv"
rejects "$scratch/noseverity.tsv" 'rows are malformed' 'row without a severity accepted'

{ header; row main '' PROVEN_TEST_BACKED; } > "$scratch/nokey.tsv"
rejects "$scratch/nokey.tsv" 'rows are malformed' 'row without an advisory key accepted'

# A mock gh proves bad branch requests fail before accessing the network.
mkdir "$scratch/bin"
cat > "$scratch/bin/gh" <<'MOCK'
#!/usr/bin/env bash
echo 'FAIL: gh was called for an invalid branch request' >&2
exit 99
MOCK
chmod +x "$scratch/bin/gh"
if PATH="$scratch/bin:$PATH" bash tests/security/build_private_advisory_matrix.sh \
	kadupulhq/kadupul "nonexistent-review-test-$$" "$scratch/output" > "$scratch/log" 2>&1; then
	echo 'FAIL: missing requested branch accepted' >&2
	exit 1
fi
grep -q 'ERROR: requested branch not found:' "$scratch/log"
test ! -e "$scratch/output"
if PATH="$scratch/bin:$PATH" bash tests/security/build_private_advisory_matrix.sh \
	kadupulhq/kadupul ' ' "$scratch/output" > "$scratch/log" 2>&1; then
	echo 'FAIL: blank branch list accepted' >&2
	exit 1
fi
grep -q 'ERROR: no branches requested.' "$scratch/log"
test ! -e "$scratch/output"

# HEAD resolves to a commit but is not a branch, so it cannot label proof.
if PATH="$scratch/bin:$PATH" bash tests/security/build_private_advisory_matrix.sh \
	kadupulhq/kadupul HEAD "$scratch/output" > "$scratch/log" 2>&1; then
	echo 'FAIL: HEAD accepted as a branch' >&2
	exit 1
fi
grep -q 'ERROR: requested branch not found: HEAD' "$scratch/log"

echo 'PASS: advisory matrix rejects empty evidence and missing branches'
