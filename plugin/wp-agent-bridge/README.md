# WP Agent Bridge

ChatGPTからWordPressを更新するためのWordPressプラグインです。

通常のWordPress操作は、**利用者自身が所有するprivate GitHub runtime repository**と、**そのWordPress専用のsite-specific private GitHub App**を使って実行します。

通常経路:

`ChatGPT → user-owned private runtime repository → site-specific GitHub App signed Webhook → WordPress → same runtime repository → ChatGPT`

通常処理に運営者所有のruntime repository / relay server、GitHub Actions worker、旧Bridge Key、`takka-d/chatgpt-data`、WPVibeは使用しません。

## 導入

1. ZIPをWordPressへインストールして有効化する。
2. **ツール > WP Agent Bridge** を開く。
3. 画面の案内から、自分のGitHubアカウントに専用private runtime repositoryを作成する。
4. **Connect GitHub** を押し、GitHub App manifestからこのWordPress専用のprivate GitHub Appを作成する。
5. GitHub Appのインストール時に **Only select repositories** を選び、専用runtime repositoryだけを選択する。
6. WP Agent Bridgeが`wp-agent-bridge-runtime` branch、canonical marker、command/result/mediaディレクトリを初期化する。
7. 同じGitHubアカウントをChatGPTへ接続し、そのruntime repositoryを利用できることを確認する。
8. ChatGPTにWordPress操作を依頼する。

PAT、private key、Webhook secret、Bridge Key、GitHub Actions workflowを利用者が手入力することは想定していません。GitHub Appのprivate keyとWebhook secretは接続先WordPress内へ暗号化保存します。

## 操作の入口

接続先の `wordpress-bridge/RUNTIME_CAPABILITIES.json` を1回確認し、`type=operation` と `operation` / `params` を使います。`post.find` で候補を検索し、ユーザー指定の `target_url` または完全一致かつ一意の `target_title` で対象を照合します。`post.create` は下書きが標準で、`post_type=page` なら固定ページを作成します。`post.get` / `post.update` は対象種別から経路を決めます。

## 主な機能

- 投稿・固定ページ等のWordPress REST API操作
- 本文・table・post meta・option・taxonomy・menu等のguarded editing
- plugin / theme管理
- theme file編集
  - exact UTF-8 asset writeでは`expected_content_sha256`と`expected_current_sha256`による完全性・stale-write guardを利用可能
- `theme.files.list` / `theme.files.search` / `theme.file.read.many`による複数theme fileのbounded inspection
- Draft Themeのpreview / publish / rollback
- media upload
  - 最大6 MiB decoded
  - ChatGPT-local / conversation / sandbox / connector-downloaded fileは、1 MiB以下なら `media.upload.inline` を1回使用。1 MiBを超え6 MiB以下の場合は、`wordpress-bridge/media/pending/*.b64` + `/wp-agent-bridge-runtime/v1/media-upload`のbatched staged-media pathを使用
  - GitHub local-file parameterは不要。元binaryを先に分割し、各chunkを独立Base64化してtext payloadとしてstageする
  - `data_paths`、whole-file bytes / SHA-256、必要に応じて`chunk_integrity`を持つupload commandは1件だけ作る
  - 通常成功経路では`media-verify`を先に実行しない。upload自身がattachment作成前にwhole-file / chunk integrityを検証する
  - fast pathは現在のGit tree snapshotから全`data_paths`を一括解決し、対応するGit blobを直接読む
  - `data_blob_shas`は、staging時にblob SHAが既に分かる場合だけpath-to-blob pinとして追加できる
  - Git Data APIを利用できる場合、payload群とupload commandを1つのtree/commit/ref更新としてruntime branchへ投入
  - `create_file`しか使えない場合もpayload群を先にstageし、最後にsingle upload commandを作る
  - `/wp-agent-bridge-runtime/v1/media-verify`は明示的なverify-only、staging不確実時、またはintegrity 409後の診断/復旧用
  - 409時はchunk diagnosticsから不一致payloadだけを交換できる
  - `/wp-agent-bridge-media/v1/upload-chunk`はbatched stagingが使えない、または実際に失敗した場合のsequential fallback
  - 成功後のstaged payloadはbounded cleanupで削除
- Site Icon / favicon
  - `site.icon.get`
  - `site.icon.set` (`confirm=true`、`expected_current_id`対応)
  - `site.icon.clear` (`confirm=true`、`expected_current_id`対応)
  - `media.upload.capabilities`
  - upload時に`set_site_icon=true` + `confirm_site_icon=true`を付けて、Media Library登録とSite Icon設定を1コマンドで実行可能
- Google Drive等からChatGPT側で取得した画像もlocal binaryとして同じサイズ基準でinlineまたはstaged media pathへ流せる。WordPress側のGoogle credentialは不要
- request-ID completed-response idempotency
  - 同一request_id + 同一payloadは元のresponseを再生
  - 同一request_id + 異なるpayloadは409で拒否
- authenticated runtime pushのprimary executionを直列化
  - recovery pushが実行中commandへ重なり、temporaryな`idempotency_in_progress`をterminal resultとして確定する競合を防止
- missed Webhook / GitHub bookkeeping競合後のpending reconciliation
- self-update full-manifest / SHA-256 / PHP parse / backup / rollback guards
- WP-Cron管理

変更操作には、操作内容に応じてpreview、confirm、SHA-256、plan hash、impact hash、stale-write rejection、active theme/plugin protection等を適用します。

任意shell command、任意WP-CLI文字列、無制限SQL writeは公開しません。

## Runtime identity

正常に初期化されたruntime repositoryでは、少なくとも以下を確認できます。

- `AGENTS.md`
- `wordpress-bridge/RUNTIME_CONNECTION.json`
- `wordpress-bridge/WEBHOOK_RUNTIME.md`
- `wordpress-bridge/commands/pending/`
- `wordpress-bridge/commands/completed/`
- `wordpress-bridge/results/`
- `wordpress-bridge/media/pending/`

`RUNTIME_CONNECTION.json`は`status: canonical`、`transport: direct-github-webhook`、`ownership: user-owned`、`operator_relay: false`を示します。

現行の生成`AGENTS.md`は、次も明示します。

- 同一task/sessionでcanonical runtime確認済みなら、具体的なmigration signalがない限りruntime探索を繰り返さないこと
- runtime解決が必要なら`wordpress-bridge/RUNTIME_CONNECTION.json`を最初に読み、retired runtimeでは調査を即終了して`replaced_by`を追うこと
- GitHub connectorのwrite actionを確認してからblocker判定すること
- ChatGPT-local / connector-downloaded mediaは1 MiB以下なら `media.upload.inline`、それを超える場合だけstaged-mediaを使うこと
- 正常時はverify-firstをせず、Git tree一括解決 + Git blob direct readのfast pathを使うこと
- sequential chunk routeはfallbackであること
- dedicated Site Icon surfaceを使うこと
- `theme.files.list` / `theme.files.search` / `theme.file.read.many`でmulti-file inspectionを1commandへまとめられること

## License

無料でダウンロード・インストール・利用できます。個人的・非公開の改変は可能です。

元のソフトウェア、改変版、fork、build等を第三者へ再配布、販売、ミラー配布、第三者向けダウンロードとして提供することは禁止します。詳細は`LICENSE.md`を参照してください。

このライセンスは独自のsource-available licenseであり、オープンソースライセンスではありません。
