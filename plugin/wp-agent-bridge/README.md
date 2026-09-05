# WP Agent Bridge 1.1.5

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

## 主な機能

- 投稿・固定ページ等のWordPress REST API操作
- 本文・table・post meta・option・taxonomy・menu等のguarded editing
- plugin / theme管理
- theme file編集
- Draft Themeのpreview / publish / rollback
- media upload
  - 最大6 MiB decoded
  - **ChatGPTローカル／会話添付／sandboxファイルは `/wp-agent-bridge-media/v1/upload-chunk` を優先**
  - ローカルbinaryを順序付きchunkへ分割し、各chunkのbytes / SHA-256と全体bytes / SHA-256を検証
  - GitHub connectorが既に扱えるmediaでは`wordpress-bridge/media/pending/*.b64`経路も利用可能
  - staged mediaは元binaryを先に分割し、各chunkを独立してBase64化
  - Git Data APIを利用できる場合、payload群とupload commandを1つのtree/commit/ref更新としてruntime branchへ投入
  - 成功後の一時payloadは1つのGit tree cleanup commitでまとめて削除
  - cleanup時にbranchが競合した場合は最新HEADからbounded retry
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

1.1.5では生成される`AGENTS.md`もsource-aware media routingを明示します。ChatGPT内にしか存在しないローカルファイルをGitHubの`.b64` stagingへ無理に流そうとせず、既存のauthenticated chunk uploadを選択します。

## License

無料でダウンロード・インストール・利用できます。個人的・非公開の改変は可能です。

元のソフトウェア、改変版、fork、build等を第三者へ再配布、販売、ミラー配布、第三者向けダウンロードとして提供することは禁止します。詳細は`LICENSE.md`を参照してください。

このライセンスは独自のsource-available licenseであり、オープンソースライセンスではありません。
