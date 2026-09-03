# WP Agent Bridge 1.0.1 外部テスト手順

対象: GitHub・ChatGPT・WordPressを普段から利用しているテスター

このテストでは、一般利用者としてWP Agent Bridgeの初回接続、通常のWordPress操作、2 MiBを超える画像転送を確認します。

## 事前条件

- テスター本人のGitHub、ChatGPT、WordPressを使う。
- HTTPSのWordPress REST APIへ外部から到達できること。
- 既存のWP Agent BridgeまたはTakKa WordPress Bridgeが入っている場合はfresh installテストを中止して報告する。
- 認証情報などの秘密情報は共有しない。

## 1. WordPressへプラグインを入れる

1. 同梱の `wp-agent-bridge-1.0.1.zip` を使う。
2. WordPress管理画面の **プラグイン > 新規プラグインを追加 > プラグインのアップロード** からインストールする。
3. インストール完了後に有効化する。
4. エラーが出た場合は同じ操作を繰り返さず、その画面を保存して報告する。

## 2. GitHub接続を開始する

1. **ツール > WP Bridge Setup** を開く。
2. `Status: Not connected` と **Connect GitHub** が表示されることを確認する。
3. **Connect GitHub** を押し、GitHubで通常の認可を完了する。
4. Organization全体の管理権限を追加する操作は行わない。
5. repository invitationのAcceptが必要なら行い、不要ならそのまま続行する。

## 3. WordPress側の接続完了を確認する

次を確認する。

- `GitHub connection completed.`
- `Status: Connected`
- `Repository: wp-agent-bridge-runtime/wordpress-bridge-...`
- `Runtime branch: wp-agent-bridge-runtime`

Repository名を結果記録へコピーする。

## 4. GitHub側で自分のruntime repoを確認する

1. 手順3のprivate repositoryを開けることを確認する。
2. 他人のruntime repositoryを探したり権限変更したりしない。
3. `AGENTS.md` と `wordpress-bridge/` が存在することを確認する。

## 5. ChatGPTのGitHub接続を確認する

ChatGPTから次のように依頼する。

```text
GitHubで、私がアクセスできる wp-agent-bridge-runtime Organizationの wordpress-bridge- で始まるprivate repositoryを確認して。AGENTS.mdを読めるか確認して、repository名だけ教えて。
```

手順3のrepository名が返れば成功。見つからない場合はOrganization設定を変更せず、その時点で終了する。

## 6. WordPressへの安全なE2E操作

ChatGPTへ次のように依頼する。

```text
今確認したWP Agent Bridgeのruntime repositoryを使って、接続先WordPressで site.info を取得し、その後 cache.flush を1回実行して。WordPressの記事・設定・テーマ・プラグインは変更しないで、結果だけ教えて。
```

`site.info`と`cache.flush`の両方がruntime repository経由で完了することを確認する。

## 7. 2 MiB command上限を超える画像転送

同梱の `03_画像転送テスト用_約2.4MB.png` を使う。この画像はWordPress側の6 MiB上限以内だが、全体をBase64化して1つのcommand JSONへ埋め込むとruntimeの2 MiB上限を超える。

1. `03_画像転送テスト用_約2.4MB.png` をChatGPTへ添付する。
2. 次のように依頼する。

```text
この画像を、今確認したWP Agent Bridgeの正式なruntime repository経由でWordPressメディアライブラリへアップロードして。別のWordPress連携経路は使わず、WP Agent Bridge 1.0.1のchunked media transportを使って。成功したらattachment ID、URL、元ファイルのbytesとSHA-256検証結果を教えて。
```

確認ポイント:

- 画像全体のBase64を1つのcommand JSONへ直埋めしない。
- runtime上限内のchunkへ分割する。
- 各chunkのbytesとSHA-256をWordPress側で検証する。
- 全chunk受信後、元ファイル全体のbytesとSHA-256を再検証してからメディア登録する。
- attachment IDとURLが返る。
- 別経路へ迂回しない。

失敗した場合は画像を縮小して再試行せず、そのresultを保存して終了する。

## 8. テスト後

- `WP Bridge Setup` が `Status: Connected` のままであることを確認する。
- `02_テスト結果記入.md` に結果を記入する。
- エラー時は同じ操作を繰り返さず、エラー全文と直前の操作を残す。

## 成功条件

1. 1.0.1 ZIPをfresh installして有効化できた。
2. GitHub認可を経てWordPressがConnectedになった。
3. `wp-agent-bridge-runtime` にテスター専用private repoが作成された。
4. ChatGPTからそのrepoを認識できた。
5. `site.info` と `cache.flush` がBridge経由で完了した。
6. 約2.4 MiBのテストPNGが、縮小や別経路に逃げずchunked media transportでアップロードできた。
7. Organization管理権限や他人のruntime repoへのアクセスを必要としなかった。
