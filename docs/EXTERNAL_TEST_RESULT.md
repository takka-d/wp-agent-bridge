# WP Agent Bridge 自己完結runtime 外部テスト結果

この記録はテスター本人の確認用です。GitHubユーザー名、WordPress URL、記事本文、command/result全文、token、private key、Webhook secret等をWP Agent Bridge運営者へ提出する必要はありません。

## 環境

- WordPressバージョン:
- PHPバージョン:
- ChatGPT利用環境:

## 1. fresh install

- [ ] テスト対象ZIPを新規インストールできた
- [ ] 有効化できた
- [ ] 既存のWP Agent Bridge / TakKa WordPress Bridgeは入っていなかった

補足:

## 2. user-owned private runtime repository

- [ ] `Tools > WP Agent Bridge`に`Status: Not connected`が表示された
- [ ] 自分自身のGitHub accountにprivate runtime repositoryを作成した
- [ ] operator-owned Organization/repositoryへ参加していない
- [ ] repositoryをpublicにしていない

## 3. site-specific GitHub App

- [ ] `Connect GitHub`からGitHub App manifest画面へ移動した
- [ ] GitHub Appのownerはテスター本人のGitHub accountだった
- [ ] GitHub Appはprivateだった
- [ ] `Only select repositories`を選択した
- [ ] runtime repository 1個だけを選択した
- [ ] PAT / private key / Webhook secret / Bridge Keyを手入力していない

## 4. WordPress接続完了

- [ ] `GitHub direct connection completed.`が表示された
- [ ] `Status: Connected (direct GitHub webhook)`になった
- [ ] Repositoryはテスター本人所有のprivate repoだった
- [ ] Runtime branchは`wp-agent-bridge-runtime`だった

## 5. canonical runtime marker / v0.9.6 guidance

- [ ] `AGENTS.md`を確認できた
- [ ] `wordpress-bridge/RUNTIME_CONNECTION.json`を確認できた
- [ ] `status = canonical`
- [ ] `transport = direct-github-webhook`
- [ ] `ownership = user-owned`
- [ ] `operator_relay = false`
- [ ] markerのrepository名は実際に開いているrepository自身と一致した
- [ ] `AGENTS.md`はChatGPT-local / connector-downloaded mediaにbatched staged-mediaを優先するよう案内した
- [ ] GitHub local-file parameterがなくてもblocker扱いしない案内になっていた
- [ ] `/wp-agent-bridge-media/v1/upload-chunk`はfallbackとして案内された
- [ ] `site.icon.get` / `site.icon.set` / `site.icon.clear` / `media.upload.capabilities`が案内された
- [ ] `theme.files.list` / `theme.files.search` / `theme.file.read.many`が案内された

## 6. ChatGPT GitHub接続

- [ ] テスター本人のGitHub accountをChatGPTへ接続した
- [ ] ChatGPTから自分のruntime repositoryを認識できた
- [ ] operator-owned runtimeを探す必要がなかった

## 7. Bridge E2E

- [ ] `site.info`が成功した
- [ ] `cache.flush`が成功した
- [ ] 記事・設定・テーマ・プラグインを変更せず完了した
- [ ] command/resultは自分のprivate runtime repository内だけに保存された
- [ ] operator-owned relay/repositoryを経由しなかった

補足:

## 8. v0.9.6 Site Icon / media capability E2E

- [ ] `media.upload.capabilities`が成功した
- [ ] `site.icon.get`が成功した
- [ ] generic option patcherでscalar `site_icon`を書き換えようとしなかった
- [ ] current Site Icon attachment IDを取得できた
- [ ] current Site Iconが設定済みだったのでsame-ID `site.icon.set` no-change E2Eを実施した
- [ ] `site.icon.set`は`confirm=true`で実行した
- [ ] `expected_current_id`にread時のcurrent IDを指定した
- [ ] 設定前後のSite Icon attachment IDは同じだった
- [ ] Site Icon未設定のためsame-ID setは該当なし

補足:

## 9. v0.9.6 multi-theme-file inspection

- [ ] `theme.files.list`が成功した
- [ ] `theme.files.search`が成功した
- [ ] `theme.file.read.many`が成功した
- [ ] `read.many`は実在する2ファイル以上を1回のBridge commandで取得した
- [ ] 単一file readの反復へ分解しなかった
- [ ] theme root外を読まなかった
- [ ] WPVibeへ迂回しなかった

補足:

## 10. ChatGPT-local media transport — preferred batched path

- [ ] 約2.4 MiB PNGをChatGPT会話へ添付して使用した
- [ ] GitHub local-file parameterがないことをblocker扱いしなかった
- [ ] 元画像全体のbytes / SHA-256を計算した
- [ ] 元binaryを順序付きbounded chunkへ分割した
- [ ] 各chunkを独立してBase64化した
- [ ] Base64 text payloadを`wordpress-bridge/media/pending/*.b64`へstageした
- [ ] `/wp-agent-bridge-runtime/v1/media-upload` commandは1件だけ作成した
- [ ] commandにordered `data_paths`を指定した
- [ ] commandにwhole-file `expected_bytes` / `expected_sha256`を指定した
- [ ] Git Data操作が利用できる場合、payload群+commandを1つのtree/commit/ref更新でruntime branchへ投入した
- [ ] 別のWordPress連携経路へ迂回しなかった
- [ ] WordPress側のwhole-file bytes / SHA-256検証が成功した
- [ ] WordPress Media Libraryへ登録できた
- [ ] staged payloadが成功後にcleanupされた

Attachment ID(ローカル記録のみ):

## 11. Sequential chunk-command fallback

- [ ] batched staged-mediaが利用できない、または実際に失敗したためfallbackを使用した
- [ ] `/wp-agent-bridge-media/v1/upload-chunk`を使用した
- [ ] whole-file / per-chunk bytes・SHA-256を検証した
- [ ] 各chunk command完了後に次へ進んだ
- [ ] 最終chunkでwhole-file integrity検証に成功した
- [ ] fallbackは不要だった

fallback理由:

## 12. upload-and-Site-Icon 1-command flow (任意)

- [ ] テスト用WordPressでSite Icon変更を許可した
- [ ] media-upload commandに`set_site_icon=true`を指定した
- [ ] `confirm_site_icon=true`を指定した
- [ ] 必要に応じて`expected_site_icon_id`を指定した
- [ ] Media Library登録とSite Icon設定が同じupload resultで成功した
- [ ] 既存運用サイトのため実施せず、same-ID no-change E2Eのみ確認した
- [ ] 該当なし

## 13. retry / pending recovery

自然にpending残留が発生した場合のみ記録する。

- [ ] 次のvalid pushで古いpendingも再走査された
- [ ] 同じrequest_idの副作用は二重実行されなかった
- [ ] result/completed/pending bookkeepingが復旧した
- [ ] 該当なし

## 14. 最終状態

- [ ] **Tools > WP Agent Bridge**で`Status: Connected (direct GitHub webhook)`のまま
- [ ] site-specific GitHub Appはruntime repo 1個だけへアクセス可能
- [ ] operator-owned Organization / relay / runtime repositoryを使用していない
- [ ] 秘密情報を第三者へ共有していない

## 総合結果

- [ ] 成功
- [ ] 失敗
- [ ] 一部成功 / 要確認

失敗または要確認の場合、必要なら**秘密情報・サイト内容・個人情報を除去したエラーコード/症状だけ**を報告する。

- 止まった手順番号:
- エラーコード/症状:
- 直前の操作:
- その他:
