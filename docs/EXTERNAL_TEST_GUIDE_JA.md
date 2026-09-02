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

**ここに表示されたRepositoryのフルネームを今回の唯一の正規runtime repositoryとして記録してください。** branch名やフォルダ名だけで別repositoryを選んではいけません。

この時点で、通常経路は **ChatGPT → GitHub → 利用者のWordPress → GitHub → ChatGPT** です。

## 5. runtime repositoryの正規マーカーを確認する

GitHubで手順4のprivate repositoryを開き、`wp-agent-bridge-runtime` branchで少なくとも次を確認します。

- `AGENTS.md`
- `wordpress-bridge/RUNTIME_CONNECTION.json`
- `wordpress-bridge/commands/pending/`
- `wordpress-bridge/commands/completed/`
- `wordpress-bridge/results/`

`wordpress-bridge/RUNTIME_CONNECTION.json` は次を満たすことを確認します。

- `status`: `canonical`
- `transport`: `direct-github-webhook`
- `repository`: 手順4で記録したRepositoryと完全一致
- `runtime_branch`: `wp-agent-bridge-runtime`
- `site_host`: 今回のWordPressサイト

以前のテスト等で似た名前のruntime repositoryがあっても、**WordPressのConnected画面とこのマーカーが一致したrepositoryだけ**を使います。

## 6. ChatGPTからrepositoryを確認する

1. ChatGPTで **設定 > プラグイン > GitHub** を確認する。
2. GitHub未接続なら、テスター本人のGitHubアカウントで通常の接続を行う。
3. GitHubプラグイン画面にrepository選択欄がなくても異常ではない。
4. ChatGPTへ、手順4で記録したRepositoryのフルネームを含めて次のように依頼する。

```text
GitHubで <WordPressのConnected画面に表示されたRepositoryフルネーム> の wp-agent-bridge-runtime branchを確認して。最初に AGENTS.md と wordpress-bridge/RUNTIME_CONNECTION.json を読み、markerのrepository/status/transport/site_hostが一致することを確認してから、このrepositoryだけを今回のWordPress操作に使って。似た名前の別repositoryへは書き込まないで。
```

5. 正しいrepositoryとcanonical markerを確認できれば成功。
6. 別repositoryを選んだ場合は、その別repositoryへcommandを書かずにテストを止めて記録する。

## 7. 安全なE2E操作

ChatGPTへ次のように依頼します。

```text
今確認したcanonical WP Agent Bridge runtime repositoryだけを使って、接続先WordPressのsite.infoを取得し、その後cache.flushを1回実行して。記事・設定・テーマ・プラグインは変更しないで、結果だけ教えて。
```

確認すること:

- commandが正しいrepositoryの `commands/pending/` に書かれる。
- GitHub Appのsigned push WebhookでWordPressが処理する。
- 同じrepositoryの `results/` に結果が返る。
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
5. `RUNTIME_CONNECTION.json` がcanonicalで、WordPress表示のrepositoryと一致した。
6. ChatGPTがその正しいruntime repositoryだけを認識した。
7. `site.info` と `cache.flush` がDirect Runtimeで完了した。
8. WP Agent Bridgeだけでメディアアップロードできた。
9. WPVibe、運営者の中継サーバー、通常処理のGitHub Actionsを必要としなかった。
