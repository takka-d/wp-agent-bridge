# WP Agent Bridge

ChatGPTからWordPressの記事・ページ・設定などを更新するためのWordPressプラグインです。

通常のWordPress操作は、**利用者自身が所有するprivate GitHub runtime repository**と、**そのWordPress専用のprivate GitHub App**を使って実行します。WP Agent Bridge運営者のruntime repository、relay server、GitHub Actions workerを通常経路として使用しません。

## 現在の配布状態

- Version: `1.1.3`
- Status: **release candidate / external tester distribution ready**
- 再現可能なplugin ZIP SHA-256: `24c8c20bf36ace6592d7857865de848e4762ebaa6773f63089bebc4d1c270326`
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
| media一時payload | 利用者のprivate runtime repository | しない |
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

WordPress側のmedia上限は6 MiBです。runtime command JSON自体は2 MiB以下に制限します。

自己完結runtimeでは、大きな画像を1個のcommand JSONへBase64直埋めしません。Base64 payloadを利用者自身のruntime repositoryの`wordpress-bridge/media/pending/*.b64`へ置き、小さいcommandから`/wp-agent-bridge-runtime/v1/media-upload`を呼び出します。必要なら複数payloadへ分割できます。

1. ChatGPT側で元ファイルのbytes / SHA-256を計算する。
2. Git Data操作が利用できる場合はpayload群をblobとして先にstagingし、payload群とupload commandを**1つのtree / commit / ref更新**でruntime branchへ公開する。
3. WordPress側が`data_path` / `data_paths`から元ファイルを再構成し、`expected_bytes` / `expected_sha256`を検証してMedia Libraryへ登録する。
4. 成功後は、使用した`.b64` payloadを**1つのGit tree cleanup commit**でまとめて削除する。
5. cleanup中にruntime branchが別commitで進んだ場合は、一部ファイルを先に削除せず最新HEADからbounded retryする。
6. resultが先に見えた場合も、対応するpending commandが消えるかcompletedが確認できるまで次のruntime branch更新を開始しない。

これにより、画像1枚につきpayload数だけbranchを動かす方式と、payloadを1個ずつcleanup commitする方式を避け、画像アップロード時のGitHub 409競合窓を縮小します。bounded chunk REST routeもfallbackとして保持します。

## 配送失敗からの復旧

GitHub pushはdurable queueではないため、WebhookやGitHub書き戻しを一度取りこぼす可能性があります。Direct Runtimeはvalidなpushごとに現在の`commands/pending/`も再走査します。

同一`request_id`はWordPress側でcompleted responseを再利用するため、`WordPress実行成功 → GitHub result書き戻し失敗`が起きても、後続pushで副作用を二重実行せずbookkeepingを復旧する設計です。実行中command自身がruntime repositoryへresult/media等を書き戻したpushについても、同じrequest_idを再実行しないloop guardを持ちます。

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

**1.1.3 release candidate / external tester distribution ready.**

1.1.3は、画像upload時にruntime branchを細かく動かすことで起きていた競合を減らす更新です。複数payloadとupload commandをGit Data APIで1回のbranch更新として投入できる手順へ変更し、WordPress側のpayload cleanupも1ファイル1commitではなく1つのGit tree commitに集約しました。cleanup時にbranchが進んだ場合は最新HEADから再構築してbounded retryします。

隔離CIでは、clean WordPress 7.1 + MySQL 8、既存guard/rollback、request-id idempotency、self-update破壊再発防止、GitHub書き戻し失敗後のpending recovery、migration rollback metadataに加え、約2.4 MiB画像を複数payloadから再構成し、cleanup ref更新を1回意図的に409競合させた後、部分削除なしで再試行できることを確認しています。

1.1.3 plugin ZIP SHA-256: `24c8c20bf36ace6592d7857865de848e4762ebaa6773f63089bebc4d1c270326`。

TakKa Note実環境への1.1.3反映と実画像での再検証は、release candidateのCI通過後に行います。
