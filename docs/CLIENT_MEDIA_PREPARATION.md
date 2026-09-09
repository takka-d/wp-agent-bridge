# Existing-image transfer through the GitHub Direct Runtime

Text editing and media registration use the same connected GitHub text/JSON
write tools. A GitHub tool does not need a local-file argument: image bytes are
encoded inside the existing command/payload format. The additional requirement
for an image task is access to the **original file bytes**, plus a way to pass
the generated strings into GitHub tool arguments without transcription.

`plugin/wp-agent-bridge/client/prepare-media.py.txt` implements that preparation.
It is packaged as inert text so existing self-update extension guards accept it.
The installed plugin synchronizes the tested script to
`wordpress-bridge/prepare-media.py` in the canonical runtime, alongside the
existing guidance. This is a client-side helper, not a new ChatGPT plugin,
WordPress endpoint, or GitHub Actions worker. It requires Python 3's standard
library in an execution tool already available to the chat. No package install,
new credentials, or network access is required by the helper itself.

## Use in the original image task

1. Use the already connected GitHub tools and verify the target's
   `wordpress-bridge/RUNTIME_CONNECTION.json`. Save that response in the current
   execution environment. Fetch the runtime's `wordpress-bridge/prepare-media.py`
   and use that exact code rather than recreating an encoder.
2. Obtain the approved image through an available conversation/file tool. An
   image ID, preview, or a path from another environment is not proof that its
   original bytes are readable. Do not regenerate, resize, recompress, or replace
   the image merely to make the transfer easier.
3. In that execution environment, call `prepare_file(source, filename,
   request_id, connection, metadata)` or run:

   ```sh
   python3 prepare-media.py /path/to/original.png \
     --connection /path/to/RUNTIME_CONNECTION.json \
     --request-id media-task-unique-id --output /path/to/new-prepared-directory
   ```

   A successful run writes `manifest.json`, `command.json`, and `git-tree.json`.
   Standard output contains only the preparation summary, not Base64 data.
   It does not upload or edit an article. Source bytes are preserved exactly.
4. Read generated JSON within an available tool orchestration environment and
   pass its strings directly to existing GitHub tools. Never ask the language
   model to reproduce long Base64 from displayed output. For Git Data, use the
   entries from `git-tree.json` with the latest runtime `base_tree`, then create
   a commit with that head as parent and update the branch without force. When
   only `create_file` is available, create the payload entries first and the
   pending command entry last. Use the repository/branch from the verified
   marker, not a repository inferred from the image or article title.
   The manifest also contains the expected Git blob IDs. Compare them with
   GitHub's returned IDs (or the created tree entries) to detect truncated tool
   payloads. For Git Data, do this before publishing the ref. For individual
   file writes, verify each staged payload before creating the pending command.
5. Check the matching result path. On an uncertain write, recover that same ID
   before retrying. A prepared package is not proof of upload. After an actual
   successful media result, use its attachment ID in the requested guarded
   article patch and verify the displayed result separately.

## What this fixes and what it cannot establish

The preparer removes manual selection of the inline/staged threshold, manual
Base64 splitting, mismatched size/hash metadata, and hand-written chunk field
names. It uses one command for media up to 1 MiB. Larger files use at most 32
independently encoded binary chunks, including files up to the 6 MiB limit.

`asset_unavailable` means the original could not be opened in the current
execution environment. It does **not** mean GitHub or WordPress is unavailable.
If no available tool can read that original, or the environment cannot carry
the generated strings into the GitHub tool call, report that specific stage.
The helper does not supply tools to ChatGPT and cannot make another session's
private files readable. Whether the failing conversation can perform those
steps must be checked in that conversation, within the user's authorization.

## Verification

`python3 tests/media-client-preparation-test.py` executes this same implementation
and independently reconstructs its output. It checks actual file reading,
unchanged PNG bytes, SHA/size, exact threshold boundaries, the 6 MiB/32-chunk
boundary, stable retry payloads, missing-asset classification, and refusal to
overwrite existing output. These checks prove preparation behavior, not a
successful ChatGPT upload or rendered article.
