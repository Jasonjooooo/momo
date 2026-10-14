<?php
// One-time builder (SPEC §4.7 step 3-4): baseline index.html -> app/templates/home.tpl.html with markers.
// Every replacement asserts its exact match count, so the defaults between markers stay byte-identical.
// Usage: php pilot-blog-work/backend/tools/make-template.php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$src = file_get_contents($root . '/pilot-blog/app/seed/baseline-index.html');
$s = $src;

function rep(string $s, string $from, string $to, int $count = 1): string
{
    $n = substr_count($s, $from);
    if ($n !== $count) {
        fwrite(STDERR, "expected $count got $n: " . mb_substr($from, 0, 90) . "\n");
        exit(1);
    }
    return str_replace($from, $to, $s);
}
function T(string $key, string $default, string $enc = 'text'): string
{
    return '<!--{t ' . $key . ($enc === 'text' ? '' : '|' . $enc) . '}-->' . $default . '<!--{/t}-->';
}
/** wrap the text between $pre and $post (both literal, unique together) in a t marker */
function wrap(string $s, string $pre, string $text, string $post, string $key, string $enc = 'text', int $count = 1): string
{
    return rep($s, $pre . $text . $post, $pre . T($key, $text, $enc) . $post, $count);
}

// ---------- head ----------
$brand = '云底之下';
$tail = '一位 PC-12 机长的飞行日志';
$desc = 'PC-12 NGX 机长的飞行日志：低云进近、短跑道、结冰天气、单发涡桨的日常。';
$s = rep($s, "<title>$brand · $tail</title>", '<title>' . T('site.brand', $brand) . ' · ' . T('site.title_tail', $tail) . '</title>');
$s = rep($s, "<meta name=\"description\" content=\"$desc\">", '<meta name="description" content="' . T('site.description', $desc, 'attr') . '">');
$s = rep($s, "<meta name=\"application-name\" content=\"$brand\">", '<meta name="application-name" content="' . T('site.brand', $brand, 'attr') . '">');
$s = rep($s, "<meta property=\"og:title\" content=\"$brand · $tail\">", '<meta property="og:title" content="' . T('site.brand', $brand, 'attr') . ' · ' . T('site.title_tail', $tail, 'attr') . '">');
$s = rep($s, "<meta property=\"og:site_name\" content=\"$brand\">", '<meta property="og:site_name" content="' . T('site.brand', $brand, 'attr') . '">');
$s = rep($s, "<meta property=\"og:description\" content=\"$desc\">", '<meta property="og:description" content="' . T('site.description', $desc, 'attr') . '">');
$deploy = "<!-- DEPLOY STEP --><!-- og:image and og:url need absolute URLs. Replace DEPLOY-DOMAIN with the live address before zipping (see deploy.md). -->\n";
$s = rep($s, $deploy, '<!--{if origin_placeholder}-->' . $deploy . '<!--{/if}-->');
$s = rep($s, '<meta property="og:image" content="https://DEPLOY-DOMAIN/assets/og.jpg">', '<meta property="og:image" content="' . T('site.origin', 'https://DEPLOY-DOMAIN', 'attr') . '/assets/og.jpg">');
$s = rep($s, '<meta property="og:url" content="https://DEPLOY-DOMAIN/">', '<meta property="og:url" content="' . T('site.origin', 'https://DEPLOY-DOMAIN', 'attr') . '/">');
$s = rep($s, "</style>\n</head>", "</style>\n<!--{block extra_css}--><!--{/block}--></head>");

// ---------- nav ----------
$s = wrap($s, '<a class="skip" href="#main">', '跳到正文', '</a>', 'nav.skip');
$s = wrap($s, '<a class="wordmark" href="#top"><span class="brand-name">', $brand, '</span>', 'site.brand');
foreach (['about' => '关于', 'log' => '日志', 'type' => '机型', 'ai' => 'AI 超市', 'faq' => '问答'] as $id => $label) {
    $s = wrap($s, '<li><a href="#' . $id . '">', $label, '</a></li>', 'nav.' . $id, 'text', 2);
}
$s = wrap($s, '<a class="btn btn-ghost nav-cta" href="#subscribe">', '订阅', '</a>', 'nav.subscribe');
$s = wrap($s, '<li><a href="#subscribe">', '订阅', '</a></li>', 'nav.subscribe');
$s = wrap($s, 'aria-controls="nav-links">', '目录', '</button>', 'nav.menu');

// ---------- hero (text only; tags and attributes untouched) ----------
$s = wrap($s, '<p class="chip" aria-hidden="true">', 'METAR ZZZZ 131030Z 24003MPS 2000 BR OVC003 08/07 Q1016 NOSIG', '</p>', 'hero.metar', 'text', 2);
$s = wrap($s, '<h1 class="hl split">', '三千英尺，<wbr>云顶之上。', '</h1>', 'hero.b1.hl', 'wbr');
$s = wrap($s, '<p class="sub split">', '我是 PC-12 机长，<wbr>飞短途支线。<wbr>跟我飞完这次进近。', '</p>', 'hero.b1.sub', 'wbr');
$s = wrap($s, '<p class="chip" aria-hidden="true">', 'IMC · ILS RWY 24', '</p>', 'hero.b2.chip');
$s = wrap($s, '<p class="hl split" data-mode="char">', '进云之后，<wbr>只信仪表。', '</p>', 'hero.b2.hl', 'wbr');
$s = wrap($s, '<p class="sub split" data-mode="char">', '窗外只剩灰色。<wbr>姿态、<wbr>空速、<wbr>航向道、<wbr>下滑道，<wbr>轮流扫视。', '</p>', 'hero.b2.sub', 'wbr');
$s = wrap($s, '<p class="chip" aria-hidden="true">', 'BREAKOUT · 云底 300 英尺', '</p>', 'hero.b3.chip');
$b3h = '穿出云底，灯光亮着。';
$b3s = '白色进近灯，绿色入口灯，两排边灯一直铺进雾里。';
$s = rep($s, '<p class="sr">' . $b3h . $b3s . '</p>', '<p class="sr">' . T('hero.b3.hl', $b3h) . T('hero.b3.sub', $b3s) . '</p>');
$s = rep($s, '<p class="hl">' . $b3h . '</p><p class="sub">' . $b3s . '</p>', '<p class="hl">' . T('hero.b3.hl', $b3h) . '</p><p class="sub">' . T('hero.b3.sub', $b3s) . '</p>', 2);
$s = wrap($s, '<p class="hl split">', '最低标准，<wbr>起飞前<wbr>就定好。', '</p>', 'hero.b4.hl', 'wbr');
$s = wrap($s, '<p class="sub ks">', '到了决断高度：看得见，继续；看不见，复飞。', '</p>', 'hero.b4.sub');
$s = wrap($s, '<a class="btn btn-primary" href="#subscribe">', '订阅飞行日志', '</a>', 'hero.cta_primary', 'text', 2);
$s = wrap($s, '<a class="btn btn-ghost" href="#log">', '先看日志', '</a>', 'hero.cta_secondary', 'text', 2);
$s = wrap($s, '<p class="cue" aria-hidden="true"><span>', '向下滚动，开始进近', '</span>', 'hero.cue');
$s = wrap($s, '<h1>', '我飞 PC-12，跑短途支线。', '</h1>', 'hero.static.h1');
$s = wrap($s, '<p class="sub">', '低云进近、短跑道、结冰天气。每篇讲清一个数字从哪里来。', '</p>', 'hero.static.sub');

// ---------- chart head ----------
$s = wrap($s, '<p class="chh-t">', '航路图', '</p>', 'chart.title');
$s = wrap($s, '<p class="chh-go">', '向下滚动，沿航路飞到跑道。', '</p>', 'chart.go');
$s = wrap($s, '<dd class="cn">', '示意航图，不作领航用', '</dd>', 'chart.disclaimer');
$s = wrap($s, 'CHART 10-1 · ENROUTE · NIGHT<br>', '示意航图，不作领航用', '</p>', 'chart.disclaimer');

// ---------- 01 about ----------
$s = wrap($s, '<p class="kicker rv" style="--i:1">', '01 / ABOUT', '</p>', 'about.kicker');
$s = wrap($s, '<h2 class="rv" id="about-h" style="--i:2">', '左座上的人。', '</h2>', 'about.h2');
preg_match('/<p class="rv" style="--i:3">我是机长.*?<\/p>\n      <p class="rv" style="--i:4">.*?<\/p>\n      <p class="rv" style="--i:5">.*?<\/p>/s', $s, $m) or exit("about body\n");
$s = rep($s, $m[0], T('about.body', $m[0], 'paras:3'));
$s = wrap($s, '<p class="staccato rv" style="--i:6">', '不露脸，不写公司，只写飞行。', '</p>', 'about.staccato');
$s = wrap($s, '<dt>TYPE</dt><dd>', '<span class="lat">PC-12 NGX</span>', '</dd>', 'about.fact1', 'lat');
$s = wrap($s, '<dt>SEAT</dt><dd>', '左座', '</dd>', 'about.fact2', 'lat');
$s = wrap($s, '<dt>ROUTE</dt><dd>', '短途支线', '</dd>', 'about.fact3', 'lat');
$s = wrap($s, '<dt>WX</dt><dd>', '低云 · 侧风 · 结冰季', '</dd>', 'about.fact4', 'lat');
$s = wrap($s, 'decoding="async" alt="', '日落之后，一架白色机身、橙红色机腹的 PC-12 在云层上方飞行，起落架已经放下，天边还留着一道晚霞。', '">', 'about.img_alt', 'attr');
$s = wrap($s, '<figcaption>', 'ON TOP · 云层上方 · 日落之后', '</figcaption>', 'about.figcaption');

// ---------- 02 log ----------
$s = wrap($s, '<p class="kicker">', '02 / LOG', '</p>', 'log.kicker');
$s = wrap($s, '<h2 id="log-h">', '飞行日志', '</h2>', 'log.h2');
$s = rep($s, '<p class="kicker">在写 5 篇</p>', '<p class="kicker"><!--{v log_count}-->在写 5 篇<!--{/v}--></p>');
$s = wrap($s, '<p class="lede rv" style="--i:1">', '落地之后写。每篇只讲一件事，写完先对一遍手册。', '</p>', 'log.lede');
$le = '这几篇还在写，写完一篇放一篇。';
$s = rep($s, "      <p class=\"list-end rv\" style=\"--i:2\">$le</p>\n", "<!--{if has_teasers}-->      <p class=\"list-end rv\" style=\"--i:2\">" . T('log.list_end', $le) . "</p>\n<!--{/if}-->");
$s = rep($s, "      <p class=\"rv\" style=\"--i:3\"><a class=\"tlink\" href=\"#subscribe\">订阅飞行日志</a></p>\n",
    "      <p class=\"rv\" style=\"--i:3\"><a class=\"tlink\" href=\"#subscribe\">" . T('log.sub_link', '订阅飞行日志') . "</a></p>\n"
    . "<!--{if has_published}-->      <p class=\"rv\" style=\"--i:4\"><a class=\"tlink\" href=\"posts/\">" . T('log.all_link', '全部日志') . "</a></p>\n<!--{/if}-->");
$s = wrap($s, '<p class="hold-lbl"><b>HOLD</b> · ', '在写', '<br>', 'log.hold_word');
preg_match('/<ol class="legs" data-io>\n        (<li .*<\/article><\/li>)\n      <\/ol>/s', $s, $m) or exit("legs\n");
$s = rep($s, $m[0], str_replace($m[1], '<!--{block legs}-->' . $m[1] . '<!--{/block}-->', $m[0]));

// ---------- 03 type ----------
preg_match('/<ul class="icao" aria-label="ICAO">\n        ((?:<li>[^<]*<\/li>)+)\n/', $s, $m) or exit("icao\n");
$s = rep($s, $m[0], str_replace($m[1], T('type.icao', $m[1], 'li'), $m[0]));
$s = wrap($s, '<p class="icao-gloss">', '单发涡桨 · 轻型 · 可单人驾驶仪表飞行 · 获准在已知结冰条件下飞行', '</p>', 'type.gloss');
$s = wrap($s, '<p class="kicker">', '03 / TYPE', '</p>', 'type.kicker');
$s = wrap($s, '<h2 id="type-h">', '机型手记：PC-12 NGX', '</h2>', 'type.h2');
$s = wrap($s, '<p class="lede">', '一台发动机，五片桨叶，一个 T 型尾翼。下面这些数字，都查得到出处。', '</p>', 'type.lede');
$s = wrap($s, '</svg>', '五叶螺旋桨', '<span class="lk"', 'type.callout1', 'lat');
$s = wrap($s, '</svg>', '<span class="lat">PT6E-67XP</span>', '<span class="lk"', 'type.callout2', 'lat');
$s = wrap($s, '</svg>', '货舱门 <span class="lat">1.35 × 1.32</span> 米', '<span class="lk"', 'type.callout3', 'lat');
$s = wrap($s, '</svg>', 'T 型尾翼', '<span class="lk"', 'type.callout4', 'lat');
preg_match('/<dl class="facts">\n        (<div><dt>.*?<\/div>)\n      <\/dl>/s', $s, $m) or exit("facts\n");
$s = rep($s, $m[0], str_replace($m[1], '<!--{block type_facts}-->' . $m[1] . '<!--{/block}-->', $m[0]));
$s = wrap($s, '<span class="src" aria-hidden="true">SOURCE</span>', '数据来自 Pilatus 公开资料，条件为海平面、标准大气、干燥铺装跑道。实际运行以飞机飞行手册为准。', '</p>', 'type.fnote');

// ---------- 04 ai ----------
$s = wrap($s, '<p class="kicker rv" style="--i:0">', '04 / AI 超市', '</p>', 'ai.kicker');
$s = wrap($s, '<h2 id="ai-h" class="rv" style="--i:1">', 'AI 超市', '</h2>', 'ai.h2');
$s = wrap($s, '<p class="lede rv" style="--i:2">', '按用途上架的 AI 工具，每样写清它擅长什么、在国内能不能直接打开。本站的 AI 助手也在这一栏。', '</p>', 'ai.lede');
$s = rep($s, '<span class="nm" id="f-act-n">A1 对话与写作</span>', '<span class="nm" id="f-act-n"><!--{v radio_act}-->A1 对话与写作<!--{/v}--></span>');
$s = rep($s, '<span class="nm" id="f-sby-n">A2 图像与视频</span>', '<span class="nm" id="f-sby-n"><!--{v radio_sby}-->A2 图像与视频<!--{/v}--></span>');
$s = wrap($s, '<p class="shelf-note">', '货架上的工具与本站没有合作关系，链接直达官网。', '</p>', 'ai.shelf_note');
$s = wrap($s, "document.createTextNode('", '打开', "')", 'ai.card_open', 'js');

// chat panel
$s = wrap($s, '<b>TWR</b> ', '塔台频率', '<span class="twf">', 'chat.twr');
$s = wrap($s, '<h3 id="chat-h">', 'AI 助手', '</h3>', 'chat.h3');
$s = wrap($s, '<span id="lamp-txt">', '接入中', '</span>', 'chat.lamp_inop');
$s = wrap($s, '<p class="chat-lede">', '问飞行常识、PC-12，或者货架上哪样工具适合你。', '</p>', 'chat.lede');
$s = wrap($s, '<p class="chat-caution">', '它会答错。涉及运行的数字，请以飞机飞行手册和公司程序为准。', '</p>', 'chat.caution');
$s = wrap($s, '<button type="button">', '决断高度和决断高有什么区别？', '</button>', 'chat.chip1');
$s = wrap($s, '<button type="button">', 'PC-12 为什么用 T 型尾翼？', '</button>', 'chat.chip2');
$s = wrap($s, '<button type="button">', '读英文资料用哪个工具？', '</button>', 'chat.chip3');
$s = wrap($s, '<label class="sr" for="chat-in">', '你的问题', '</label>', 'chat.label');
$s = wrap($s, 'maxlength="500" placeholder="', '输入你的问题', '"', 'chat.placeholder', 'attr');
$s = wrap($s, 'id="chat-send" type="submit">', '发送', '</button>', 'chat.send');
$s = wrap($s, '<p class="chat-privacy">', '对话会经本站服务器转给 AI 服务商处理，请不要输入个人信息。', '</p>', 'chat.privacy');

// ---------- 05 faq ----------
$s = wrap($s, '<p class="cl-head">', 'CHECKLIST · 读之前', '</p>', 'faq.card_head');
$s = wrap($s, '<p class="kicker">', '05 / FAQ', '</p>', 'faq.kicker');
$s = wrap($s, '<h2 id="faq-h">', '读之前，先过一遍检查单。', '</h2>', 'faq.h2');
preg_match('/<dl class="cl-rows">\n            (<div class="cl-row".*?<\/dd><\/div>)\n          <\/dl>/s', $s, $m) or exit("faq rows\n");
$s = rep($s, $m[0], str_replace($m[1], '<!--{block faq_rows}-->' . $m[1] . '<!--{/block}-->', $m[0]));
$s = wrap($s, '<p class="after"><a class="tlink" href="#subscribe">', '订阅飞行日志', '</a></p>', 'faq.sub_link');

// ---------- approach ----------
$s = wrap($s, '<p class="kicker">', 'ILS 示意 · 按住下降', '</p>', 'apch.kicker');
$s = wrap($s, '<h2 id="apch-h">', '换你来飞。', '</h2>', 'apch.h2');
$s = wrap($s, '<p class="ins">', '按住按钮，沿下滑道降到决断高度。中途松手，就是复飞。', '</p>', 'apch.ins');
$s = wrap($s, '<span class="hold-label" id="hold-label">', '按住下降', '</span>', 'apch.hold_btn');
$s = wrap($s, '<p class="fly-status" id="fly-status" aria-live="polite">', '已截获下滑道，等你按住。', '</p>', 'apch.idle');

// ---------- 06 subscribe ----------
$s = wrap($s, '<p class="kicker">', '06 / SUBSCRIBE', '</p>', 'sub.kicker');
$s = wrap($s, '<h2 id="sub-h">', '订阅飞行日志', '</h2>', 'sub.h2');
$s = wrap($s, '<p class="lede">', '最低标准写在起飞前，日志写在落地后。', '</p>', 'sub.lede');
$s = wrap($s, '<p class="sub2">', '新的一篇写完，发到你的邮箱。', '</p>', 'sub.sub2');
$s = wrap($s, '<label for="sub-email">', '邮箱', '</label>', 'sub.label');
$s = wrap($s, 'autocomplete="email" placeholder="', 'name@example.com', '"', 'sub.placeholder', 'attr');
$s = rep($s, "<p class=\"sub-err\" id=\"sub-err\" aria-live=\"polite\"></p>\n          <div class=\"thr5\"",
    "<p class=\"sub-err\" id=\"sub-err\" aria-live=\"polite\"></p>\n"
    . "<!--{if subscribe_live}-->          <label class=\"sub-consent\"><input id=\"sub-consent\" name=\"consent\" type=\"checkbox\" value=\"1\"><span>" . T('sub.consent', '我同意本站保存这个邮箱，只用来给我发飞行日志。') . "</span></label>\n"
    . "          <p class=\"sub-hp\" aria-hidden=\"true\"><label for=\"sub-web\">网址</label><input id=\"sub-web\" name=\"website\" type=\"text\" tabindex=\"-1\" autocomplete=\"off\"></p>\n"
    . "<!--{/if}-->          <div class=\"thr5\"");
$s = wrap($s, 'id="sub-btn" type="submit">', '订阅飞行日志', '</button>', 'sub.button');
$s = wrap($s, '<p class="micro">', '只发飞行日志。不发广告，随时退订。', '</p>', 'sub.micro');
$off = '订阅通道还在接入，开通之前提交的邮箱不会被保存。';
$s = rep($s, "          <p class=\"micro mode-a\">$off</p>\n        </form>", "<!--{if !subscribe_live}-->          <p class=\"micro mode-a\">" . T('sub.off_note', $off) . "</p>\n<!--{/if}-->        </form>");

// ---------- footer ----------
$s = wrap($s, '<p class="foot-mark"><span class="brand-name">', $brand, '</span>', 'site.brand');
$s = wrap($s, '<p class="foot-tag">', '落地，关车，写日志。', '</p>', 'footer.tag');
$s = wrap($s, '<p>', '本站的飞机影像和配图由 AI 生成，不对应任何真实航空器。', '</p>', 'footer.note1');
$s = wrap($s, '<p>', '本站内容是个人飞行经验分享，不能代替飞机飞行手册、公司程序和教员的指导。', '</p>', 'footer.note2');
$s = rep($s, '<p>© 2026 <span class="brand-name">云底之下</span></p>', '<p>© ' . T('site.footer_year', '2026') . ' <span class="brand-name">' . T('site.brand', $brand) . '</span></p>');

// ---------- shelf JSON ----------
preg_match('/<script type="application\/json" id="shelf-data">\n(\{.*?\n\})\n<\/script>/s', $s, $m) or exit("shelf\n");
$s = rep($s, $m[0], str_replace($m[1], '<!--{block shelf_json}-->' . $m[1] . '<!--{/block}-->', $m[0]));

// ---------- script constants and copy ----------
$s = rep($s, "const CHAT_LIVE = false;", "const CHAT_LIVE = <!--{v chat_live}-->false<!--{/v}-->;");
$s = rep($s, "const SUBSCRIBE_ENDPOINT = '';", "const SUBSCRIBE_ENDPOINT = <!--{v subscribe_endpoint}-->''<!--{/v}-->;");
$tStart = strpos($s, "const T = {\n");
$tEnd = strpos($s, "\n};\n", $tStart);
$tObj = substr($s, $tStart, $tEnd - $tStart);
$jsKeys = [
    'flyIdle' => 'apch.idle', 'flyHold' => 'apch.holding', 'flyGa' => 'apch.go_around', 'flyDone' => 'apch.done',
    'holdBtn' => 'apch.hold_btn', 'holdAgain' => 'apch.again',
    'welcomeInop' => 'chat.welcome_inop', 'welcomeLive' => 'chat.welcome_live', 'chatInop' => 'chat.msg_inop',
    'chatDown' => 'chat.msg_down', 'chatTimeout' => 'chat.msg_timeout', 'chatRate' => 'chat.msg_rate', 'chatLimit' => 'chat.msg_limit',
    'send' => 'chat.send', 'sending' => 'chat.sending', 'answering' => 'chat.answering',
    'subEmpty' => 'sub.msg_empty', 'subFormat' => 'sub.msg_format', 'subModeA' => 'sub.msg_off', 'subBusy' => 'sub.msg_busy',
    'subOkPre' => 'sub.ok_pre', 'subOkPost' => 'sub.ok_post', 'subFail' => 'sub.msg_fail',
];
foreach ($jsKeys as $name => $key) {
    $n = preg_match_all('/(?<![A-Za-z])' . $name . ": '((?:[^'\\\\]|\\\\.)*)'/", $tObj, $mm);
    if ($n !== 1) {
        exit("T.$name: $n\n");
    }
    $tObj = str_replace($mm[0][0], $name . ": '" . T($key, $mm[1][0], 'js') . "'", $tObj);
}
$tObj .= "<!--{if subscribe_live}-->,\n  subConsent: '" . T('sub.msg_consent', '请先勾选同意，再提交。', 'js') . "',\n  subRate: '" . T('sub.msg_rate', '提交得有点频繁，过一会儿再试。', 'js') . "'<!--{/if}-->";
$s = substr($s, 0, $tStart) . $tObj . substr($s, $tEnd);
$s = rep($s, "const LAMP = { stby: ['STBY', '待命'], tx: ['TX', '发送中'], on: ['ON', '在线'], inop: ['INOP', '接入中'] };",
    "const LAMP = { stby: ['STBY', '" . T('chat.lamp_stby', '待命', 'js') . "'], tx: ['TX', '" . T('chat.lamp_tx', '发送中', 'js') . "'], on: ['ON', '" . T('chat.lamp_on', '在线', 'js') . "'], inop: ['INOP', '" . T('chat.lamp_inop', '接入中', 'js') . "'] };");

// ---------- subscribe script: live branch (real endpoint) and today's Mode A branch ----------
$a = strpos($s, "/* ============ 06: subscribe ============ */\n");
$b = strpos($s, "/* ---------- hero diagnostics panel ---------- */");
$old = substr($s, $a, $b - $a);
$old = rep($old, "subBtn.textContent = '订阅飞行日志';", "subBtn.textContent = '" . T('sub.button', '订阅飞行日志', 'js') . "';");
$new = file_get_contents(__DIR__ . '/subscribe-live.js');
$new = str_replace('{{SUB_BUTTON}}', T('sub.button', '订阅飞行日志', 'js'), $new);
if (!str_ends_with($new, "});\n\n")) {
    exit("subscribe-live.js must end with \"});\\n\\n\"\n");
}
$s = substr($s, 0, $a) . '<!--{if subscribe_live}-->' . $new . '<!--{else}-->' . $old . '<!--{/if}-->' . substr($s, $b);

file_put_contents($root . '/pilot-blog/app/templates/home.tpl.html', $s);
echo "home.tpl.html written: " . strlen($s) . " bytes, " . substr_count($s, '<!--{') . " markers\n";
