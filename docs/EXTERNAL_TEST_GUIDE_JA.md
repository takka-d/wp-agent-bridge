# WP Agent Bridge Direct Runtime 外部テスト手順

対象: GitHub・ChatGPT・WordPressを利用できるテスター

このテストでは、**運営者の中継サーバーやWPVibeを使わず**、利用者自身のGitHubアカウントとWordPressだけで、初回接続からChatGPT経由のWordPress操作までを確認します。

## 事前条件

- テスター本人のGitHubアカウントを使う。
- テスター本人のChatGPTアカウントを使う。
- テスター本人が管理できるHTTPSのWordPressサイトを使う。
- WordPress REST APIへ外部から到達できること。
- WordPressにWP Agent Bridge / TakKa WordPress Bridgeの旧版・開発版が既にある場合は、fresh installテストとは分けて扱う。
- secret / token / private key / Webhook secretを共有・撮影しない。

## 1. WordPressへお試し版を入れる

1. 同梱のWP Agent Bridge ZIPをWordPress管理画面からアップロードする。
2. インストール後、プラグインを有効化する。
3. エラーが出た場合は連打せず、画面とエラー全文を保存する。

## 2. private runtime repositoryを作る

1. WordPress管理画面で **ツール > WP Agent Bridge** を開く。
2. `Status: Not connected` を確認する。
3. **Create private repository on GitHub** を押す。
4. GitHubの新規repository画面で、WordPress側に表示された `wordpress-bridge-...` 名になっていることを確認する。
5. Ownerは通常、自分のGitHubアカウントのままにする。
6. Visibilityは **Private** のまま作成する。
7. README等を手動追加する必要はない。
8. repository作成後、WordPressのWP Agent Bridge画面へ戻る。

## 3. サイト専用GitHub Appを作成・インストールする

1. WordPressのWP Agent Bridge画面で **Connect GitHub** を押す。
2. GitHubの **Create GitHub App** 画面が表示されることを確認する。
3. 自動入力された `WP Agent Bridge ...` の名前はそのままで作成する。
4. GitHub AppのInstall画面では **Only select repositories** を選ぶ。
5. 手順2で作った `wordpress-bridge-...` repository **だけ**を選ぶ。
6. 権限が概ね次であることを確認する。
   - Read access to metadata
   - Read and write access to code / repository contents
7. **Install** を押す。
8. WordPressへ自動で戻るまで待つ。

## 4. WordPress側の接続完了を確認する

次が表示されれば接続成功です。

- `GitHub direct connection completed.`
- `Status: Connected (direct GitHub webhook)`
- `Repository: <自分のGitHubアカウント>/wordpress-bridge-...`
- `Runtime branch: wp-agent-bridge-runtime`
- `GitHub App: wp-agent-bridge-...`

この時点で、通常経路は **ChatGPT → GitHub → 利用者のWordPress → GitHub → ChatGPT** です。

## 5. runtime repositoryを確認する

GitHubで手順4のprivate repositoryを開き、少なくとも次を確認します。

- `AGENTS.md`
- `wordpress-bridge/commands/pending/`
- `wordpress-bridge/commands/completed/`
- `wordpress-bridge/results/`
- branch `wp-agent-bridge-runtime`

以前のテスト等で似た名前のruntime repositoryがあっても、**WordPressのConnected画面に表示されたrepositoryを今回の接続先として使います。**

## 6. ChatGPTからrepositoryを確認する

1. ChatGPTで **設定 > プラグイン > GitHub** を確認する。
2. GitHub未接続なら、テスター本人のGitHubアカウントで通常の接続を行う。
3. GitHubプラグイン画面にrepository選択欄がなくても異常ではない。
4. ChatGPTへ次のように依頼する。

```text
GitHubで、私のWordPressのWP Agent Bridge画面に表示されたprivate runtime repositoryを探して、wp-agent-bridge-runtime branchのAGENTS.mdを読んで。見つかったrepository名だけ教えて。
```

5. 手順4と同じrepositoryが返れば成功。
6. 別の古いruntime repositoryを選んだ場合は、その時点で記録して終了する。

## 7. 安全なE2E操作

ChatGPTへ次のように依頼します。

```text
今確認したWP Agent Bridgeのruntime repositoryを使って、接続先WordPressのsite.infoを取得し、その後cache.flushを1回実行して。記事・設定・テーマ・プラグインは変更しないで、結果だけ教えて。
```

確認すること:

- commandが `commands/pending/` に書かれる。
- GitHub Appのsigned push WebhookでWordPressが処理する。
- `results/` に結果が返る。
- ChatGPTが結果を読める。
- 通常処理にGitHub Actionsを要求されない。

## 8. メディアアップロード

小さな画像をChatGPTへ添付し、次のように依頼します。

```text
この画像をWP Agent Bridgeだけを使って、接続先WordPressのメディアライブラリへアップロードして。WPVibeは使わないで。アップロード後、attachment IDとURLを教えて。
```

成功すれば、WP Agent Bridgeの `media.upload_base64` または `media.upload_from_url` 系の機能だけでメディアを登録できます。

※ 大きな画像にはサイズ上限があります。テストでは小さな画像を使ってください。

## 9. テスト後

- WordPressの **ツール > WP Agent Bridge** がConnectedのままであることを確認する。
- 結果は `EXTERNAL_TEST_RESULT.md` に記入する。
- エラー時に同じボタンを何度も押したり、GitHub Appを何個も作り直したりしない。
- secret / token / private key / Webhook secretは結果ファイルへ書かない。

## 成功条件

1. ZIPをfresh installして有効化できた。
2. 自分のprivate runtime repositoryを作成できた。
3. サイト専用GitHub AppをそのrepositoryだけにInstallできた。
4. WordPressが `Connected (direct GitHub webhook)` になった。
5. ChatGPTが正しいruntime repositoryを認識した。
6. `site.info` と `cache.flush` がDirect Runtimeで完了した。
7. WP Agent Bridgeだけでメディアアップロードできた。
8. WPVibe、運営者の中継サーバー、通常処理のGitHub Actionsを必要としなかった。
