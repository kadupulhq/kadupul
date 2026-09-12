#!/usr/bin/env bash
set -euo pipefail

MATRIX_FILE="${1:-/tmp/private_advisory_proof_matrix.tsv}"
ALLOW_PARTIAL="${ALLOW_PARTIAL:-0}"

if [ ! -f "$MATRIX_FILE" ]; then
	echo "ERROR: matrix file not found: $MATRIX_FILE" >&2
	exit 1
fi

# NR>1 alone counted blank and malformed lines as evidence rows, so a header
# followed by one blank line reported total=1 with nothing unresolved and strict
# closure succeeded having proved nothing. A row must carry a status field.
row='NR>1 && NF>1 && $1 != "" && $NF != ""'
total="$(awk -F'\t' "${row} {n++} END {print n+0}" "$MATRIX_FILE")"
no_evidence="$(awk -F'\t' "${row} && \$NF==\"NO_EVIDENCE\" {n++} END {print n+0}" "$MATRIX_FILE")"
partial="$(awk -F'\t' "${row} && \$NF==\"PARTIAL_REFERENCE\" {n++} END {print n+0}" "$MATRIX_FILE")"

echo "matrix_total=${total}"
echo "matrix_no_evidence=${no_evidence}"
echo "matrix_partial=${partial}"

if [ "$total" -eq 0 ]; then
	echo "ERROR: empty matrix does not establish advisory closure." >&2
	exit 1
fi

if [ "$no_evidence" -gt 0 ]; then
	echo "ERROR: unresolved advisories with NO_EVIDENCE." >&2
	exit 1
fi

if [ "$ALLOW_PARTIAL" != "1" ] && [ "$partial" -gt 0 ]; then
	echo "ERROR: unresolved advisories with PARTIAL_REFERENCE." >&2
	exit 1
fi

echo "OK: matrix closure criteria satisfied."
