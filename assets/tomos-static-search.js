(function () {
  'use strict';

  function text(value) {
    return typeof value === 'string' ? value : '';
  }

  function lower(value) {
    return text(value).toLocaleLowerCase();
  }

  function terms(query) {
    var normalized = text(query).replace(/[\u0000-\u001f\u007f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100);
    return normalized === '' ? [] : Array.from(new Set(lower(normalized).split(/\s+/).filter(Boolean)));
  }

  function haystack(doc) {
    return [
      text(doc.title),
      text(doc.description),
      text(doc.excerpt),
      Array.isArray(doc.tags) ? doc.tags.join(' ') : '',
      text(doc.url),
      text(doc.path),
      text(doc.search_text)
    ].join(' ');
  }

  function containsAll(doc, queryTerms) {
    var source = lower(haystack(doc));
    return queryTerms.every(function (term) {
      return term === '' || source.indexOf(term) !== -1;
    });
  }

  function score(doc, queryTerms) {
    var fields = [
      [text(doc.title), 40],
      [Array.isArray(doc.tags) ? doc.tags.join(' ') : '', 30],
      [text(doc.description), 20],
      [text(doc.search_text), 10],
      [text(doc.excerpt), 5]
    ];

    return fields.reduce(function (total, entry) {
      var source = lower(entry[0]);
      var weight = entry[1];
      return total + queryTerms.reduce(function (subtotal, term) {
        return subtotal + (term !== '' && source.indexOf(term) !== -1 ? weight : 0);
      }, 0);
    }, 0);
  }

  function escapeHtml(value) {
    return text(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function render(container, query, docs) {
    var queryTerms = terms(query);
    var summary = container.querySelector('[data-static-search-summary]');
    var resultsNode = container.querySelector('[data-static-search-results]');

    if (!summary || !resultsNode) {
      return;
    }

    if (queryTerms.length === 0) {
      summary.textContent = '検索語を入力してください。';
      resultsNode.innerHTML = '';
      return;
    }

    var results = docs.filter(function (doc) {
      return containsAll(doc, queryTerms);
    }).map(function (doc) {
      return { doc: doc, score: score(doc, queryTerms) };
    });

    results.sort(function (a, b) {
      if (a.score !== b.score) {
        return b.score - a.score;
      }

      var dateCompare = text(b.doc.sort_date).localeCompare(text(a.doc.sort_date));
      if (dateCompare !== 0) {
        return dateCompare;
      }

      return text(a.doc.title).localeCompare(text(b.doc.title));
    });

    if (results.length === 0) {
      summary.textContent = '「' + query + '」に一致するページはありませんでした。';
      resultsNode.innerHTML = '';
      return;
    }

    summary.textContent = '「' + query + '」の検索結果: ' + results.length + '件';
    resultsNode.innerHTML = results.map(function (entry) {
      var doc = entry.doc;
      var description = text(doc.description);
      return '<li><a href="' + escapeHtml(doc.url) + '">' + escapeHtml(doc.title) + '</a>'
        + (description !== '' ? '<p>' + escapeHtml(description) + '</p>' : '')
        + '</li>';
    }).join('');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var container = document.querySelector('[data-static-search]');
    if (!container) {
      return;
    }

    var indexUrl = container.getAttribute('data-search-index-url') || '';
    var form = container.querySelector('form');
    var input = container.querySelector('input[name="q"]');
    var params = new URLSearchParams(window.location.search);
    var query = params.get('q') || '';

    if (input) {
      input.value = query;
    }

    fetch(indexUrl, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('search index unavailable');
        }
        return response.json();
      })
      .then(function (docs) {
        render(container, query, Array.isArray(docs) ? docs : []);
      })
      .catch(function () {
        var summary = container.querySelector('[data-static-search-summary]');
        if (summary) {
          summary.textContent = '検索データを読み込めませんでした。';
        }
      });

    if (form) {
      form.addEventListener('submit', function () {
        if (input) {
          input.value = input.value.trim();
        }
      });
    }
  });
}());
