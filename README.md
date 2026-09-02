# WP Agent Bridge

ChatGPTからWordPressの記事・ページ・設定などを更新するためのWordPressプラグインです。

特定のWordPress連携サービス側に独自の月間実行回数や操作回数の課金枠を設けず、GitHub接続を利用してWordPressを操作します。

通常のWordPress commandは、専用private GitHub repositoryへのcommand作成 → GitHub Appのsigned Webhook → WordPress → result書き戻し、という経路で実行します。通常処理にGitHub Actionsは使用しません。

## 導入

利用者が行う手順は次の6つです。

1. 配布ページからWP Agent BridgeのZIPをダウンロードする。
2. WordPressへZIPをアップロードし、有効化する。
3. WordPressの **ツール > WP Bridge Setup** で **Connect GitHub** を押す。
4. GitHubで接続を許可する。
5. 自動作成されたruntime repositoryをChatGPTのGitHub連携へ追加する。
6. ChatGPTにWordPressの更新を依頼する。

手順3〜4では、専用private repository作成、runtime branch作成、必要ファイルの初期化、WordPressとの紐付けを自動で行います。利用者がrepository、branch、Webhook、secretを手作業で設定することは想定していません。

## 主な用途

- 投稿・固定ページの取得、作成、更新
- 本文の検索・部分編集・一括編集
- HTML tableの行・セル編集
- post meta、taxonomy、menu等の管理
- plugin / themeの管理
- theme fileの編集
- Draft Themeのpreview / publish / rollback
- media upload
- WP-Cron管理
- WordPress REST APIを利用した各種操作

変更操作には、操作内容に応じてpreview、confirm、SHA-256、plan hash、impact hash、stale-write rejection、active theme/plugin protection等のguardを適用します。

任意のshell command、任意のWP-CLI文字列、無制限のSQL writeは公開しません。

## 必要環境

- WordPress 6.9以上
- PHP 7.4以上
- HTTPSで公開され、WordPress REST APIへ到達可能なサイト
- GitHubアカウント
- GitHubを接続できるChatGPT環境

## 配布

公開版は、開発repositoryとは別のcleanなpublic repositoryから配布します。開発用command/result履歴、運営用Onboarding Service、diagnostics、秘密情報、無関係なproject dataは公開版へ含めません。

WordPress.org Plugin Directoryからの配布は予定していません。

## License

無料でダウンロード・インストール・利用できます。個人的・非公開の改変は可能です。

元のソフトウェア、改変版、fork、build等を第三者へ再配布、販売、ミラー配布、第三者向けダウンロードとして提供することは禁止します。詳細は[`LICENSE.md`](LICENSE.md)を参照してください。

このライセンスは独自のsource-available licenseであり、オープンソースライセンスではありません。

## Status

1.0.0公開候補。GitHub onboarding、専用private runtime repository作成、ChatGPTからのcommand投入、signed Webhook relay、WordPress実行、result返却までのend-to-end testを完了しています。
