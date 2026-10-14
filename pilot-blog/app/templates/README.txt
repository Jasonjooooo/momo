Templates for the public site (SPEC.md §4, §7.6). Read by the publisher in app/lib/publish.php.

home.tpl.html     the home page. It is the 08da7c1 index.html with comment markers around every editable
                  text and list. Rendering it with the baseline seed (app/seed/*.baseline.json, subscribe
                  off, chat off, 5 teasers) gives back app/seed/baseline-index.html byte for byte (T1).
                  It was produced once by pilot-blog-work/backend/tools/make-template.php; edit it by hand
                  from now on, then run php app/bin/check-template.php (T1, T3 and the lint must pass).
post.tpl.html     one flight-log page, posts/<slug>.html. Markers here have empty defaults.
archive.tpl.html  posts/index.html, every published post grouped by year.
home-extra.css    styles for markup that only exists outside the baseline (published legs, live subscribe
                  form). Emitted inside <style id="extra-css"> only when needed.
posts.htaccess    copied to posts/.htaccess when it is missing (strict CSP for the post pages).
uploads.htaccess  copied to uploads/.htaccess when it is missing (nothing in uploads/ ever executes).

Marker grammar (whitespace inside the braces is exactly one space):
  <!--{t KEY}-->default<!--{/t}-->        text field (KEY registered in app/lib/fields.php), encoding text
  <!--{t KEY|ENC}-->default<!--{/t}-->    ENC: text attr js wbr lat li paras:N
  <!--{v NAME}-->default<!--{/v}-->       computed value (app/lib/blocks.php value_render, or publish.php)
  <!--{block NAME}-->default<!--{/block}-->  list rendered by PHP (legs, faq_rows, type_facts, shelf_json, …)
  <!--{if COND}-->…<!--{else}-->…<!--{/if}-->  COND may start with !; ifs do not nest
The renderer replaces each marker span, markers included; defaults never reach the output. When an if
guards whole lines, the opening marker sits at column 0 and the closing marker right after the last
guarded newline, so a false condition leaves the original bytes.

Hero rule: no marker may sit inside the hero CSS, the loader, the hero JS or the diagnostics code
(regions 1-6 of T3). Only hero caption text is editable. The developer comments inside the page are part
of the T1 baseline and stay as they are, even where they are stale.
