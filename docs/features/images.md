# 画像

Tomos は Markdown 本文内の通常画像記法を、安全な `<img>` HTML に変換します。

## 通常Markdown画像

```markdown
![説明](images/photo.jpg)
```

相対パスは、Markdownファイルの場所を基準に解決します。

## ルート基準

```markdown
![説明](/images/photo.jpg)
```

先頭が `/` の画像パスは、`content/` 直下基準として扱います。

## Obsidian形式画像

Tomos は Obsidian 形式の画像埋め込みにも対応します。

```markdown
![[images/sample.png]]
![[images/sample.png|代替テキスト]]
```

`![[...]]` は Wikiリンクではなく、画像埋め込みとして処理します。そのため、処理順としては Obsidian形式画像を Wikiリンクより先に解釈します。

```markdown
![[images/sample.png|Obsidian画像]]
```

この場合は以下のように解釈します。

```text
target: images/sample.png
alt: Obsidian画像
```

## 画像の配置場所

画像は `content/` 配下に置きます。

```text
content/images/photo.jpg
content/diary/images/photo.jpg
```

## 許可形式

初期設定で画像として表示できる形式は以下です。

- jpg
- jpeg
- png
- gif
- webp

Tomos Postから投稿する画像は、元画像の内容から決まる `tms-...` 形式のファイル名で保存されます。画像は1点ずつ一時領域へ送信し、公開先の1回の受信上限より大きい画像は複数チャンクに分けて復元します。全点が揃った後に公開先で加工します。GDが使えればJPEG、PNG、WebPを長辺2048px以内へ調整します。JPEGはEXIFの向き情報を可能な範囲で反映し、JPEG品質82、WebP品質82、PNG圧縮6で再保存します。PNGの透明部分は維持します。GIFはアニメーション維持のため加工しません。

JPEGのEXIF Orientationは1〜8に対応し、`Orientation` と `IFD0.Orientation` の両方を確認します。EXIF機能が使えず向きを自動調整できない場合は、投稿結果に警告を表示し、開発者向けにPHPエラーログへ記録します。

GDが使えない場合、Tomos Postは警告を表示し、対応形式の画像を元のまま保存します。公開HTMLのローカル画像URLには更新識別子を付けるため、同じ管理名の画像を縮小版へ置き換えた場合も古いキャッシュを参照し続けません。

同じ元画像を再投稿した場合、現在の加工結果と既存の公開画像が同じであれば再利用します。未加工画像や過去の加工結果が同じ管理名で残っている場合は、現在の加工結果で安全に置き換えます。公開HTMLの更新識別子も変わるため、古いブラウザキャッシュを参照し続けません。

Markdownの更新や取り下げで使われなくなった管理画像は、公開中の全Markdownを再確認してから削除します。権限などの理由で削除できなかった場合は補助データへ記録し、次回の投稿・更新・取り下げ時に再確認して削除を試みます。再試行時に別のMarkdownから参照されていれば削除しません。

画像参照索引は公開中のMarkdownとTomos管理画像の対応を `cache/index/image-references.json` に記録します。索引が存在しない旧環境や索引を削除した環境でも、公開中の全Markdownから再生成できます。外部URL画像は管理対象へ含めず、存在しない管理画像への参照は索引の再生成時に確認できます。索引の生成や再生成によってMarkdown本文や公開画像は変更しません。

## SVGについて

SVGは初期設定では許可しません。スクリプトや外部参照を含められるため、初心者向けの標準設定では無効です。

## 外部画像URLについて

通常Markdown画像では、`http://` または `https://` で始まる外部画像URLも使えます。

```markdown
![外部](https://example.com/photo.jpg)
```

外部画像URLはサーバー内のファイル存在確認を行わず、そのURLを `src` にした `<img>` として表示します。

外部画像でも、使える形式は `jpg`, `jpeg`, `png`, `gif`, `webp` です。`javascript:`, `data:text/html`, `file:`, `//example.com` のようなURLは画像として表示しません。

将来的には、外部画像を許可するホストを設定で制御する可能性があります。

## 存在しない画像

存在しないローカル画像や危険なパスは、`image-missing` として表示します。ページ全体を404や500にはしません。
