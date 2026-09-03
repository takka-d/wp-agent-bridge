# WP Agent Bridge 1.0.0

ChatGPTからWordPressを更新するためのWordPressプラグインです。

通常のWordPress操作は、利用者自身のprivate GitHub repositoryへのcommand作成 → サイト専用GitHub Appのsigned push Webhook → 利用者のWordPress → result書き戻し、という経路で実行します。通常処理に運営者の中継サーバーやGitHub Actionsは使用しません。

## 導入

1. ZIPをダウンロードする。
2. WordPressへインストールして有効化する。
3. **ツール > WP Agent Bridge** を開く。
4. **Create private repository on GitHub** から、表示された名前のprivate repositoryを自分のGitHubアカウントに作成する。
5. WordPressへ戻って **Connect GitHub** を押す。
6. GitHubでサイト専用GitHub Appを作成し、**Only select repositories** で手順4のrepositoryだけを選んでInstallする。
7. WordPressに戻り `Status: Connected (direct GitHub webhook)` を確認する。
8. ChatGPTでGitHubを接続し、手順4のruntime repositoryを参照できることを確認する。
9. ChatGPTにWordPress更新を依頼する。

private keyとWebhook secretは利用者のWordPress内に暗号化して保存します。WPVibeや運営者所有の中継サーバーは一般利用者の通常経路には不要です。

## 主な機能

- 投稿・固定ページの取得、作成、更新
- メディアアップロード
  - Direct Runtimeでは画像本体をcommand JSONへ埋め込まず、`wordpress-bridge/media/pending/*.b64` に分離して転送
  - 1ファイルまたは複数chunkに分割可能
  - `expected_bytes` と `expected_sha256` で完全性を確認
  - Data URL、空白、URL-safe Base64、padding省略を正規化
  - 成功後は一時payloadをprivate repositoryから削除
  - URLからのアップロードも対応
- テーマファイルの読取・検索・編集
- Draft Themeのpreview / publish / rollback
- プラグイン / テーマ管理
- post meta、option、taxonomy、menu、cron等の管理
- WordPress REST API経由の各種操作
- guarded write、preview、confirm、SHA-256、plan/impact hash、stale-write rejection
- HMAC署名認証とsecure transport

任意shell command、任意WP-CLI文字列、無制限SQL writeは公開しません。

## License

無料利用可、個人的・非公開の改変可、再配布禁止、無保証です。詳細はLICENSE.mdを参照してください。
