# WP Agent Bridge 1.0.1 外部テスト結果

テスター名は本名でなくて構いません。秘密情報は記載しないでください。

## 基本情報

- テスト日時:
- テスター識別名:
- WordPressバージョン:
- PHPバージョン:
- ブラウザ / ChatGPTアプリ:
- WordPressサイトURL(公開して問題ない場合のみ):

## 1. fresh install

- [ ] `wp-agent-bridge-1.0.1.zip` を新規インストールできた
- [ ] 有効化できた
- [ ] 既存のWP Agent Bridge / TakKa WordPress Bridgeは入っていなかった

補足:

## 2. GitHub認可

- [ ] `Tools > WP Bridge Setup` に `Status: Not connected` が表示された
- [ ] `Connect GitHub` を押した
- [ ] GitHub認可画面が表示された
- [ ] Organization全体の管理権限を追加していない
- Repository invitationの手動Accept: 必要だった / 不要だった / 不明

認可画面や遷移で気づいた点:

## 3. WordPress接続完了

- [ ] `GitHub connection completed.` が表示された
- [ ] `Status: Connected` になった
- Repository:
- Runtime branch:
- Installation ID(表示された場合):

## 4. GitHub repositoryアクセス

- [ ] 自分用runtime repositoryをGitHubで開けた
- [ ] `AGENTS.md` を確認できた
- [ ] `wordpress-bridge/` を確認できた
- [ ] Organization管理権限は必要なかった

## 5. ChatGPT GitHub接続

- ChatGPTのGitHubはテスト前から接続済みだった: はい / いいえ
- [ ] テスター本人のGitHubアカウントで接続できた
- [ ] ChatGPTから作成されたruntime repositoryを認識できた
- ChatGPTが認識したRepository:

## 6. Bridge E2E

- [ ] `site.info` が成功した
- [ ] `cache.flush` が成功した
- [ ] 記事・設定・テーマ・プラグインを変更せず完了した
- ChatGPTの最終結果:

## 7. chunked media transport

- [ ] `03_画像転送テスト用_約2.4MB.png` を使用した
- [ ] 画像全体を1つのcommand JSONへ直埋めせずchunk分割した
- [ ] 別のWordPress連携経路へ迂回しなかった
- [ ] 各chunkのbytes / SHA-256検証が成功した
- [ ] 元画像全体のbytes / SHA-256検証が成功した
- [ ] WordPressメディアライブラリへ登録できた
- Attachment ID:
- Attachment URL:
- 元ファイルbytes:
- 元ファイルSHA-256:
- Chunk数:

## 8. 最終状態

- [ ] WordPressの `WP Bridge Setup` は `Status: Connected` のまま
- [ ] GitHub Appの削除・再インストールはしていない
- [ ] Organization側設定は変更していない
- [ ] 秘密情報を共有していない

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
