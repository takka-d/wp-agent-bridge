# WP Agent Bridge

ChatGPTからWordPressの記事・ページ・設定などを更新するためのWordPressプラグインです。

通常のWordPress操作は、**利用者自身が所有するprivate GitHub runtime repository**と、**そのWordPress専用のprivate GitHub App**を使って実行します。WP Agent Bridge運営者のruntime repository、relay server、GitHub Actions workerを通常経路として使用しません。

## 現在の配布状態

- Version: `1.1.5`
- Status: **release candidate / validation in progress**
- 1.1.5はChatGPT-local／会話添付ファイルの転送経路をsource-awareにした更新です。
- 1.1.5の外部テスト用prereleaseと再現可能なZIP SHA-256は、merged-main packaging完了後に記録します。
- 既存の`v1.1.4-rc1`は1.1.4時点のテスト成果物として保持し、上書きしません。
- broader public / stable releaseはまだ宣言していません。

## 構成

```mermaid
flowchart LR
    C["ChatGPT"]

    subgraph GH["利用者のGitHub"]
        R["private runtime repository"]
        A["site-specific private GitHub App"]
    end

    W["利用者のWordPress<br/>WP Agent Bridge"]

    C -->|"command / media payload"| R
    R -->|"push event"| A
    A -->|"signed Webhook"| W
    W -->|"GitHub App installation API<br/>result / completed / cleanup"| R
    R -->|"result"| C

    A -. "runtime repositoryだけにinstall" .-> R
```

通常のデータ経路は次のとおりです。

`ChatGPT → 利用者のprivate runtime repository → site-specific GitHub App signed Webhook → 利用者のWordPress → 利用者のruntime repository → ChatGPT`

GitHub Appは利用者自身のGitHubアカウントに作成し、対象のprivate runtime repositoryだけへinstallします。WordPressは、そのAppのinstallation tokenを使って同じrepositoryへresult / completed / media cleanupを書き戻します。

### 運営者側を経由しないもの

| データ / 資格情報 | 保存・処理される場所 | WP Agent Bridge運営者側へ送信 |
| --- | --- | --- |
| command / result | 利用者のprivate runtime repository | しない |
| media一時payload | 利用者のprivate runtime repositoryまたは利用者のWordPress一時領域 | しない |
| 記事本文・WordPress設定 | 利用者のWordPress、および依頼内容に必要な範囲のruntime command/result | しない |
| GitHub App private key / Webhook secret | 利用者のWordPress内で暗号化保存 | しない |

## 設計上の前提

- ダウンロード・インストール・利用は無料。
- 通常運用で運営者所有のGitHub Organization、private repository、WordPress、relay serverの資源を使用しない。
- runtime command/result、記事本文、WordPress設定等を運営者側へ送信・保存しない。
- GitHub Appのprivate keyとWebhook secretは、接続先WordPress内へAES-256-GCMで暗号化して保存する。
- 通常のWordPress操作にGitHub Actions、旧Bridge Key、`takka-d/chatgpt-data`、WPVibeを使用しない。

## 導入

```mermaid
flowchart TD
    I["1. WP Agent BridgeをWordPressへinstall"] --> T["2. Tools > WP Agent Bridge"]
    T --> R["3. 自分のGitHubにprivate runtime repositoryを作成"]
    R --> C["4. Connect GitHub"]
    C --> M["5. GitHub App Manifestからsite-specific private Appを作成"]
    M --> S["6. Only select repositoriesでruntime repo 1個だけ選択"]
    S --> B["7. runtime branch / marker / queueを自動初期化"]
    B --> G["8. ChatGPTから同じGitHub repoへアクセス"]
```

1. WP Agent BridgeのZIPをWordPressへアップロードし、有効化する。
2. **ツール > WP Agent Bridge** を開く。
3. 画面の案内から、利用者自身のGitHubアカウントに専用private runtime repositoryを作成する。
4. **Connect GitHub** を押す。GitHub App manifestにより、このWordPress専用のprivate GitHub Appを利用者自身のGitHub側に作成する。
5. GitHub Appのインストール時に **Only select repositories** を選び、手順3のruntime repositoryだけを選択する。
6. WP Agent Bridgeが`wp-agent-bridge-runtime` branch、runtime marker、command/result/media用ディレクトリを初期化する。
7. ChatGPTのGitHub接続から、その利用者自身のruntime repositoryを利用できることを確認する。
8. ChatGPTにWordPress操作を依頼する。

PAT、Webhook secret、private key、Bridge Key、GitHub Actions workflowを利用者が手入力することは想定していません。

## Runtime identity

ChatGPTはWordPress操作前に、runtime repositoryの`wordpress-bridge/RUNTIME_CONNECTION.json`を確認できます。正常な自己完結runtimeでは少なくとも次を示します。

```json
{
  "status": "canonical",
  "transport": "direct-github-webhook",
  "ownership": "user-owned",
  "operator_relay": false,
  "runtime_branch": "wp-agent-bridge-runtime"
}
```

repository名と`site_host`も、実際の接続先と一致している必要があります。

## 主な用途

- 投稿・固定ページの取得、作成、更新
- 本文の検索・部分編集・一括編集
- HTML tableの行・セル編集
- post meta、taxonomy、menu等の管理
- plugin / themeの管理
- theme fileの編集
- Draft Themeのpreview / publish / rollback
- media upload
- WP-Cron管理
- WordPress REST APIを利用した各種操作

変更操作には、操作内容に応じてpreview、confirm、SHA-256、plan hash、impact hash、stale-write rejection、active theme/plugin protection等のguardを適用します。

任意のshell command、任意のWP-CLI文字列、無制限のSQL writeは公開しません。

## 画像・ファイル転送

WordPress側のmedia上限は6 MiBです。転送経路は**ファイルのsourceに応じて選びます**。

### ChatGPTローカル／会話添付／sandboxファイル

GitHub connectorがローカルファイル参照を直接repositoryへ渡せない場合、`media/pending/*.b64`へ無理にコピーしようとせず、既存のauthenticated chunk routeを優先します。

`/wp-agent-bridge-media/v1/upload-chunk`

1. 元binary全体のbytes / SHA-256を計算する。
2. binaryを順序付きchunkへ分割する。Bridge上限は1chunk 1,200,000 decoded bytes、32 chunks、全体6 MiB。
3. 各chunkの`chunk_bytes` / `chunk_sha256`を計算し、そのchunkだけをBase64化する。
4. `upload_id`、chunk index/count、filename、全体integrity、chunk integrity、`data_b64`をnormal runtime REST commandとして順次送る。
5. 各chunkのcommand完了を確認してから次へ進む。
6. 最終chunkでWordPressが全体を再構成・検証し、Media Libraryへ登録してWordPress側の一時ファイルをcleanupする。

この経路はGitHubの`wordpress-bridge/media/pending/`を使わないため、ChatGPT内にしかないローカル画像をGitHubファイルAPIへ転写するための余分なBase64 stagingを避けられます。

### GitHub connectorが既に扱えるmedia

remote／既存staged sourceなど、GitHub connectorがlarge local fileをモデル経由で再serializeせず扱える場合は従来の`wordpress-bridge/media/pending/*.b64`経路も利用できます。

1. 元binary全体のbytes / SHA-256を計算する。
2. 元binaryを先に分割し、各binary chunkを独立してBase64化する。
3. staged payloadを検証する。
4. Git Data操作が利用できる場合はpayload群とupload commandを1つのtree / commit / ref更新で公開する。
5. `/wp-agent-bridge-runtime/v1/media-upload`がordered payloadを再構成し、bytes / SHA-256を検証する。
6. 成功後は一時payloadを1つのGit tree cleanup commitでまとめて削除し、branch競合時はbounded retryする。

## 配送失敗からの復旧

GitHub pushはdurable queueではないため、WebhookやGitHub書き戻しを一度取りこぼす可能性があります。Direct Runtimeはvalidなpushごとに現在の`commands/pending/`も再走査します。

1.1.4以降では、authenticated runtime pushをprimary executorへ渡す前にWordPress側で直列化します。これにより、別pushのrecovery scanがまだ実行中の同一commandへ重なり、request-id idempotencyが返したtemporaryな`idempotency_in_progress`をGitHub result/completedへterminal resultとして確定する競合を防ぎます。

同一`request_id`はWordPress側でcompleted responseを再利用するため、`WordPress実行成功 → GitHub result書き戻し失敗`が起きても、後続pushで副作用を二重実行せずbookkeepingを復旧する設計です。

## セキュリティ

外部からWordPressへ入るDirect RuntimeのWebhookは、site-specific GitHub AppのWebhook secretによるSHA-256 HMAC署名を検証します。さらに、push元が設定済みのprivate repository、installation ID、repository ID、`wp-agent-bridge-runtime` branchと一致する場合だけ処理します。

WordPress内部ではBridge coreのguarded REST surfaceをローカル実行し、request ID、allowlist、preview / confirm、stale-write protection等を適用します。詳細は[`SECURITY.md`](SECURITY.md)を参照してください。

## 更新安全性

Bridge self-updateは完全manifestを要求します。manifestに含まれない既存ファイルを暗黙削除とは扱いません。削除する場合は`delete_paths`と明示確認が必要で、bootstrapのPHP依存関係とPHP構文も置換前に検証します。

## 必要環境

- WordPress 6.9以上
- PHP 7.4以上
- HTTPSで公開され、WordPress REST APIへGitHub Webhookから到達可能なサイト
- OpenSSL(AES-256-GCM / RSA signing)
- GitHubアカウント
- GitHubを接続できるChatGPT環境

## 配布

公開ZIPにはWP Agent Bridge本体だけを含めます。旧central/operator Onboarding Service、diagnostics、開発用command/result履歴、秘密情報、無関係なproject dataは含めません。自己完結GitHub onboardingはWP Agent Bridge本体に含まれます。

外部テスト手順は[`docs/EXTERNAL_TEST_GUIDE_JA.md`](docs/EXTERNAL_TEST_GUIDE_JA.md)、release gateは[`PUBLIC_RELEASE.md`](PUBLIC_RELEASE.md)を参照してください。

WordPress.org Plugin Directoryからの配布は予定していません。

## License

無料でダウンロード・インストール・利用できます。個人的・非公開の改変は可能です。

元のソフトウェア、改変版、fork、build等を第三者へ再配布、販売、ミラー配布、第三者向けダウンロードとして提供することは禁止します。詳細は[`LICENSE.md`](LICENSE.md)を参照してください。

このライセンスは独自のsource-available licenseであり、オープンソースライセンスではありません。

## Status

**1.1.5 release candidate / validation in progress.**

1.1.5は、ChatGPT-local／会話添付／sandbox fileでGitHub側の`.b64` stagingを既定にせず、既存のauthenticated chunk uploadを優先するsource-aware routingをruntime identityへ組み込む更新です。TakKa Noteのcanonical runtimeでは同じ方針を先行反映済みで、1.1.5ではfresh installやruntime identity再同期後もその方針が維持されます。
