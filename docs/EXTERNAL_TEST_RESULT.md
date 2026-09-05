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

## 5. canonical runtime marker / media routing

- [ ] `AGENTS.md`を確認できた
- [ ] `wordpress-bridge/RUNTIME_CONNECTION.json`を確認できた
- [ ] `status = canonical`
- [ ] `transport = direct-github-webhook`
- [ ] `ownership = user-owned`
- [ ] `operator_relay = false`
- [ ] markerのrepository名は実際に開いているrepository自身と一致した
- [ ] `AGENTS.md`はChatGPT-local / conversation-uploaded fileに`/wp-agent-bridge-media/v1/upload-chunk`を優先するよう案内した

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

## 8. ChatGPT-local media transport

- [ ] 約2.4 MiB PNGをChatGPT会話へ添付して使用した
- [ ] ChatGPT-local sourceを`wordpress-bridge/media/pending/*.b64`へ転写しようとして停止しなかった
- [ ] `/wp-agent-bridge-media/v1/upload-chunk`を使用した
- [ ] 元画像全体のbytes / SHA-256を計算した
- [ ] binaryを順序付きchunkへ分割した
- [ ] 各chunkのbytes / SHA-256を検証した
- [ ] 各chunkだけをBase64化してnormal runtime commandとして順番に送った
- [ ] 各chunk command完了を確認してから次へ進んだ
- [ ] 別のWordPress連携経路へ迂回しなかった
- [ ] 最終chunkで元画像全体のbytes / SHA-256検証が成功した
- [ ] WordPress Media Libraryへ登録できた
- [ ] WordPress側の一時chunk stagingが成功後にcleanupされた

Attachment ID(ローカル記録のみ):

## 9. GitHub-staged media transport（任意）

- [ ] 該当sourceが最初からGitHub connectorでmanageableだった
- [ ] `wordpress-bridge/media/pending/*.b64`を使用した
- [ ] 元binaryを先に分割し、各chunkを独立Base64化した
- [ ] staged blobを検証した
- [ ] Git Data操作を利用できる場合、payload群とupload commandを1つのtree/commit/ref更新でruntime branchへ投入した
- [ ] 元画像全体のbytes / SHA-256検証が成功した
- [ ] 成功後に一時`.b64` payloadが1つのcleanup commitでまとめて削除された
- [ ] 該当なし

## 10. retry / pending recovery

自然にpending残留が発生した場合のみ記録する。

- [ ] 次のvalid pushで古いpendingも再走査された
- [ ] 同じrequest_idの副作用は二重実行されなかった
- [ ] result/completed/pending bookkeepingが復旧した
- [ ] 該当なし

## 11. 最終状態

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