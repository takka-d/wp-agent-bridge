# Result observation and bounded reads

A GitHub command commit acknowledges submission. WordPress then executes it and
publishes the matching result in an atomic bookkeeping commit. Execution,
publication, and the client's ability to retrieve that file are separate facts.

| Evidence | Meaning | Next action |
| --- | --- | --- |
| Matching result cannot yet be fetched | Pending or unknown, not confirmed execution failure | Keep the ID and payload; observe at most twice with time between observations |
| GitHub 401/403 | Repository access error | Stop unchanged retries; report the actual error |
| Full result fetch exceeds a client limit | Retrieval error | Read the bounded header, then only the required output |
| `outcome.state=succeeded` | Command operation succeeded | Inspect requested output; this is not proof that an entire user task or content audit is complete |
| `outcome.state=failed` | Command operation failed | Use its status and detailed error |
| `outcome.state=partial` / HTTP 207 | Batch output is incomplete | Inspect failed, omitted, and not-started indexes; retain successful results |

New result files include a small `outcome` before echoed input and output. A
GitHub line-range read of the first 80 lines can inspect it even when the full
result is large. That excerpt is not necessarily a complete JSON document.
Older files remain readable through `result.ok` and nested operation statuses.
No second status file or additional publication commit is required.

Allow at least five seconds after submission before the first observation,
using independent work where available. If the result cannot be retrieved,
allow at least ten further seconds before one final observation, respecting any
stricter user limit. These are observation intervals, not a completion SLA.
After two unavailable reads, report **pending or unknown** with timestamps and
the observed GitHub error. Never replay a mutation or infer a broken queue from
this alone. `duration_ms` excludes delivery, publication, and client waiting.

In a batch, `failed_count` remains the number of unsatisfied items for backward
compatibility. `execution_failed_count`, `result_omitted_count`, and
`not_started_count` explain why output is incomplete. These categories can
overlap: a failed operation can also produce an oversized response.
An omitted item retains its `execution_ok` and `execution_status`.

Theme range reads accept `start_line` plus `max_lines`, or an inclusive
`end_line`. When both bounds are supplied, the smaller wins. The maximum is
500 lines; the existing default is 200. `theme.file.read_many` forwards these
bounds for each item.

Theme search returns UTF-8 excerpts around the first match on each matching
line. It limits context lines too. Defaults are 1,024 bytes per excerpt and
65,536 bytes for the results array. Use `pattern` to constrain file paths.
`max_excerpt_bytes` can be increased up to 8,192; an accepted query is never
shortened out of its excerpt. `excerpts_truncated` identifies shortened text;
`matches_truncated` identifies incomplete match coverage, including skipped
files. Search excerpts locate data and must not be used as complete JSON or as
unguarded replacement content.
