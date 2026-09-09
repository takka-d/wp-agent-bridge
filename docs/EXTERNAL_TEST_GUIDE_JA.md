# WP Agent Bridge 自己完結runtime 外部テスト手順

このテストでは、テスター本人が所有するGitHub・ChatGPT・WordPressだけを使い、WP Agent Bridgeの初回接続、通常操作、配送復旧、画像転送、Site Icon専用surface、複数theme file inspectionを確認します。

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

`AGENTS.md`ではv0.9.6の案内として、少なくとも次を確認する。

- ChatGPT-local / conversation / sandbox / connector-downloaded mediaは1 MiB以下なら media.upload.inline を1回使い、1 MiB超〜6 MiBの場合だけbatched staged-mediaを使う。
- 任意のGitHub local-file parameterがなくてもblockerとは判断しない。
- `/wp-agent-bridge-media/v1/upload-chunk`はbatched stagingが使えない、または実際に失敗した場合のfallbackである。
- `site.icon.get` / `site.icon.set` / `site.icon.clear` / `media.upload.capabilities`が案内される。
- `theme.files.list` / `theme.files.search` / `theme.file.read.many`が案内される。

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

## 8. v0.9.6 Site Icon / media capability E2E

まず変更を伴わないreadを確認する。

ChatGPTへ次のように依頼する。

```text
WP Agent Bridgeのv0.9.6 surfaceでmedia.upload.capabilitiesとsite.icon.getを実行して。現在のSite Icon attachment IDとmedia upload capabilityを取得するだけで、画像や設定は変更しないで。
```

確認:

- `media.upload.capabilities`が成功する。
- `site.icon.get`が成功する。
- current Site Iconが設定済みならattachment IDが取得できる。
- generic option patcherで`site_icon`を書き換えようとしない。

current Site Iconが設定済みの場合だけ、**同じattachment IDを再設定するno-change E2E**を行う。

```text
さきほどsite.icon.getで取得した現在のSite Icon attachment IDを、site.icon.setで同じIDのまま再設定して。confirm=trueを使い、expected_current_idにも現在のIDを指定してstale-write guardを通して。別画像には変更しないで。
```

確認:

- `site.icon.set`が成功する。
- 設定前後のattachment IDが同じである。
- visible faviconを別画像へ変更していない。

Site Iconが未設定なら、このno-change set testは「該当なし」としてよい。`site.icon.clear`をテストのためだけに実行しない。

## 9. v0.9.6 複数theme file inspection E2E

active themeに対してread-onlyで確認する。

```text
WP Agent Bridgeのv0.9.6 theme file surfaceだけを使って、active themeのtheme.files.list、theme.files.search、theme.file.read.manyを順に確認して。書き込みはしないで。read.manyは実在する2ファイル以上、最大20ファイルの範囲内で1回のBridge commandにまとめて。
```

確認:

- `theme.files.list`が成功する。
- `theme.files.search`が成功する。
- `theme.file.read.many`が成功する。
- `read.many`を単一file readの反復へ分解していない。
- theme root外を読まない。
- WPVibeへ迂回しない。

## 10. ChatGPT-local画像転送 — サイズによる最短経路

同梱の約2.4 MiB PNGを**このChatGPT会話へ添付して**使う。ファイルはChatGPT-local sourceとして扱う。

ChatGPTへ次のように依頼する。

```text
この添付画像を、今確認したuser-owned WP Agent Bridge runtimeだけを使ってWordPress Media Libraryへアップロードして。現行AGENTS.mdに従い、1 MiB以下なら media.upload.inline を1回使って。1 MiB超〜6 MiBの場合はbatched staged-media pathを使い、以下の分割手順を適用して。元binary全体のbytesとSHA-256を最初に計算し、元binaryをbounded chunkに分割してから各chunkを独立Base64化し、wordpress-bridge/media/pending/*.b64へtext payloadとしてstageして。ordered data_paths、filename、expected_bytes、expected_sha256を持つ/wp-agent-bridge-runtime/v1/media-upload commandは1件だけ作って。Git Data操作が使えるならpayload群とcommandを1つのtree/commit/ref更新で公開して。別のWordPress連携サービスへは迂回しないで。
```

確認ポイント:

- GitHub local-file parameterがないことをblocker扱いしない。
- 元画像全体のbytes / SHA-256を最初に計算する。
- 元binaryを先に分割し、各chunkを独立してBase64化する。
- Base64文字列を`wordpress-bridge/media/pending/*.b64`へstageする。
- upload commandは`/wp-agent-bridge-runtime/v1/media-upload`の1件だけである。
- commandはordered `data_paths`、whole-file `expected_bytes`、`expected_sha256`を持つ。
- Git Data操作が使える場合、payload群+commandを1回のtree/commit/ref更新で公開する。
- WordPressが元画像全体のbytes / SHA-256を検証する。
- WordPress Media Libraryへ登録される。
- 成功後にstaged payloadがcleanupされる。
- 別のWordPress連携サービスへ迂回しない。

失敗時は画像を縮小して成功扱いにしない。

## 11. Sequential chunk-command fallback

これはpreferred pathの代替確認であり、通常テストで意図的に選ぶ必要はない。

`/wp-agent-bridge-media/v1/upload-chunk`を使用してよいのは、GitHub connectorがbounded Base64 text/blobを確実にstageできないことが確認された場合、またはbatched staged-media writeが実際に失敗した場合だけとする。

fallback時もwhole-file / per-chunk bytes・SHA-256を計算し、順序付きcommandを1件ずつ完了確認してから次へ進み、最終chunkでwhole-file integrityを検証する。

## 12. upload-and-Site-Icon 1-command flow (任意)

テスト用WordPressでSite Iconを変更してよい場合だけ実施する。既存運用サイトでは必須ではない。

media-upload commandに次を追加し、Media Library登録とSite Icon設定が同じresultで完了することを確認する。

- `set_site_icon=true`
- `confirm_site_icon=true`
- 既存Site Iconがある場合は必要に応じて`expected_site_icon_id`

既存運用サイトでは手順8のsame-ID no-change E2Eを優先する。

## 13. pending取りこぼし復旧

通常利用者が意図的にGitHub障害を作る必要はありません。もしテスト中に`WordPressでは処理されたように見えるがpendingが残る`状態が自然発生した場合のみ、別の無害な`site.info` commandを1件投入する。

期待結果:

- 次のvalid pushで既存`commands/pending/`も再走査される。
- 同じ`request_id`のWordPress副作用は二重実行されない。
- result/completed/pending bookkeepingだけが復旧する。
- 実行中commandへ別pushのrecoveryが重なっても、一時的な`idempotency_in_progress`をterminal resultとして確定しない。

## 14. テスト後

- **ツール > WP Agent Bridge** で`Status: Connected (direct GitHub webhook)`のままであること。
- GitHub Appのrepository accessがテスト用private runtime repo 1個だけであること。
- operator-owned Organization、relay、runtime repositoryを使っていないこと。
- テストで作成したMedia Library attachmentが不要ならテスター自身の判断で削除する。
- 不要になったテスト環境はテスター自身の判断で削除する。

## 成功条件

1. ZIPをfresh installして有効化できる。
2. runtime repositoryをテスター自身のGitHub accountにprivateで作成できる。
3. site-specific private GitHub Appをテスター自身が所有し、runtime repo 1個だけにinstallできる。
4. WordPressがDirect Runtime Connectedになる。
5. canonical markerが`ownership=user-owned` / `operator_relay=false`になる。
6. ChatGPTから自分のruntime repoを認識できる。
7. `site.info` / `cache.flush`がuser-owned repo → signed Webhook → user WordPress → user-owned repoで完了する。
8. `media.upload.capabilities`と`site.icon.get`が成功し、Site Icon設定済みならsame-ID `site.icon.set` no-change E2Eがstale-write guard付きで成功する。
9. `theme.files.list` / `theme.files.search` / `theme.file.read.many`がread-onlyで成功する。
10. 約2.4 MiBのChatGPT-local添付画像をpreferred batched staged-media pathからMedia Libraryへ送れ、whole-file bytes / SHA-256検証に成功する。
11. sequential chunk routeをlocal-file parameter不足だけを理由に選ばない。
12. 運営者所有のGitHub/WordPress/relayへruntime command/resultやWordPress内容を送らない。
