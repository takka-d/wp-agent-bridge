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

## 8. 大きな画像転送

同梱の約2.4 MiB PNGを使う。これはWordPress側6 MiB上限以内だが、全体Base64を1つのcommand JSONへ入れる方式は使わない。

ChatGPTへ次のように依頼する。

```text
この画像をuser-owned WP Agent Bridge runtimeだけを使ってWordPress Media Libraryへアップロードして。画像全体のBase64を1個のcommand JSONへ入れず、wordpress-bridge/media/pending/のBase64 payloadファイルを使うself-contained media transportで送って。必要ならpayloadを複数ファイルに分割してdata_pathsを使って。Git Data操作が使える場合はpayload群とupload commandを別々にbranchへcommitせず、blobを先に作って1つのtree/commit/ref更新としてまとめて公開して。元ファイルのbytesとSHA-256をWordPress側で検証し、成功後に一時payloadを削除して。resultが見えてもpendingが消えるかcompletedが見えるまでは次のruntime branch更新を始めないで。
```

確認ポイント:

- payload保存先はテスター本人のprivate runtime repoだけ。
- command JSONへ画像全体のBase64を直埋めしない。
- Git Data操作を利用できる場合、payload群とupload commandが1つのruntime branch更新で投入される。
- `data_path`または`data_paths`からWordPressが元画像を再構成する。
- 元画像のbytes / SHA-256一致後にMedia Libraryへ登録する。
- 成功後に`media/pending/*.b64`がまとめて削除される。
- cleanup中にbranchが動いた場合も、一部payloadだけを先に削除せず最新HEADから再試行される。
- 別のWordPress連携サービスへ迂回しない。

失敗時は画像を縮小して成功扱いにせず、その時点で停止する。

## 9. pending取りこぼし復旧

通常利用者が意図的にGitHub障害を作る必要はありません。もしテスト中に`WordPressでは処理されたように見えるがpendingが残る`状態が自然発生した場合のみ、別の無害な`site.info` commandを1件投入する。

期待結果:

- 次のvalid pushで既存`commands/pending/`も再走査される。
- 同じ`request_id`のWordPress副作用は二重実行されない。
- result/completed/pending bookkeepingだけが復旧する。

## 10. テスト後

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
8. 約2.4 MiB画像を縮小・別経路なしでMedia Libraryへ送れ、payload cleanupの競合で取り残しや部分削除が起きない。
9. 運営者所有のGitHub/WordPress/relayへruntime command/resultやWordPress内容を送らない。
