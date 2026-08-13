# Tomos 1ファイルインストーラー製品設計

## 1. 目的

本書は、Issue #46の配置PoCを前提に、Tomosの正式な1ファイルインストーラーを実装する前の製品仕様を確定するための設計書である。今回は `install.php`、Tomos本体、Release Asset、通常配布ZIP、Update仕様を変更しない。

目的は、対応可能な環境では「1ファイルをアップロードして、設置先を選び、ブラウザから導入できる」こと、対応不能な環境では通常のZIPダウンロード＋アップロードへ明確に移行できることである。すべてのサーバーで自動導入できることは目標にしない。

## 2. PoC結果と前提

Issue #46では、A方式（現在のディレクトリへのファイル単位配置）とB方式（子ディレクトリへの完成ディレクトリ移動）の正常系、およびA方式の途中失敗とB方式の移動失敗が実サーバーで確認済み、という前提で進める。PoCのUI、marker、テスト用packageは製品に流用しない。

製品ではPoCに加えて、署名付き取得、ZIP全体の検証、永続journal、強制終了後の復旧、終了処理、通常アップロードへのフォールバックを実装対象とする。Issue #46で成立したA/Bの基本方針は変更しない。

## 3. 全体アーキテクチャ

```text
公式の固定latest pointer URL
        │ HTTPS
        ▼
versioned manifest + signature
        │ pointerが示すversion
        ▼
install.php（診断・UI・取得・検証・配置・復旧）
        │ 署名済みmanifestのasset URL
        ▼
通常配布ZIP（同一ReleaseのTomos本体）
        │ 署名・hash・entry照合
        ▼
hidden作業領域 → A: ファイル単位配置 / B: directory rename
        │
        ├─ 成功: 完了marker、installer無効化、自己削除試行
        └─ 失敗: journal検証、今回作成物だけrollback、通常導線提示
```

installerはGitHub APIを直接の製品契約にしない。固定された公式latest pointer URLからversioned manifestを特定し、manifestが指すasset URLを使用する。pointerは取得先を示すだけで信頼の起点にせず、最終的にversioned manifestの署名を必ず検証する。manifest取得失敗、署名不正、ZIP検証失敗、環境診断NGはいずれもTomos利用不能ではなく、簡単インストール非対応として扱う。

## 4. 現行実装の再確認と再利用方針

確認した現行ファイルは次のとおりである。

| 対象 | 現状 | 製品設計での扱い |
|---|---|---|
| `tools/build-distribution.sh` | `VERSION`から通常ZIPを作り、allowlist、除外物、必須ファイル、secret、ZIPを検査 | 通常ZIP生成の入口として維持。install manifest生成を後段に追加する |
| `tools/required-distribution-files.txt` | 通常配布物の必須ファイル一覧 | 配布物の存在検査に継続利用。署名対象のfile inventoryとは別責務 |
| `core/required-installed-files.txt` | インストール済み必須ファイル一覧 | 初回配置後の診断・自己検査の基礎に継続利用 |
| `tools/build-update-package.php` | Update用 `manifest.json` / `manifest.sig` を生成し、更新ZIP内のhashを再検査 | Update専用。初回ZIPには使わない |
| `core/UpdateService.php` | RSA/SHA-256、生バイト署名検証、許可パス、Zip Slip、symlink、重複、500 entry、展開後100 MiB、staging、lock、backup、rollback | 信頼モデルと検証原則を共有する。Updateのファイルallowlistや既存ファイル保護を初回へコピーしない |
| `update/public-key.pem` | Updateの公開鍵を配布物に含む | 初回も当面同一鍵を利用するが、installer内定数として保持する |

現行UpdateはZIP内の `manifest.json` を `getFromName()` で取得し、取得した生バイト列を署名検証してからJSONとして解釈する。再serializeして署名検証しない点をinstall manifestでも必須とする。ZIP展開は `extractTo()` を使わず、entryごとのstream展開と排他的作成を採用する。

Updateの既存の `content/`、`cache/`、`storage/` 保護、更新対象の許可リスト、既存ファイルのbackup／復元は初回配置の仕様とは異なる。初回は「空の設置先」を前提とし、Aでは衝突を全件事前拒否、Bでは対象子ディレクトリの存在を拒否する。

## 5. 信頼モデル

信頼の起点は、利用者が公式HTTPSサイトから取得したinstallerと、installerに内蔵された公開鍵である。installerは固定の公式manifest URL以外を受け付けず、manifestの署名、assetのサイズとSHA-256、ZIP entryとfile hashを順に確認する。通信路のTLSは改変防止に使うが、取得物の真正性は署名で担保する。

HTTPSの証明書検証を無効化しない。HTTP、任意URL、リダイレクト先の任意ホストは拒否する。公式manifest URLはHTTPSの固定URLとし、manifest内asset URLは同一の公式配布ドメインまたは許可済みGitHub Releaseホストに限定する。

## 6. installer自身の信頼

初回の `install.php` は公式サイトのHTTPSページとGitHub Release Assetの双方から配布可能にする。利用者へ署名操作を要求しない。公式配布ページではinstallerのSHA-256を公開するが、一般利用者の必須手順にはしない。

installer自身をmanifest署名対象にして自己検証する仕組みは、最初の信頼起点を解決しないため採用しない。HTTPS、公式配布経路、公開SHA-256、リリース時のCIハッシュ検査を現実的な初期モデルとする。installerの改ざん対策は、公式配布サイトの管理権限とRelease公開経路の保護に依存する。将来、installer専用署名を導入する場合は別Issueで設計する。

## 7. install-manifest schema

正式名は `install-manifest.json` とする。署名対象はファイルの生バイト列であり、末尾改行を含めて生成物をそのまま署名・検証する。

```json
{
  "schema_version": 1,
  "product": "Tomos",
  "version": "0.1.0-alpha.16",
  "asset": {
    "name": "tomos-0.1.0-alpha.16.zip",
    "size": 123456,
    "sha256": "64文字の小文字hex",
    "url": "https://github.com/tomosweb/tomos/releases/download/v0.1.0-alpha.16/tomos-0.1.0-alpha.16.zip"
  },
  "limits": {
    "max_entries": 500,
    "max_file_bytes": 10485760,
    "max_uncompressed_bytes": 104857600
  },
  "files": {
    "index.php": {"type": "file", "size": 123, "sha256": "64文字の小文字hex"},
    "setup/index.php": {"type": "file", "size": 456, "sha256": "64文字の小文字hex"}
  }
}
```

確定仕様は次のとおりとする。

- `schema_version` は整数で、正式installerが理解する値は `1` だけ。未知値は拒否する。
- `product` は `Tomos` に固定し、`version` は `VERSION` と一致するsemver互換文字列とする。
- `asset.name`、`asset.size`、`asset.sha256`、`asset.url` は必須。sizeは非負整数、hashは小文字64桁hexとする。
- `files` はZIPの全ファイルentryを相対pathのキーで列挙する。各項目は `type=file`、size、sha256を持つ。
- directory entryはmanifestに含めない。ディレクトリはfile pathの親から導出し、ZIPにdirectory entryがあっても許可するが、manifestにない実体ファイルentryは拒否する。
- `.htaccess` を含むhidden fileは通常ファイルとして列挙する。ドット始まりを理由に除外しない。
- 必須ファイルは `tools/required-distribution-files.txt` と `core/required-installed-files.txt` によりbuild／installed検査する。manifest内に別の必須フラグは設けず、manifestは実体の完全なinventoryに限定する。
- JSON objectのキー順は生成toolが固定する。canonicalizationライブラリは導入しない。署名検証はcanonicalize後ではなく、生バイト列に対して行う。
- `asset.url` は署名対象に含める。将来CDNを変更する場合はmanifestを再署名する。
- manifest内のlimitsは表示用ではなく、installerの安全上限を超える値を受け入れない。installer側にもハード上限を持つ。

初回ZIPとmanifestの同一Release性は、manifestがZIP名、size、SHA-256、version、固定asset URLを署名していること、Release工程でZIP生成直後にmanifestを生成すること、公開後に再取得検査することで保証する。

## 8. manifest署名

署名方式は現行Updateと同じRSA / SHA-256、PHPの `openssl_verify()` とする。署名ファイル名は `install-manifest.sig`、形式はOpenSSLのバイナリ署名とする。manifestの生バイト列と署名を読み、公開鍵で検証する。

初期製品では `update/public-key.pem` と同じ鍵を使う。Tomosの配布工程を単純に保ち、Updateと初回で信頼起点を分けないためである。ただし、同一鍵侵害時にUpdateと初回の両方が影響を受けるリスクは残る。鍵分離は将来のrotation設計で再評価する。

1ファイルUXを守るため公開鍵は `install.php` の定数にPEM文字列として内蔵する。外部の公開鍵ファイルを別アップロード必須にはしない。`update/public-key.pem` はTomos本体側のUpdate用として従来どおり配布する。

## 9. 鍵rotation

初期実装では、installerに現行公開鍵を1個だけ内蔵し、鍵交換時は新鍵を内蔵した新しいinstallerを公式配布する。古いinstallerが将来鍵を検証できない問題は、古いinstallerを自動更新しない代わりに、公式サイトの案内と新installerの再取得で解決する。

複数鍵、manifestのkey ID、オンライン鍵取得、PKIチェーンは初期製品には採用しない。鍵rotation時は、旧鍵で署名したmanifestの受け入れ期限、新installerの配布開始、旧installerのサポート終了をRelease checklistで管理する。緊急失効が必要な場合、旧installer単体では救済できないため、公式配布停止と新installerの再配布を行う。

## 10. Release Asset構成と責務分離

初回ReleaseのAssetは次の4つを基本とし、manifestとsignatureはversion別の同一ディレクトリへ公開する。

```text
tomos-x.y.z.zip
install-manifest.json
install-manifest.sig
install.php
```

公開URLの構造は次のようにする。

```text
/download/install/latest.json
/download/install/v0.1.0-alpha.16/install-manifest.json
/download/install/v0.1.0-alpha.16/install-manifest.sig
/download/install/v0.1.0-alpha.16/tomos-0.1.0-alpha.16.zip
```

責務は明確に分ける。

- 通常ZIP: 初回のTomos本体。通常アップロードにも使う。
- install manifest／signature: 通常ZIPのversion、size、全file inventoryとhashを署名する。
- `install.php`: 取得、検証、A/B配置、復旧、fallbackを実行する。Tomos本体には含めない。
- `tomos-update-x.y.z.zip`: 既存Tomos向けUpdate専用。初回インストールには利用しない。
- Updateの `manifest.json` / `manifest.sig`: Update ZIP専用。install manifestと混同しない。

install manifestとsignatureは同一version directoryへ先に公開する。installerはlatest pointerを読み、pointerが示すversion directoryのmanifestとsignatureを取得するため、利用者にversionやURLを入力させない。Update ZIPとUpdate manifest／signatureはこの構造に混在させない。

## 11. 安定した配布物取得URL

推奨は「公式サイト上の固定HTTPS URL（例: `/download/install/latest.json`）を取得し、そこに記載されたversioned manifestを取得して署名検証し、署名済みasset URLへ進む」方式である。latest pointerはJSONを採用する。

```json
{"schema_version":1,"version":"0.1.0-alpha.16","manifest_url":"https://tomos.example/download/install/v0.1.0-alpha.16/install-manifest.json","signature_url":"https://tomos.example/download/install/v0.1.0-alpha.16/install-manifest.sig"}
```

pointerには任意のZIP URLを持たせず、versionと公式versioned manifest／signature URLだけを持たせる。pointer自体の改ざんで任意ZIPを直接インストールできないよう、manifestの署名、manifest内のasset host、ZIP size／SHA-256を必ず検証する。pointerのJSONもschema、HTTPS、公式host、version pathの一致を確認するが、pointerに対する署名は初期版では導入しない。

| 案 | 評価 | 採否 |
|---|---|---|
| GitHub Latest API | API仕様、rate limit、private/public、認証、JSON変更に依存。installerがGitHubに強く結合 | 不採用 |
| GitHub固定Release URL | GitHub CDNとredirectの扱いは必要だがAPI不要。公式サイト移行時にinstaller変更が必要 | 取得先として許可 |
| 公式固定manifest URLを直接差し替え | URLは単純だが、manifestとsignatureの別更新で一時不整合が起きる | 不採用 |
| versioned manifest + JSON latest pointer | versioned 3点を先に検証可能な状態で公開し、最後にpointerだけ切り替えられる | 採用 |

latest pointer、versioned manifest、signatureはHTTPSで取得し、redirectは同一公式ホストまたは事前に許可した配布ホストに限定する。asset URLのredirectは、GitHub Release CDNの既知挙動を許可するかを実装時に確定する。未確認のホストへ無制限に追従しない。

固定latest pointerを更新するRelease手順は、versioned ZIP公開 → versioned manifest公開 → versioned signature公開 → 3点の公開URLから再取得・署名／hash検証 → 最後にlatest pointerをatomicに切替、の順とする。pointer切替前にどれか1点が取得不能なら公開を中止する。配布基盤がこの順序を保証できない場合は、pointer更新を行わずReleaseを未公開とする。

## 12. downloader仕様

優先順位はcURL、次に `allow_url_fopen` である。どちらも使えない場合は自動インストールを利用不可にし、通常ZIP導線を表示する。

- response bodyはmemoryに全保持せず、hidden作業領域の一時fileへstream書込みする。
- connect timeoutは10秒、総合timeoutは120秒を初期値とし、実装時に一般的なshared hostingで確認する。
- redirectは最大3回、HTTPSのみ、許可ホストのみ。証明書検証とpeer name検証は有効化する。
- HTTP statusは2xxのみ許可する。Content-Lengthがあればmanifestのsizeと一致させ、最終file sizeとSHA-256も一致させる。
- 最大download sizeはmanifest limitsではなくinstallerのハード上限（初期50 MiB）で先に制限する。size超過、切断、disk不足はtempを削除する。
- `Content-Length` がない場合もstreamしながら上限を超えた時点で中止する。
- asset取得後に同じZIPを二度読みしてhashを検査し、検査失敗時は配置に進まない。

## 13. 環境診断

| 項目 | 分類 | 判定 |
|---|---|---|
| PHP最低version（Tomosの現行要件） | 必須 | NGなら簡単インストール不可 |
| OpenSSL / `openssl_verify` | 必須 | 署名検証不能なら不可 |
| `ZipArchive` | 必須 | 安全なentry処理不能なら不可 |
| cURLまたは `allow_url_fopen` | 必須 | 外部取得不能なら不可 |
| HTTPS | 必須 | installer自体がHTTPなら不可、HTTP通信も不可 |
| 対象ディレクトリ書込み | 必須 | A/B双方で確認 |
| hidden作業領域作成 | 必須 | 作成不能なら不可 |
| temp file作成 | 必須 | 作成不能なら不可 |
| lock | 必須 | `flock`／lock fileが使えなければ不可 |
| session | 必須 | CSRFと二重POST防止に必要 |
| `disk_free_space()` | 推奨 | 取得不能だけでは不可にせず、安全側の固定上限で継続 |
| memory_limit | 情報表示 | streaming前提。極端に小さい場合だけ診断メッセージ |
| 既存Tomos／衝突 | 必須 | 実行開始前に設置方式別に拒否 |
| 外部HTTPS疎通 | 必須 | 診断時のHEAD依存は避け、実取得時に最終確認 |
| B方式のdirectory rename | 必須（B選択時） | 事前probeまたは実行時に失敗したらBのみfallback |

診断NG画面は「このサーバーでは、かんたんインストールを利用できません。Tomosは通常のファイルアップロードで設置できます。」とする。内部理由は安全な診断コードで示し、OpenSSL等の技術語は一般画面の主文に出さない。

## 14. 設置先UI

Issue #46のUIを製品仕様として採用する。初期選択は「この場所に設置」。Bを選んだときだけ子ディレクトリ名入力を表示する。絶対パス、URL、host、任意の取得先入力欄は設けない。

子名はASCII英数字で開始し、ASCII英数字・`-`・`_`のみ、1〜64文字とする。`.`、`..`、空文字、制御文字、slash、backslash、colon、NUL、先頭dotは拒否する。日本語名は初期製品では許可しない。既存ディレクトリ、symlink、既存fileは拒否し、Bの自動fallbackは行わない。

成功画面のURLは、現在ディレクトリまたは入力された子名から、host headerではなく固定された相対URLを基に表示する。httpsの絶対URLを生成する必要がある場合は、公式設定またはrequest schemeの安全なallowlistを使い、任意Hostを信頼しない。

## 15. A方式「この場所に設置」

配置前にZIP全体を検証し、staging内で全ファイルの完成状態、hash、必須file、PHP構文の最低限検査を完了する。対象rootのトップレベルと配置予定全pathについて、file、directory、symlinkの衝突を全件検査する。1件でも衝突があれば配置を開始しない。

配置順序は次のとおりとする。

1. `core/`、`setup/`、`themes/`等の内部fileとその親directory
2. その他の非公開・補助file
3. `.htaccess`
4. `index.php`

`index.php`を最後にすることで、公開入口が成立する前に検証済み内部fileを揃える。`.htaccess`は公開入口より先に配置する。先に配置しても入口がない間は公開動作を開始せず、最後の `index.php` 配置時点でアクセス制御が存在するためである。ただしApache以外の挙動を保証しないため、製品UIでは配置中アクセスを要求しない。

各fileは既存targetを上書きしない排他的作成で書く。1fileごとにjournalを更新し、公開入口配置後にinstalled markerをatomicに書く。marker書込みに失敗した場合も成功扱いにしない。

## 16. B方式「新しいフォルダに設置」

child名を検証し、targetが存在しないこと、symlinkでないこと、親が書込み可能であることを確認する。stagingは必ず設置先child directoryと同じ親ディレクトリ配下のhidden領域に作成する。たとえば次の構造である。

```text
/public_html/
    install.php
    .tomos-installer/
        staging-<random>/
    blog/   ← rename後のtarget
```

OSのsystem temp directoryなど、別filesystemとなる可能性がある場所にはB方式のstagingを作成しない。この位置固定で同一filesystemである可能性を最大化し、完成状態を作ってから同一filesystem上の `rename(staging, target)` を実行する。事前に複雑なfilesystem判定を行うのではなく、targetと同じ親配下で作成し、最終renameの成功をもって確定する。

実装時にcross-filesystem renameを自動copy処理へ置き換えない。戻り値、error、source／destination存在状態を内部ログに記録し、成功後にtargetの必須fileとhashを再確認する。

directory renameが使えない場合は、A方式への自動fallbackをしない。A方式は「現在の場所」に対する全衝突確認と復旧を前提にした別モードであり、Bの失敗時に混在させると不完全な子directoryを残す危険がある。Bだけを利用不可として通常アップロードを案内する。

## 17. 永続的 transaction journal

journalは設置root直下の、公開拒否設定済みhidden領域（例 `.tomos-installer/transactions/<id>/journal.json`）に保存する。staging内だけに置かない。強制終了後も次回起動で検出できる必要がある。領域にはdeny設定を置くが、Webサーバーに依存せず、秘密情報を記録しない。

```json
{
  "schema_version": 1,
  "transaction_id": "128bit hex",
  "installer_version": "1.0.0",
  "mode": "current",
  "state": "placing",
  "root_fingerprint": "sha256 of canonical root identity",
  "created_files": ["core/example.php"],
  "created_directories": ["core"],
  "started_at": "2026-08-13T00:00:00Z"
}
```

journalはJSONを一時fileへ書き、`LOCK_EX`でflush後、同一directory内のrenameで更新する。fileを1つ作成するたび、directoryを作成するたびに更新する。完了時は `state=complete` をatomicに記録してからmarkerを作り、最後にjournalを削除または完了履歴へ移す。

各created entryには相対path、作成前不存在確認の結果、配置後のSHA-256、transaction IDを記録する。journalが壊れている、root fingerprintが違う、pathが安全でない、targetがsymlink、記録時hashと現物hashが違う場合は自動削除しない。安全に停止し、通常アップロードと管理者確認へ案内する。

B方式では完成stagingからrenameするため、配置中のcreated file listは不要である。rename前のjournalは `state=ready_to_move`、成功後は `state=moved` とし、targetの再検証後に完了markerを作る。

## 18. 強制終了からの復旧

次回起動時に未完了journalを検出したら、同一lockを取得して復旧する。初回インストールはresumeではなくcleanupして最初からやり直す。

1. journalのJSON schema、transaction ID、root fingerprint、相対path、stateを検証する。
2. 各 `created_files` が現在もfileであり、記録された作成後hashと一致し、既存fileの復元対象でないことを確認する。
3. 親directoryが空で、journalの `created_directories` に含まれるものだけを逆順で削除する。
4. stagingを安全なtree削除で消し、journalを完了扱いにして削除する。
5. どれか1つでも条件を満たさない場合は削除を中止し、管理者確認を要求する。

「前回のインストールは完了しませんでした。安全に初期化しました。もう一度実行できます。」を表示する。journalだけを信用して既存fileを削除しない。A方式の配置開始前の衝突確認と排他的作成により、既存fileをjournalのcreated listへ入れないことが安全条件である。

## 19. ZIP検証・安全展開

`ZipArchive::extractTo()` は使わず、`statIndex()`で全entryを列挙し、必要なfileを `getStream()` から排他的fileへstream展開する。次を拒否する。

- `../`、`.`、空component、absolute path、先頭slash
- backslash、Windows drive path、NUL、制御文字、invalid UTF-8
- symlink、暗号化entry、許可しないcompression方式
- 重複entry、manifest外のfile entry、manifestにない必須file
- file count 500超、file 10 MiB超、総展開100 MiB超（最終値は実装時にReleaseサイズを確認）

directory entryは許可するが、正規化したpathがfile entryと衝突しないことを確認する。entry名はmanifestのキーと同一の正規形で比較し、大小文字を勝手に同一視しない。展開後に各fileの実サイズとSHA-256を再計算する。圧縮bombは圧縮後sizeではなく展開後size、entry数、stream中の実byte数で制限する。

## 20. 取得後の検証順序

1. installer環境診断とlock取得
2. 固定manifest URLからHTTPSで `install-manifest.json` を取得
3. `install-manifest.sig` を取得
4. manifest生バイトをRSA/SHA-256検証
5. JSON schema、version、asset URL host、limits、全pathを検証
6. assetをtemp fileへstream取得（HTTPS、status、redirect、size上限）
7. manifest記載ZIP sizeと実sizeを比較
8. ZIP全体のSHA-256を比較
9. ZIPを開き、entry数、path、symlink、duplicate、圧縮方式、展開容量を検証
10. manifestの全fileとZIP file entryの完全一致を検証
11. stagingへentryごとにstream展開
12. 展開後size／SHA-256、`VERSION`、required filesを検証
13. 設置先の既存Tomos、衝突、子directory、書込みを再確認
14. AまたはBの配置を実行
15. 完了後のinstalled marker、必須file、公開入口を確認
16. journal完了、temp／staging cleanup、installer無効化・自己削除試行

署名不正のmanifestからasset URLを信頼しない。manifest取得とsignature取得の不一致、size/hash不一致は全て配置前に中止する。

## 21. installer安全性

sessionを開始し、POSTごとにCSRF tokenを `hash_equals()` で検証する。lockは設置rootに対して排他的に取得し、二重POST、同時実行、完了marker存在を拒否する。完了markerは署名済みversion、transaction ID、作成時刻を含み、再実行時はHTTP 409相当の無効画面を表示する。

入力はmodeと子directory名だけとする。任意URL、任意filesystem path、任意version、任意hostは受け付けない。Host headerを信頼してリンクや取得先を生成しない。PHP warning／exception／absolute pathを一般画面へ表示せず、内部ログにはphaseと安全なerror codeだけを残す。

### 第三者による直接実行

CSRFは、正規利用者のブラウザを第三者サイトから操作させる攻撃への対策であり、第三者が `install.php` を直接開いて自分のsessionとCSRF tokenを取得することは防がない。lockは同時実行防止であり、認証ではない。したがって、CSRFとlockを第三者実行防止策として扱わない。

installer URLが第三者に知られた場合、正規利用者より先に実行される可能性がある。特にTomos未設置の空directoryでは、第三者が初回インストールを成立させ得る。短時間運用、完了後無効化、自己削除試行、公式配布URL限定だけでは、この直接実行リスクを十分に解決したとはみなさない。

初期製品ではアカウント登録、メール認証、外部認証サービス、複雑なinstaller用ユーザー管理は導入しない。Phase 2開始前またはPhase 2内で、次の軽量案を比較して採用方式を必ず確定する。

| 案 | 内容 | 限界 |
|---|---|---|
| A: 初回アクセスsession所有 | 最初にGETしたsessionをbootstrap ownerとして短時間保持し、別sessionのPOSTを拒否。期限、cookie、ブラウザ閉鎖後の再取得を定義 | 第三者が最初にGETした場合は防げない |
| B: installer内蔵bootstrap token | 配布時または生成時のrandom tokenをinstaller内へ保持し、画面またはURLの一部で利用。長いtoken手入力は要求しない | 配布物共有・URL漏えい時の扱いを要検討 |
| C: 簡易初回所有確認 | 利用者だけが知り得る情報や一時markerを使う。FTP操作を追加する案は避ける | shared hostingで共通に使える確認方法が未確認 |

Phase 2では、bootstrap状態、有効期限、session cookie、別session POST拒否、ブラウザ閉鎖後の再取得、先行第三者アクセス時の限界をテストする。採用案が第三者の先行アクセスを完全に防げない場合は、その限界を利用者導線と運用条件へ明記し、設置前にinstallerを短時間だけ配置する運用を要求する。

## 22. installer終了処理

採用案はC「成功後に自己削除を試み、失敗しても恒久的に無効化」である。

- Tomos本体の配置と完了markerを確定する。
- installer自身を無効化するmarkerを、installerと同じ階層にatomicに作る。
- Unix系ではshutdown処理または完了応答後のunlinkを試みる。Windowsやshared hostingでは自己削除失敗を想定する。
- 自己削除できなくても、無効化markerとTomos存在検出により再実行を拒否する。
- markerがある再アクセスはHTTP 410または409相当とし、「このinstallerは無効です。削除できる場合は `install.php` を削除してください。」と表示する。

手動削除を成功条件にはしないが、自己削除不能時は安全のため利用者へ削除を推奨する。失敗時にinstallerを自動で上書き更新する処理は導入しない。

## 23. ログ設計

一般画面には次のような抽象メッセージだけを表示する。

- 「Tomosを取得できませんでした。通常のファイルアップロードをご利用ください。」
- 「このサーバーでは、かんたんインストールを利用できません。」
- 「インストールを完了できませんでした。診断コードを添えてサポートへご相談ください。」

内部ログ／診断コピーには、phase、internal error code、UTC timestamp、installer version、PHP version、HTTP status、exception category、transaction IDの短縮値を含める。session ID、CSRF token、cookie、credential、秘密鍵、過度に詳細なabsolute pathは記録しない。利用者がコピーできる形式は `TOMOS-INSTALL code=... phase=... time=...` の1行とする。

## 24. ユーザー導線

初期画面は次の内容とする。

```text
Tomos かんたんインストール
この場所にTomosを設置しますか？
○ この場所に設置
○ 新しいフォルダに設置
[Tomosをインストール]
```

実行中は「Tomosを準備しています。この画面を閉じずにお待ちください。」と表示する。長時間処理は同期POSTを初期案とするが、shared hostingのtimeoutを超える場合は実装時に分割処理の要否を確認する。利用者へOpenSSL、ZipArchive、rename、staging、RSAを基本画面で表示しない。

成功時は「Tomosのインストールが完了しました。[Tomosをはじめる]」。非対応時は「このサーバーでは、かんたんインストールを利用できません。Tomosは通常のファイルアップロードで設置できます。[設置方法を見る]」とする。

## 25. 通常アップロードへのフォールバック

自動導入不可、Bだけ不可、通信失敗、署名／ZIP検証失敗、timeout、書込み不可のいずれでも、エラーだけで終了しない。公式の設置手順へ次を案内する。

1. 通常配布ZIPをダウンロード
2. PCで解凍
3. FTP/SFTPまたはサーバーのファイル管理機能でアップロード
4. `setup/` をブラウザで開く

installer内には特定ホスティングの操作手順を持たせず、安定した公式docs URLへのリンクだけを置く。通常ZIPのURLはinstaller内に固定せず、署名済みmanifestまたは公式手順から案内する。

## 26. Release工程

既存工程への最小追加として、次をRelease checklistにする。

1. `VERSION`確認
2. `bash tools/build-distribution.sh`
3. `unzip -t`とsource／build／ZIPのpath・byte比較
4. 通常ZIPを開き、全file inventory、size、SHA-256を生成
5. versioned `install-manifest.json`を固定JSON出力
6. 既存の秘密鍵をproject外から読み、versioned manifestへRSA/SHA-256署名
7. versioned manifest、signature、ZIP、VERSION、required fileの相互一致検査
8. Update ZIPを既存 `tools/build-update-package.php` で生成・検証
9. installer test vectorとローカル統合テスト
10. versioned ZIP、manifest、signatureを公開
11. 公開versioned URLから3点を再取得し、size／SHA-256／署名を再確認
12. 検査済みversionを指すlatest pointerを最後にatomic切替
13. latest pointer経由でinstallerが同じversioned manifestへ到達できることを確認

秘密鍵をsource、README、tests、GitHubへ置かない。通常ZIPとUpdate ZIPの生成責務は混ぜない。

## 27. test vector

製品実装前に固定fixtureをリポジトリへ保持する価値がある。候補配置は `tools/installer/test-vectors/` とし、秘密鍵は含めず、公開鍵・manifest・signature・小さなZIP・期待error codeだけを置く。

必須vectorは次のとおりとする。

| vector | 期待 |
|---|---|
| 正常manifest／署名／ZIP | accept |
| signature改変 | reject: signature |
| ZIP SHA-256改変 | reject: asset_hash |
| file hash改変 | reject: file_hash |
| manifest外entry／missing entry | reject: zip_contents |
| duplicate entry | reject: zip_path |
| symlink | reject: zip_path |
| traversal、absolute、drive、NUL | reject: zip_path |
| oversized file／総容量／entry数 | reject: zip_limits |
| unknown schema | reject: schema |
| VERSION不一致 | reject: version |
| truncated ZIP | reject: zip_openまたはzip_contents |

## 28. テスト計画

### 自動テスト

manifest parser、署名生byte検証、schema拒否、asset URL／size／hash、downloader上限、path validation、ZIP safety、entry照合、journalのatomic更新、rollback、child名、衝突、lock、CSRF、二重POST、installer disableをテストする。

### ローカル統合テスト

test Release Asset相当をHTTP fixtureまたはローカルHTTPS相当で取得し、manifest検証、ZIP検証、stream展開、A/B配置、強制終了後のjournal復旧、通常fallbackまで確認する。実HTTPS証明書検証、shared hosting固有のtimeout、renameのfilesystem差はローカル成功だけで完了扱いにしない。

### 実サーバー

Issue #46で配置方式の基本PoCは完了済みとし、製品実装後はPHP最低version、OpenSSL／ZipArchive、cURL／fallback、HTTPS証明書、書込み、disk不足、Bのrename、lock競合、強制終了復旧、自己削除不能時の無効化、公開URLからの再取得だけを最小確認する。全hosting網羅は要求しない。

## 29. 実装対象ファイル

現時点では変更しない。製品実装時の候補を次に列挙する。

新規:

- `install.php`（配布用単一ファイル。最終的なbuild生成方法は実装時確定）
- `tools/build-install-manifest.php`
- `tools/installer/test-vectors/` 配下
- `tests/installer_*.php`
- `docs/user/installation.md` または既存設置手順の適切な場所

変更候補:

- `tools/build-distribution.sh`（manifest生成の呼出しまたは検査のみ）
- `tools/required-distribution-files.txt`（通常ZIPの既存責務内で必要な場合のみ）
- Release／CI workflow（秘密鍵をログへ出さない署名・再取得検査）
- 公式配布手順（Release工程が承認された後のみ）

`core/UpdateService.php`、`tools/build-update-package.php`、Update ZIP仕様は、初回installerのために変更しない。共通化が必要な場合はまず純粋な検証ライブラリの設計Issueを分ける。

## 30. 実装Phase案

1. **Phase 1: manifestとRelease tooling** — schema、versioned manifest／signature、JSON latest pointer、Release切替手順、生成、署名、test vector、公開後再取得検査。製品installerはまだ配布しない。
2. **Phase 2: standalone installer core** — 固定latest pointer取得、downloader、署名／ZIP検証、stream展開、診断、bootstrap、第三者直接実行対策、sessionとCSRFの責務分離。
3. **Phase 3: placement and recovery** — A/B、衝突、lock、永続journal、rollback、強制終了復旧、B方式の同一親配下staging、rename前提、cross-filesystem fallback禁止。
4. **Phase 4: UX and termination** — UI、fallback、完了marker、無効化、自己削除試行、ログ。
5. **Phase 5: Release integration** — CI、Asset公開、公式手順、限定実サーバー確認。

各Phaseを個別Issue／PRにし、Phase 5の公開承認までは通常配布方式を変更しない。

## 31. 未解決事項

- Tomosの正式installer最低PHP versionと、現行Update要件との差分は実装時にVERSION／対応表で確定する。
- shared hostingでのHTTP 120秒、最大ZIPサイズ、`rename()`、自己削除、session cookieの実測は未確認。
- 固定manifest URLの実ドメイン、GitHub Release CDN redirect許可host、cache制御は公式配布環境の決定後に確定する。
- latest pointerをJSONで運用する配布基盤のatomic切替、cache purge、versioned 3点の公開順序は未確認。
- install manifestとUpdate manifestの検証コードを共通化する最小境界は、コード重複より安全性を優先して実装時に確認する。
- installer自身の署名を将来導入するか、同一鍵のrotation運用をどのRelease権限で行うかは未確認。
- 長時間処理がshared hostingのtimeoutを超える場合の分割UIは、Phase 2の実測後に判断する。
- 第三者によるinstaller直接実行への軽量な防止・所有確認方式は、Phase 2開始前またはPhase 2内で必ず確定する。
- B方式のstagingを設置先と同一filesystemとなる位置へ作成できることを、代表shared hostingで確認する。

## 製品実装へ進む最終推奨

### 製品実装判断

**条件付きGo**

### Go条件

- Issue #46のA/B成功・失敗結果を検証記録として確定する。
- 固定manifest URL、Release Assetの公式host、redirect許可範囲を決める。
- 第三者によるinstaller直接実行への軽量な防止・所有確認方式をPhase 2までに確定する。CSRFとlockを認証の代用にしない。
- manifest／signature更新時に不整合時間を作らないversioned公開とlatest pointer切替方式を確定する。
- B方式stagingを設置先と同じ親ディレクトリ配下、かつ同一filesystemとなる位置へ作成する。
- manifest生成・署名・公開後再取得検査を先に実装し、test vectorを通す。
- PHP／ZipArchive／OpenSSL／cURL／shared hostingのtimeout、B rename、自己削除、journal復旧を代表実サーバーで確認する。
- installer自身の配布経路と、同一公開鍵のrotation運用責任者を決める。

### 製品版で必須とするもの

- 署名済みmanifestの生バイト検証
- 通常ZIPのsize、SHA-256、全entry、全file hash照合
- Zip Slip、absolute／drive／NUL、symlink、duplicate、容量・件数制限
- A方式の全衝突事前検査、公開入口最後の配置、永続journal、逆順rollback
- B方式の完成directory rename、失敗時cleanup、A方式への自動fallbackなし
- CSRF、lock、二重POST防止、完了marker、既存Tomos検出
- 自動導入不可時の通常ZIP＋ファイルアップロード導線
- 成功後の自己削除試行と、失敗時も再実行を拒否する恒久無効化
- 利用者表示と内部診断ログの分離

### 製品版では採用しないもの

- GitHub Latest APIへの直接依存
- 利用者にversion、URL、絶対path、CLI、SSH、Composer、Git、署名操作を要求するUI
- B失敗時の複雑なA方式自動fallback
- 初期段階のオンライン鍵取得、PKIチェーン、複数鍵の動的更新
- Update ZIPを初回installへ流用する設計
- `extractTo()`、SSL検証無効化、manifest外entryの黙認、既存file上書き
- installerへアカウント登録、メール認証、hosting別操作手順を組み込むこと

### 次Issue案

- **Issue A:** install-manifest schema／生成／RSA署名／test vector
- **Issue B:** standalone installerの取得・診断・安全ZIP検証
- **Issue C:** A/B配置、永続journal、強制終了復旧、rollback
- **Issue D:** UI、fallback、完了marker、自己無効化・自己削除
- **Issue E:** Release／CI統合、公開Asset再取得検査、代表実サーバー検証

この文書は設計確定の成果物であり、上記Issueの承認前に正式 `install.php` やRelease Assetを作成しない。
