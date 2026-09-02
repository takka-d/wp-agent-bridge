# WP Agent Bridge 1.0.0 RC1 外部テスト結果

テスター名は本名でなくて構いません。secret/token/private key等は絶対に記載しないでください。

## 基本情報

- テスト日時:
- テスター識別名:
- WordPressバージョン:
- PHPバージョン:
- ブラウザ / ChatGPTアプリ:
- WordPressサイトURL(公開して問題ない場合のみ):

## 1. fresh install

- [ ] `wp-agent-bridge-1.0.0.zip` を新規インストールできた
- [ ] 有効化できた
- [ ] 既存のWP Agent Bridge / TakKa WordPress Bridgeは入っていなかった

補足:

## 2. GitHub認可

- [ ] `Tools > WP Bridge Setup` に `Status: Not connected` が表示された
- [ ] `Connect GitHub` を押した
- [ ] GitHub認可画面が表示された
- [ ] 権限表示は `Public read-only` + `repository invitations` 相当だった
- [ ] Organization accessの `Grant` は押していない
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
- 他人のruntime repoが見えてしまった: はい / いいえ / 確認していない

※ 他人のprivate repo名を探す必要はありません。通常操作の範囲で意図せず見えた場合だけ「はい」。

## 5. ChatGPT GitHub接続

- ChatGPTのGitHubはテスト前から接続済みだった: はい / いいえ
- [ ] テスター本人のGitHubアカウントで接続できた
- [ ] ChatGPTから作成されたruntime repositoryを認識できた
- ChatGPTが認識したRepository:

見つかるまでに待ち時間があった場合:

## 6. Bridge E2E

- [ ] `site.info` が成功した
- [ ] `cache.flush` が成功した
- [ ] 記事・設定・テーマ・プラグインを変更せず完了した
- ChatGPTの最終結果:

## 7. 最終状態

- [ ] WordPressの `WP Bridge Setup` は `Status: Connected` のまま
- [ ] GitHub Appの削除・再インストールはしていない
- [ ] Organization側設定は変更していない
- [ ] secret/token/private key等は共有していない

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
