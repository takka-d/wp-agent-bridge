# WP Agent Bridge 1.0.0

ChatGPTからWordPressを更新するためのWordPressプラグインです。

通常のWordPress操作は、専用private GitHub repositoryへのcommand作成 → GitHub Appのsigned Webhook → WordPress → result書き戻し、という経路で実行します。通常処理にGitHub Actionsは使用しません。

## 導入

1. ZIPをダウンロードする。
2. WordPressへインストールして有効化する。
3. ツール > WP Bridge Setup > Connect GitHub。
4. GitHubで接続を許可する。
5. 自動作成されたruntime repositoryをChatGPTのGitHub連携へ追加する。
6. ChatGPTにWordPress更新を依頼する。

repository、runtime branch、relay用secret等はオンボーディング処理で自動設定します。

## 主な機能

- 投稿・固定ページ・メディア等のWordPress REST API操作
- テーマファイルの読取・検索・編集
- Draft Themeのpreview / publish / rollback
- post meta、option、taxonomy、menu、cron等の管理
- guarded write、preview、confirm、SHA-256、plan/impact hash、stale-write rejection
- HMAC署名認証とsecure transport

任意shell command、任意WP-CLI文字列、無制限SQL writeは公開しません。

## License

無料利用可、個人的・非公開の改変可、再配布禁止、無保証です。詳細はLICENSE.mdを参照してください。
