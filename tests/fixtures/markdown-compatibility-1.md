# H1
## H2
### H3
#### H4
##### H5
###### H6

Paragraph line one
Paragraph line two with an explicit break  
Paragraph line three.

**bold**, *italic*, ***bold italic***, and ~~strikethrough~~.

> A blockquote with **inline emphasis**.

- unordered parent
  - nested unordered child
  1. nested ordered child
1. ordered parent
   - nested unordered child
   1. nested ordered child
- [ ] incomplete task
- [x] completed task
- [X] completed task (uppercase)

Inline `code **not bold** https://example.com`.

```
**not bold**
<script>alert(1)</script>
https://example.com
```

```php
echo '<strong>code</strong>';
```

[Markdown link](https://example.com/path)
http://example.org/plain.
https://example.com/bare.
<https://example.com/autolink>
[mailto](mailto:person@example.com)
[javascript](javascript:alert(1))
[data](data:text/html,alert(1))

![Markdown image](images/sample.jpg)
![[images/sample.jpg]]

| Header | Left | Center | Right | Long cell |
| :--- | :--- | :---: | ---: | --- |
| **inline** | left text | center text | right text | A long cell with a URL https://example.com/a-very-long-path/with-many-segments |
| second | more text | more text | more text | another long value that should remain inside the table region |

[[page]]
[[page|Page label]]
[[page#heading|Heading label]]
![[page]]
:::note
Callout candidate
:::
Footnote candidate[^1]
[^1]: Footnote definition
==highlight candidate==
Block reference candidate ^abc123

https://www.youtube.com/watch?v=dQw4w9WgXcQ&si=ignored
https://youtu.be/dQw4w9WgXcQ?t=30
https://youtube.com/shorts/dQw4w9WgXcQ#ignored
Prefix https://www.youtube.com/watch?v=dQw4w9WgXcQ
[Markdown YouTube link](https://www.youtube.com/watch?v=dQw4w9WgXcQ)

<div>safe HTML candidate</div>
<span>safe span candidate</span>
<strong>safe strong candidate</strong><br>
<a href="https://example.com">safe anchor candidate</a>
<script>alert('unsafe')</script>
<iframe src="https://evil.example/frame"></iframe>
<img src="x" onerror="alert(1)">
<a href="javascript:alert(1)">unsafe URL</a>
<svg><circle cx="10" cy="10" r="5"></circle></svg>
<style>body { display: none; }</style>
