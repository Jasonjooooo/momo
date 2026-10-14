# Backend work progress

- 2026-10-14T15:48:56Z spec agent: started; reading index.html, chart parts, build.py, chat.php, deploy.md
- 2026-10-14T16:03:45Z spec agent: read all sources; verified lat regex + PHP JSON pretty-print reproduce seed bytes; writing SPEC.md
- 2026-10-14T16:17:20Z spec agent: SPEC.md written and self-reviewed (template markers, seed/T1, publisher, posts, shelf, subscribers, assistant, security, admin UX, routes, tests, build order)
- 2026-10-14T16:21:03Z core agent: started; read SPEC.md fully, index.html landmarks, chat.php, test.js. Plan: Phase 0+1 core, subscribe.php, chat.php, .htaccess, tests (no admin UI)
- 2026-10-14T16:37:34Z core agent: Phase 0 core done: lib (bootstrap/store/security/ratelimit/template/fields/blocks/markdown/posts/shelf/settings/images/subscribers/publish), home.tpl.html built by tools/make-template.php (339 markers), seed extracted; check-template.php: T1 PASS (sha 3401b7f4…), T3 PASS (baseline/launch/probe)
- 2026-10-14T16:42:12Z core agent: Phase 1 publisher: post/archive templates, assets/post.css, home-extra.css, launch patch, render-launch.php, bin/publish.php; pilot-blog/index.html regenerated = launch render (diff vs 08da7c1 limited to subscribe flow); api/subscribe.php, chat.php (settings/usage), .htaccess files, dev router written; next: tests
- 2026-10-14T16:52:31Z core agent: tests: run.php 181 checks pass (template/markdown/publish/images/subscribers/http); public.e2e.js pub0/1/3/8 at 1440+390 all pass; B-chart test.js both noshots against launch page passes (only chat 503 logged)
- 2026-10-14T16:57:16Z core agent: added app/lib/content.php (site/shelf draft, publish, discard, origin quick-set), hardening (public dir modes 0755, slug guard, honeypot types, manifest touch); SPEC §18 Deviations + §19 How to run locally; run.php 196 pass, e2e all pass. NEXT (admin agent): security.php auth/session/CSRF/setup code, view.php, admin/index.php + assets + views (SPEC §11-§13); app_init step 3
