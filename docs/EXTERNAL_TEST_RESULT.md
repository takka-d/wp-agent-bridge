# WP Agent Bridge Direct Runtime 外部テスト結果

テスター名は本名でなくて構いません。secret / token / private key / Webhook secret等は絶対に記載しないでください。

## 基本情報

- テスト日時:
- テスター識別名:
- WordPressバージョン:
- PHPバージョン:
- ブラウザ / ChatGPTアプリ:

## 1. fresh install

- [ ] お試し版ZIPを新規インストールできた
- [ ] 有効化できた
- [ ] `ツール > WP Agent Bridge` を開けた
- [ ] 旧 `TakKa WP Bridge` / Bridge Key設定画面が通常メニューに出ていない

補足:

## 2. private runtime repository

- [ ] `Status: Not connected` が表示された
- [ ] `Create private repository on GitHub` を押した
- [ ] 指定された `wordpress-bridge-...` 名で作成した
- [ ] VisibilityをPrivateにした
- Repository:

## 3. site-specific GitHub App

- [ ] `Connect GitHub` を押した
- [ ] `Create GitHub App` 画面が表示された
- [ ] GitHub Appを作成できた
- [ ] `Only select repositories` を選んだ
- [ ] 今回のruntime repositoryだけを選んだ
- [ ] Installできた
- 権限表示で気づいた点:

## 4. WordPress接続完了

- [ ] `GitHub direct connection completed.` が表示された
- [ ] `Status: Connected (direct GitHub webhook)` になった
- Repository:
- Runtime branch:
- GitHub App slug:

## 5. canonical runtime identity

- [ ] `AGENTS.md` を確認できた
- [ ] `wordpress-bridge/RUNTIME_CONNECTION.json` を確認できた
- [ ] marker `status` が `canonical`
- [ ] marker `transport` が `direct-github-webhook`
- [ ] marker `repository` がWordPressのConnected画面と完全一致
- [ ] marker `runtime_branch` が `wp-agent-bridge-runtime`
- [ ] marker `site_host` が今回のWordPressサイトと一致
- [ ] `wordpress-bridge/commands/pending/` を確認できた
- [ ] `wordpress-bridge/commands/completed/` を確認できた
- [ ] `wordpress-bridge/results/` を確認できた
- 古い別runtime repositoryを誤って選んだ: はい / いいえ

## 6. ChatGPT GitHub接続

- ChatGPTのGitHubはテスト前から接続済みだった: はい / いいえ
- [ ] テスター本人のGitHubアカウントで接続できた
- [ ] ChatGPTがConnected画面とmarkerで指定されたruntime repositoryを認識できた
- [ ] 似た名前の別repositoryへcommandを書かなかった
- ChatGPTが認識したRepository:

## 7. Direct Runtime E2E

- [ ] `site.info` が成功した
- [ ] `cache.flush` が成功した
- [ ] 正しいrepositoryの `results/` に結果が作成された
- [ ] ChatGPTが結果を読み取れた
- [ ] 通常処理でGitHub Actionsを要求されなかった

補足:

## 8. メディアアップロード

- [ ] ChatGPTへ小さな画像を添付した
- [ ] WP Agent BridgeだけでWordPressへアップロードできた
- [ ] WPVibeを使わなかった
- Attachment ID:
- Media URL:

失敗した場合のエラー:

## 9. 最終状態

- [ ] WordPressのWP Agent BridgeはConnectedのまま
- [ ] 運営者の中継サーバーを設定していない
- [ ] WPVibeを一般利用者向け経路として必要としなかった
- [ ] secret / token / private key / Webhook secretを共有していない

## 総合結果

- [ ] 成功
- [ ] 失敗
- [ ] 一部成功 / 要確認

失敗または要確認の場合:

- 止まった手順番号:
- 画面に表示されたエラー全文:
- 直前に行った操作:
- スクリーンショット有無:
- その他:
