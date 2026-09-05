# WP Agent Bridge 自己完結runtime 外部テスト手順

このテストでは、テスター本人が所有するGitHub・ChatGPT・WordPressだけを使い、WP Agent Bridgeの初回接続、通常操作、配送復旧、大きな画像転送を確認します。

**テスターのGitHubユーザー名、WordPress URL、記事本文、command/result、secret等をWP Agent Bridge運営者へ提出することはテスト条件ではありません。**

## 事前条件

- テスター本人のGitHub、ChatGPT、WordPressを使う。
- HTTPSのWordPress REST APIへGitHub Webhookから到達できること。
- GitHub上にprivate repositoryを1個作れること。
- 既存のWP Agent Bridge / TakKa WordPress Bridgeが入っている場合はfresh installテストを中止する。
- token、private key、Webhook secret、Cookie、nonce等を第三者へ共有しない。

## 1. WordPressへプラグインを入れる

1. テスト対象のWP Agent Bridge ZIPを使う。
2. WordPress管理画面の **プラグイン > 新規プラグインを追加 > プラグインのアップロード** からインストールする。
3. インストール完了後に有効化する。
4. エラーが出た場合は同じ操作を繰り返さず停止する。

## 2. 自分のprivate runtime repositoryを作る

1. **ツール > WP Agent Bridge** を開く。
2. `Status: Not connected` を確認する。
3. **Create private repository on GitHub** を開く。
4. prefillされたrepository名を使い、**自分自身のGitHubアカウント**にprivate repositoryを作成する。
5. publicへ変更しない。

WP Agent Bridge運営者のOrganizationへ参加したり、運営者所有repositoryへcollaborator追加されたりする操作はありません。

## 3. site-specific GitHub Appを作る

1. WordPressへ戻り **Connect GitHub** を押す。
2. GitHub App manifest画面へ移動することを確認する。
3. 作成されるGitHub Appの所有者が自分自身のGitHubアカウントであることを確認する。
4. GitHub Appをインストールする際、**Only select repositories** を選択する。
5. 手順2で作成したprivate runtime repository **1個だけ**を選択する。
6. WordPressへ戻る。

利用者がPAT、private key、Webhook secret、Bridge Key、GitHub Actions workflowを手入力することはありません。GitHubから返されたsite-specific Appのprivate key / Webhook secretは、そのWordPress内だけに暗号化保存されます。

## 4. WordPress側の接続完了を確認する

次を確認する。

- `GitHub direct connection completed.`
- `Status: Connected (direct GitHub webhook)`
- Repositoryが**自分自身のGitHub account/private repository**になっている
- Runtime branchが`wp-agent-bridge-runtime`
- GitHub Appが自分のaccountに作成したsite-specific Appになっている

## 5. runtime repositoryを確認する

`wp-agent-bridge-runtime` branchで以下を確認する。

- `AGENTS.md`
- `wordpress-bridge/RUNTIME_CONNECTION.json`
- `wordpress-bridge/WEBHOOK_RUNTIME.md`
- `wordpress-bridge/commands/pending/`
- `wordpress-bridge/commands/completed/`
- `wordpress-bridge/results/`
- `wordpress-bridge/media/pending/`

`RUNTIME_CONNECTION.json`では少なくとも次を確認する。

- `status: canonical`
- `transport: direct-github-webhook`
- `ownership: user-owned`
- `operator_relay: false`
- repository名が今開いているrepository自身と一致

`AGENTS.md`では、ChatGPT-local / conversation-uploaded fileに対して`/wp-agent-bridge-media/v1/upload-chunk`を優先し、GitHubへ`.b64` payloadを無理に作ろうとしない指示があることも確認する。

## 6. ChatGPTから自分のruntimeを認識する

ChatGPTのGitHub接続を**テスター本人のGitHubアカウント**へ接続する。

その後、次のように依頼する。

```text
私のGitHubでWP Agent Bridgeのruntime repositoryを確認して。AGENTS.mdとwordpress-bridge/RUNTIME_CONNECTION.jsonを読み、status=canonical、transport=direct-github-webhook、ownership=user-owned、operator_relay=falseであることと、repository markerが実際のrepository自身を指していることだけ確認して。
```

運営者所有Organization/runtimeを探す必要はありません。

## 7. 安全なBridge E2E

ChatGPTへ次のように依頼する。

```text
今確認したuser-owned WP Agent Bridge runtimeを使って、接続先WordPressでsite.infoを取得し、その後cache.flushを1回実行して。記事・設定・テーマ・プラグインは変更しないで、結果だけ確認して。
```

確認:

- commandが自分のprivate repoの`commands/pending`へ作られる。
- GitHub App signed Webhookが自分のWordPressへ直接届く。
- result/completedが同じ自分のprivate repoへ返る。
- 運営者所有relay/repositoryを経由しない。

## 8. ChatGPT-local画像転送

同梱の約2.4 MiB PNGを**このChatGPT会話へ添付して**使う。ファイルはChatGPT-local sourceとして扱い、GitHub connectorにローカルfile parameterがない場合は`wordpress-bridge/media/pending/*.b64`へ転写しようとしない。

ChatGPTへ次のように依頼する。

```text
この添付画像を、今確認したuser-owned WP Agent Bridge runtimeだけを使ってWordPress Media Libraryへアップロードして。AGENTS.mdのChatGPT-local media手順に従い、GitHubのmedia/pendingへローカルファイルを転写しようとせず、/wp-agent-bridge-media/v1/upload-chunkを使って。元ファイル全体のbytesとSHA-256を最初に計算し、binaryを順序付きchunkに分割して、各chunkのbytesとSHA-256も検証してから、そのchunkだけをBase64化してnormal runtime REST commandとして順番に送って。各chunk commandのpending消失またはcompletedを確認してから次を送り、最終chunkで全体bytes/SHA-256一致を確認してMedia Libraryへ登録して。別のWordPress連携サービスへは迂回しないで。
```

確認ポイント:

- ChatGPT-local sourceを`media/pending/*.b64`へコピーしようとして長時間停止しない。
- `/wp-agent-bridge-media/v1/upload-chunk`を使用する。
- command/resultはテスター本人のprivate runtime repoだけを使う。
- 元画像全体のbytes / SHA-256を最初に計算する。
- binaryを順序付きchunkへ分割し、各chunkのbytes / SHA-256も検証する。
- 各chunkだけをBase64化する。
- 各command完了を確認してから次のchunkを送る。
- 最終chunkでWordPressが元画像全体のbytes / SHA-256を検証する。
- WordPress Media Libraryへ登録される。
- WordPress側の一時chunk stagingが成功後にcleanupされる。
- 別のWordPress連携サービスへ迂回しない。

失敗時は画像を縮小して成功扱いにせず、その時点で停止する。

## 9. GitHub-staged media経路（任意）

これは1.1.5のlocal-fileテストとは別の任意確認です。media sourceが既にGitHub connectorでmanageableなtext/blobとして扱える場合のみ、`wordpress-bridge/media/pending/*.b64` + `/wp-agent-bridge-runtime/v1/media-upload`を使用してよいです。

この経路では、元binaryを先に分割して各chunkを独立Base64化し、staged blobを検証し、可能ならpayload群+commandを1つのGit tree/commit/ref更新で公開し、成功後に1つのbounded-retry cleanup commitでpayloadを削除することを確認します。

ChatGPT-local添付ファイルを、この任意経路を試すためだけにGitHubへ手作業的に転写してはいけません。

## 10. pending取りこぼし復旧

通常利用者が意図的にGitHub障害を作る必要はありません。もしテスト中に`WordPressでは処理されたように見えるがpendingが残る`状態が自然発生した場合のみ、別の無害な`site.info` commandを1件投入する。

期待結果:

- 次のvalid pushで既存`commands/pending/`も再走査される。
- 同じ`request_id`のWordPress副作用は二重実行されない。
- result/completed/pending bookkeepingだけが復旧する。
- 実行中commandへ別pushのrecoveryが重なっても、一時的な`idempotency_in_progress`をterminal resultとして確定しない。

## 11. テスト後

- **ツール > WP Agent Bridge** で`Status: Connected (direct GitHub webhook)`のままであること。
- GitHub Appのrepository accessがテスト用private runtime repo 1個だけであること。
- operator-owned Organization、relay、runtime repositoryを使っていないこと。
- 不要になったテスト環境はテスター自身の判断で削除する。

## 成功条件

1. ZIPをfresh installして有効化できる。
2. runtime repositoryをテスター自身のGitHub accountにprivateで作成できる。
3. site-specific private GitHub Appをテスター自身が所有し、runtime repo 1個だけにinstallできる。
4. WordPressがDirect Runtime Connectedになる。
5. canonical markerが`ownership=user-owned` / `operator_relay=false`になる。
6. ChatGPTから自分のruntime repoを認識できる。
7. `site.info` / `cache.flush`がuser-owned repo → signed Webhook → user WordPress → user-owned repoで完了する。
8. 約2.4 MiBのChatGPT-local添付画像を、GitHub `.b64` stagingで詰まらずauthenticated chunk routeからMedia Libraryへ送れ、全体bytes / SHA-256検証に成功する。
9. 運営者所有のGitHub/WordPress/relayへruntime command/resultやWordPress内容を送らない。