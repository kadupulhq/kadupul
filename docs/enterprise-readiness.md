# Enterprise readiness evidence

This records the affected requirement only; other enterprise requirements remain not assessed.

| Requirement and acceptance criteria | Subsystem | Status | Verification evidence | Known gaps and risks | Release gate |
| --- | --- | --- | --- | --- | --- |
| Stop legacy writer lock retries after 60 attempts, return failure, close the writer and preserve queued samples. | Boost collection | implemented | BoostDaemonHardeningTest exercises failed and successful bounded acquisition; BoostWorkerNativeTest exercises the shipped legacy worker failure path. | Real database contention and an installed RRDtool older than 1.5 are not verified by these fixtures. | Verify applicable checks and operational review before merging the combined main presentation PR. |

For RRDtool older than 1.5, each MySQL named-lock request waits at most one second; retries add a 50 ms backoff. Exhaustion logs an error and returns through the existing failed-worker path before deleting samples. Operators should investigate the competing writer and database connectivity before retrying collection. No schema or credential changes are required. Newer RRDtool versions keep their existing path.
