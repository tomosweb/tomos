# Home Article List

Tomos 1.1.15 introduces the structured `list.latest_items` collection for home templates.
Both the dynamic renderer and the GitHub Pages build use the same collection.

```html
{{# list.latest_items }}
<article>
  {{# image_url }}<img src="{{ image_url }}" alt="{{ image_alt }}">{{/ image_url }}
  <h2><a href="{{ url }}">{{ title }}</a></h2>
  {{# date }}<time datetime="{{ date }}">{{ date_display }}</time>{{/ date }}
  {{# excerpt }}<p>{{ excerpt }}</p>{{/ excerpt }}
</article>
{{/ list.latest_items }}
```

The collection follows the existing latest-articles ordering and visibility rules, with up to 12 public articles. The site root, folder index pages, and root profile page are excluded. A usable front matter `image` takes priority. Otherwise, the first resolvable Markdown or Obsidian image in the article is used. An explicit image has an empty alt value because front matter currently supplies no image description. The body image keeps its authored alt value.

Article URLs and local images include the site's public base path. External HTTP(S) images are passed through to the reader's browser. The server does not download them. The source of the text excerpt is the normal Tomos article excerpt. Existing templates can keep using `list.latest_pages`.
