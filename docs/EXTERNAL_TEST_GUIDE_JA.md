# WP Agent Bridge 1.0.0 RC1 外部テスト手順

対象: GitHub・ChatGPT・WordPressを普段から利用しているテスター

このテストでは、Organization ownerではない一般利用者として、WP Agent Bridgeの初回接続からChatGPT経由のWordPress操作までを確認します。

## 事前条件

- テスター本人のGitHubアカウントを使う。
- テスター本人のChatGPTアカウントを使う。
- テスター本人が管理できるHTTPSのWordPressサイトを使う。
- WordPress REST APIへ外部から到達できること。
- そのWordPressに `WP Agent Bridge` または旧 `TakKa WordPress Bridge` が既に入っている場合は、置き換えずにいったんテストを止めて報告する。今回はfresh installを確認する。
- GitHub Client secret、token、private key、Webhook secret等を誰かへ送ったり、スクリーンショットに写したりしない。

## 1. WordPressへプラグインを入れる

1. 同梱の `wp-agent-bridge-1.0.0.zip` を使う。
2. WordPress管理画面で **プラグイン > 新規プラグインを追加 > プラグインのアップロード** を開く。
3. `wp-agent-bridge-1.0.0.zip` を選択する。
4. **今すぐインストール** を押す。
5. インストール完了後、**プラグインを有効化** を押す。
6. エラーが出た場合は、それ以上操作せず画面を保存して報告する。

## 2. GitHub接続を開始する

1. WordPress管理画面で **ツール > WP Bridge Setup** を開く。
2. 初回なら `Status: Not connected` と **Connect GitHub** が表示されることを確認する。
3. **Connect GitHub** を押す。
4. GitHubの `WP Agent Bridge Setup by TakKa` の認可画面が出た場合、権限表示が概ね次の内容であることを確認する。
   - Public repositories: read-only
   - repository invitations
5. Organization全体への追加権限を求める `Grant` 等が表示されても、今回のテストでは押さない。
6. 通常の認可ボタンを押して続行する。
7. GitHubのrepository invitation確認画面や通知が出た場合は、その内容を記録する。Acceptが必要ならAcceptする。何も出ず自動で進んでも正常候補として記録する。

## 3. WordPress側の接続完了を確認する

1. WordPressへ戻るまで待つ。
2. 次が表示されれば接続成功。
   - `GitHub connection completed.`
   - `Status: Connected`
   - `Repository: wp-agent-bridge-runtime/wordpress-bridge-...`
   - `Runtime branch: wp-agent-bridge-runtime`
3. Repository名を結果記録へコピーする。
4. `Reconnect GitHub` は押さない。

## 4. GitHub側で自分のruntime repoを確認する

1. GitHubで、手順3に表示されたprivate repositoryを開けるか確認する。
2. `wp-agent-bridge-runtime` Organizationの他人のprivate runtime repositoryを探したり、権限変更したりしない。
3. 自分のrepositoryだけ開ければよい。
4. repository内に少なくとも `AGENTS.md` と `wordpress-bridge/` があることを確認する。

## 5. ChatGPTのGitHub接続を確認する

1. ChatGPTで **設定 > プラグイン > GitHub** を開く。
2. GitHubが未接続なら、テスター本人のGitHubアカウントで接続する。
3. 既に接続済みなら、切断・再設定はまず行わない。
4. `wp-agent-bridge-runtime` Organization側のChatGPT Codex Connectorは運営側で設定済みなので、テスターがOrganization設定や `All repositories` / `Only select repositories` を変更する必要はない。
5. ChatGPTへ戻り、次のように依頼する。

```text
GitHubで、私がアクセスできる wp-agent-bridge-runtime Organizationの wordpress-bridge- で始まるprivate repositoryを確認して。AGENTS.mdを読めるか確認して、repository名だけ教えて。
```

6. 手順3で作成されたrepository名が返れば成功。
7. 見つからない場合は、Organization設定を触らず、その時点のChatGPT画面とGitHub側の自分のrepositoryアクセス状況を記録して終了する。

## 6. WordPressへの安全なE2E操作を1回行う

ChatGPTへ次のように依頼する。

```text
今確認したWP Agent Bridgeのruntime repositoryを使って、接続先WordPressで site.info を取得し、その後 cache.flush を1回実行して。WordPressの記事・設定・テーマ・プラグインは変更しないで、結果だけ教えて。
```

確認ポイント:

- ChatGPTがruntime repositoryへcommandを書き込める。
- WordPress側でcommandが実行される。
- GitHubへresultが返る。
- ChatGPTが `site.info` の結果と `cache.flush` 成功を読み取れる。

記事本文や設定値を変更する必要はありません。

## 7. テスト後

- WordPressの **ツール > WP Bridge Setup** が `Status: Connected` のままであることを確認する。
- 結果は同梱の `EXTERNAL_TEST_RESULT.md` に記入する。
- エラーが出た箇所では、同じボタンを何度も押したり、GitHub Appを削除・再インストールしたりしない。
- 画面、エラー文、発生した手順番号をそのまま残す。

## 成功条件

次をすべて満たせばfresh-user E2E成功とします。

1. RC1 ZIPをfresh installして有効化できた。
2. GitHub認可を経てWordPressがConnectedになった。
3. `wp-agent-bridge-runtime` にテスター専用private repoが作成された。
4. テスター本人はそのrepoへアクセスできた。
5. ChatGPTからそのrepoを認識できた。
6. `site.info` と `cache.flush` がBridge経由で完了した。
7. テスト中にOrganization管理権限や他人のruntime repoへのアクセスを必要としなかった。
