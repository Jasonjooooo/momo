<?php
// Field registry (SPEC §4.8) and site-copy validation (SPEC §5.2).
declare(strict_types=1);
defined('BLOG_APP') || exit;

function field_groups(): array
{
    return [
        'site' => '基本信息',
        'nav' => '导航',
        'hero' => '开场动画（首页第一屏）',
        'chart' => '航路图标题',
        'about' => '关于',
        'type' => '机型',
        'apch' => '进近小游戏',
        'log' => '飞行日志栏',
        'ai' => 'AI 超市栏',
        'chat' => 'AI 助手对话框',
        'faq' => '问答',
        'sub' => '订阅',
        'footer' => '页脚',
        'post' => '日志页面',
    ];
}

/**
 * key => [group, label, where, enc[], multiline, kind (text|pattern|url|year), vars[], soft (bool), default (?string), max, soft_max, hint]
 * enc[] lists the encodings the key is rendered with in the templates (empty: used only through values and blocks).
 */
function field_registry(): array
{
    static $reg = null;
    if ($reg !== null) {
        return $reg;
    }
    $W = '竖线 | 是手机上允许换行的位置，可以不加。';
    // [key, group, label, where, enc, flags, default]; flags: s = soft max, m = multiline, p:{a},{b} = pattern, u = url, y = year
    $rows = [
        ['site.brand', 'site', '博客名', '浏览器标签、分享卡片、导航和页脚', ['text', 'attr'], 's', null],
        ['site.title_tail', 'site', '标题后半句（浏览器标签和分享卡片）', '浏览器标签和分享卡片标题里，博客名后面', ['text', 'attr'], '', null],
        ['site.description', 'site', '网站简介（搜索结果和分享卡片）', '搜索结果和分享卡片的简介', ['attr'], '', null],
        ['site.origin', 'site', '网站地址（例如 https://example.com，不要以 / 结尾）', '分享卡片的图片和链接地址', ['attr'], 'u', null],
        ['site.footer_year', 'site', '版权年份', '页脚 © 后面', ['text'], 'y', null],

        ['nav.skip', 'nav', '跳到正文（键盘用户才看得到）', '页面最上面，按 Tab 键才出现', ['text'], 's', null],
        ['nav.about', 'nav', '导航：关于', '顶部导航和页脚链接', ['text'], 's', null],
        ['nav.type', 'nav', '导航：机型', '顶部导航和页脚链接', ['text'], 's', null],
        ['nav.log', 'nav', '导航：日志', '顶部导航和页脚链接', ['text'], 's', null],
        ['nav.ai', 'nav', '导航：AI 超市', '顶部导航和页脚链接', ['text'], 's', null],
        ['nav.faq', 'nav', '导航：问答', '顶部导航和页脚链接', ['text'], 's', null],
        ['nav.subscribe', 'nav', '导航：订阅按钮', '顶部导航右边的按钮和页脚链接', ['text'], 's', null],
        ['nav.menu', 'nav', '手机菜单按钮', '手机上顶部右边的按钮', ['text'], 's', null],

        ['hero.metar', 'hero', '天气报文字条', '开场第 1 段上方的小字条', ['text'], 's', null],
        ['hero.b1.hl', 'hero', '第 1 段大字', '开场第 1 段', ['wbr'], 's', null],
        ['hero.b1.sub', 'hero', '第 1 段小字', '开场第 1 段', ['wbr'], 's', null],
        ['hero.b2.chip', 'hero', '第 2 段字条', '开场第 2 段（进云）', ['text'], 's', null],
        ['hero.b2.hl', 'hero', '第 2 段大字', '开场第 2 段（进云）', ['wbr'], 's', null],
        ['hero.b2.sub', 'hero', '第 2 段小字', '开场第 2 段（进云）', ['wbr'], 's', null],
        ['hero.b3.chip', 'hero', '第 3 段字条', '开场第 3 段（出云）', ['text'], 's', null],
        ['hero.b3.hl', 'hero', '第 3 段大字', '开场第 3 段（出云）', ['text'], 's', null],
        ['hero.b3.sub', 'hero', '第 3 段小字', '开场第 3 段（出云）', ['text'], 's', null],
        ['hero.b4.hl', 'hero', '第 4 段大字', '开场第 4 段（落地前）', ['wbr'], 's', null],
        ['hero.b4.sub', 'hero', '第 4 段小字', '开场第 4 段（落地前）', ['text'], 's', null],
        ['hero.cta_primary', 'hero', '开场按钮：订阅', '开场最后的橙色按钮', ['text'], 's', null],
        ['hero.cta_secondary', 'hero', '开场按钮：看日志', '开场最后的第二个按钮', ['text'], 's', null],
        ['hero.cue', 'hero', '向下滚动提示', '开场第一屏下方', ['text'], 's', null],
        ['hero.static.h1', 'hero', '静态版标题（省流量模式和不能播视频时显示）', '不能播放视频时的第一屏', ['text'], 's', null],
        ['hero.static.sub', 'hero', '静态版小字（省流量模式和不能播视频时显示）', '不能播放视频时的第一屏', ['text'], 's', null],

        ['chart.title', 'chart', '航路图标题', '开场下面的航路图标题栏', ['text'], 's', null],
        ['chart.go', 'chart', '航路图提示', '航路图标题下面', ['text'], '', null],
        ['chart.disclaimer', 'chart', '示意图声明', '航路图标题栏和页脚', ['text'], '', null],

        ['about.kicker', 'about', '小标签', '「关于」标题上方', ['text'], 's', null],
        ['about.h2', 'about', '标题', '「关于」', ['text'], 's', null],
        ['about.body', 'about', '正文（每段一行）', '「关于」标题下面', ['paras:3'], 'm', null],
        ['about.staccato', 'about', '结尾短句', '「关于」正文后面', ['text'], '', null],
        ['about.fact1', 'about', '机型', '「关于」下面的小表格', ['lat'], '', null],
        ['about.fact2', 'about', '座位', '「关于」下面的小表格', ['lat'], '', null],
        ['about.fact3', 'about', '航线', '「关于」下面的小表格', ['lat'], '', null],
        ['about.fact4', 'about', '天气', '「关于」下面的小表格', ['lat'], '', null],
        // the INSET A photo's alt and caption now live in the album (site.album); these two keys mirror its first photo so
        // content saved before the album keeps validating and an old version rolls back to the right caption
        ['about.img_alt', 'about', '照片说明（读屏软件读这句）', '「关于」的照片（看不见，读屏软件会读）', [], '', null],
        ['about.figcaption', 'about', '照片下方小字', '「关于」的照片下面', [], '', null],

        ['type.icao', 'type', '代码条（一行一个）', '「机型」最上面的一排代码', ['li'], 'm', null],
        ['type.gloss', 'type', '代码解释', '代码条下面', ['text'], '', null],
        ['type.kicker', 'type', '小标签', '「机型」标题上方', ['text'], 's', null],
        ['type.h2', 'type', '标题', '「机型」', ['text'], 's', null],
        ['type.lede', 'type', '导语', '「机型」标题下面', ['text'], '', null],
        ['type.callout1', 'type', '图注 1（对应线图上的 1 号）', '「机型」线图旁的图注', ['lat'], '', null],
        ['type.callout2', 'type', '图注 2（对应线图上的 2 号）', '「机型」线图旁的图注', ['lat'], '', null],
        ['type.callout3', 'type', '图注 3（对应线图上的 3 号）', '「机型」线图旁的图注', ['lat'], '', null],
        ['type.callout4', 'type', '图注 4（对应线图上的 4 号）', '「机型」线图旁的图注', ['lat'], '', null],
        ['type.fnote', 'type', '数据来源说明', '数据格子下面', ['text'], '', null],

        ['apch.kicker', 'apch', '小标签', '进近小游戏标题上方', ['text'], 's', null],
        ['apch.h2', 'apch', '标题', '进近小游戏', ['text'], 's', null],
        ['apch.ins', 'apch', '玩法说明', '进近小游戏标题下面', ['text'], '', null],
        ['apch.hold_btn', 'apch', '按钮文字', '进近小游戏的按钮', ['text', 'js'], 's', null],
        ['apch.idle', 'apch', '等待时的提示', '按钮下面', ['text', 'js'], '', null],
        ['apch.holding', 'apch', '下降中', '按住按钮时', ['js'], '', null],
        ['apch.go_around', 'apch', '复飞', '中途松手时', ['js'], '', null],
        ['apch.done', 'apch', '完成', '降到决断高度以后（下面接着是「飞行日志」）', ['js'], '', null],
        ['apch.again', 'apch', '再飞一次', '完成以后的按钮文字', ['js'], 's', null],

        ['log.kicker', 'log', '小标签', '「飞行日志」标题上方', ['text'], 's', null],
        ['log.h2', 'log', '标题', '「飞行日志」', ['text'], 's', null],
        ['log.lede', 'log', '导语', '「飞行日志」标题下面', ['text'], '', null],
        ['log.list_end', 'log', '有预告时显示的一句', '「飞行日志」导语下面，有预告时才显示', ['text'], '', null],
        ['log.sub_link', 'log', '订阅链接文字', '「飞行日志」左栏的链接', ['text'], 's', null],
        ['log.all_link', 'log', '全部日志链接', '「飞行日志」左栏，有已发布的日志时才显示', ['text'], 's', '全部日志'],
        ['log.hold_word', 'log', '等待航线旁的小字', '「飞行日志」等待航线图旁边', ['text'], 's', null],
        ['log.count_writing', 'log', '计数：只有预告', '「飞行日志」标题旁', [], 'p:{t}', '在写 {t} 篇'],
        ['log.count_latest', 'log', '计数：只有已发布', '「飞行日志」标题旁', [], 'p:{p}', '最新 {p} 篇'],
        ['log.count_mixed', 'log', '计数：两种都有', '「飞行日志」标题旁', [], 'p:{p},{t}', '最新 {p} 篇 · 在写 {t} 篇'],
        ['log.count_none', 'log', '计数：一篇都没有', '「飞行日志」标题旁', [], '', '还没有日志'],
        ['log.teaser_date', 'log', '预告的日期位置显示', '每条预告的日期位置', [], 's', 'DRAFT'],
        ['log.ete_teaser', 'log', '预告的阅读时间', '每条预告右上角', [], 'p:{n}', '预计 {n} 分钟'],
        ['log.ete_published', 'log', '已发布的阅读时间', '每篇已发布日志的右上角和日志页面', [], 'p:{n}', '{n} 分钟'],

        ['ai.kicker', 'ai', '小标签', '「AI 超市」标题上方', ['text'], 's', null],
        ['ai.h2', 'ai', '标题', '「AI 超市」', ['text'], 's', null],
        ['ai.lede', 'ai', '导语', '「AI 超市」标题下面', ['text'], '', null],
        ['ai.shelf_note', 'ai', '货架底部声明', '货架最下面的小字', ['text'], '', null],
        ['ai.card_open', 'ai', '工具卡片上的「打开」', '每张工具卡片的链接', ['js'], 's', null],

        ['chat.twr', 'chat', '塔台频率字样', '对话框最上面', ['text'], 's', null],
        ['chat.h3', 'chat', '标题', '对话框标题', ['text'], 's', null],
        ['chat.lede', 'chat', '说明', '对话框标题下面', ['text'], '', null],
        ['chat.caution', 'chat', '提醒', '对话框说明下面', ['text'], '', null],
        ['chat.privacy', 'chat', '隐私说明', '对话框最下面', ['text'], '', null],
        ['chat.chip1', 'chat', '示例问题 1', '对话框里可以点的示例问题', ['text'], 's', null],
        ['chat.chip2', 'chat', '示例问题 2', '对话框里可以点的示例问题', ['text'], 's', null],
        ['chat.chip3', 'chat', '示例问题 3', '对话框里可以点的示例问题', ['text'], 's', null],
        ['chat.label', 'chat', '输入框说明（读屏）', '输入框（看不见，读屏软件会读）', ['text'], '', null],
        ['chat.placeholder', 'chat', '输入框提示', '输入框里的灰字', ['attr'], 's', null],
        ['chat.send', 'chat', '发送按钮', '对话框的发送按钮', ['text', 'js'], 's', null],
        ['chat.lamp_inop', 'chat', '状态灯：接入中', '对话框标题旁的状态灯', ['text', 'js'], 's', null],
        ['chat.lamp_stby', 'chat', '状态灯：待命', '对话框标题旁的状态灯', ['js'], 's', null],
        ['chat.lamp_tx', 'chat', '状态灯：发送中', '对话框标题旁的状态灯', ['js'], 's', null],
        ['chat.lamp_on', 'chat', '状态灯：在线', '对话框标题旁的状态灯', ['js'], 's', null],
        ['chat.welcome_inop', 'chat', '开场白（助手没开时）', '对话框里的第一句话', ['js'], '', null],
        ['chat.welcome_live', 'chat', '开场白（助手开着时）', '对话框里的第一句话', ['js'], '', null],
        ['chat.msg_inop', 'chat', '提示：助手还没接通', '访客提问以后', ['js'], '', null],
        ['chat.msg_down', 'chat', '提示：连不上', '访客提问以后', ['js'], '', null],
        ['chat.msg_timeout', 'chat', '提示：等太久', '访客提问以后', ['js'], '', null],
        ['chat.msg_rate', 'chat', '提示：问得太快', '访客提问以后', ['js'], '', null],
        ['chat.msg_limit', 'chat', '提示：字数到上限', '输入框下面', ['js'], '', null],
        ['chat.sending', 'chat', '发送按钮：发送中', '点了发送以后', ['js'], 's', null],
        ['chat.answering', 'chat', '读屏提示：正在回答', '读屏软件会读', ['js'], 's', null],

        ['faq.card_head', 'faq', '卡片标题', '问答卡片最上面', ['text'], 's', null],
        ['faq.kicker', 'faq', '小标签', '「问答」标题上方', ['text'], 's', null],
        ['faq.h2', 'faq', '标题', '「问答」', ['text'], 's', null],
        ['faq.sub_link', 'faq', '订阅链接文字', '问答卡片下面的链接', ['text'], 's', null],

        ['sub.kicker', 'sub', '小标签', '订阅卡片最上面', ['text'], 's', null],
        ['sub.h2', 'sub', '标题', '订阅卡片', ['text'], 's', null],
        ['sub.lede', 'sub', '导语', '订阅卡片标题下面', ['text'], '', null],
        ['sub.sub2', 'sub', '说明', '订阅卡片导语下面', ['text'], '', null],
        ['sub.label', 'sub', '输入框标题', '邮箱输入框上面', ['text'], 's', null],
        ['sub.placeholder', 'sub', '输入框提示', '邮箱输入框里的灰字', ['attr'], 's', null],
        ['sub.button', 'sub', '订阅按钮', '订阅卡片的橙色按钮', ['text', 'js'], 's', null],
        ['sub.micro', 'sub', '按钮下方小字', '订阅按钮下面', ['text'], '', null],
        ['sub.off_note', 'sub', '订阅关闭时的说明', '订阅关闭时，按钮下面和问答里', ['text'], '', null],
        ['sub.consent', 'sub', '同意勾选框文字', '订阅开着时，按钮上面的勾选框', ['text'], '', null],
        ['sub.msg_empty', 'sub', '提示：没填邮箱', '点订阅以后', ['js'], '', null],
        ['sub.msg_format', 'sub', '提示：邮箱格式不对', '点订阅以后', ['js'], '', null],
        ['sub.msg_off', 'sub', '提示：订阅关着', '订阅关闭时点订阅以后', ['js'], '', null],
        ['sub.msg_busy', 'sub', '按钮：提交中', '点订阅以后', ['js'], 's', null],
        ['sub.ok_pre', 'sub', '成功提示（邮箱前面）', '订阅成功以后，邮箱地址前面的话', ['js'], '', null],
        ['sub.ok_post', 'sub', '成功提示（邮箱后面）', '订阅成功以后，邮箱地址后面的话', ['js'], '', null],
        ['sub.msg_fail', 'sub', '提示：没提交成功', '点订阅以后', ['js'], '', null],
        ['sub.msg_consent', 'sub', '提示：没勾同意', '点订阅以后', ['js'], '', null],
        ['sub.msg_rate', 'sub', '提示：提交太频繁', '点订阅以后', ['js'], '', null],

        ['footer.tag', 'footer', '页脚短句', '页脚博客名下面', ['text'], '', null],
        ['footer.note1', 'footer', '页脚说明 1', '页脚', ['text'], '', null],
        ['footer.note2', 'footer', '页脚说明 2', '页脚', ['text'], '', null],

        ['post.back', 'post', '回到航路图', '日志页面顶部', ['text'], 's', '回到航路图'],
        ['post.all', 'post', '全部日志', '日志页面顶部', ['text'], 's', '全部日志'],
        ['post.prev', 'post', '上一篇', '日志页面底部', [], 's', '上一篇'],
        ['post.next', 'post', '下一篇', '日志页面底部', [], 's', '下一篇'],
        ['post.end_kicker', 'post', '结尾小标签', '日志正文后面', ['text'], 's', 'ON BLOCKS'],
        ['post.end_line', 'post', '结尾一句', '日志正文后面', ['text'], '', '这一段飞完了。'],
        ['post.sub_line', 'post', '订阅说明', '日志正文后面，订阅开着时', ['text'], '', '想读下一篇，可以订阅。写完以后，我会从订阅名单里发到你的邮箱。'],
        ['post.sub_btn', 'post', '订阅按钮', '日志正文后面，订阅开着时', ['text'], 's', '订阅飞行日志'],
        ['post.tags', 'post', '标签', '日志正文后面', ['text'], 's', '标签'],
        ['archive.kicker', 'post', '全部日志页：小标签', '全部日志页', ['text'], 's', 'LOGBOOK'],
        ['archive.title', 'post', '全部日志页：标题', '全部日志页', ['text', 'attr'], 's', '全部日志'],
        ['archive.lede', 'post', '全部日志页：导语', '全部日志页', ['text'], '', '按发布时间排列，新的在上面。'],
        ['archive.empty', 'post', '全部日志页：没有日志时', '全部日志页', [], '', '还没有发布的日志。'],
    ];
    $seedFile = APP_DIR . '/seed/site.baseline.json';
    $seed = is_file($seedFile) ? (json_read($seedFile, [])['fields'] ?? []) : [];
    $reg = [];
    foreach ($rows as [$key, $group, $label, $where, $enc, $flags, $default]) {
        $kind = 'text';
        $vars = [];
        if (str_starts_with($flags, 'p:')) {
            $kind = 'pattern';
            $vars = explode(',', substr($flags, 2));
        } elseif ($flags === 'u') {
            $kind = 'url';
        } elseif ($flags === 'y') {
            $kind = 'year';
        }
        $multiline = $flags === 'm';
        $seedVal = $seed[$key] ?? $default;
        $len = $seedVal === null ? 0 : mb_strlen($seedVal);
        $max = min(400, max(20, 3 * $len));
        if ($kind === 'url') {
            $max = 200;
        } elseif ($kind === 'year') {
            $max = 4;
        }
        $soft = $flags === 's' || str_starts_with($key, 'hero.');
        $reg[$key] = [
            'group' => $group,
            'label' => $label,
            'where' => $where,
            'enc' => $enc,
            'multiline' => $multiline,
            'kind' => $kind,
            'vars' => $vars,
            'default' => $default,
            'max' => $max,
            'soft_max' => ($soft && $len > 0) ? (int) ceil(1.5 * $len) : null,
            'hint' => in_array('wbr', $enc, true) ? $W : null,
        ];
    }
    return $reg;
}

const SOFT_MAX_WARNING = '比原来长不少，手机上可能放不下，发布前请预览';

/** Problems with one value; null when fine. */
function field_problem(string $key, mixed $value): ?string
{
    $reg = field_registry();
    if (!isset($reg[$key])) {
        return '没有这一项。';
    }
    $f = $reg[$key];
    if (!is_string($value)) {
        return '这一项要填文字。';
    }
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{2028}\x{2029}]/u', $value)) {
        return '这一项里有看不见的特殊字符，请删掉后再保存。';
    }
    if (trim($value) === '') {
        return '这一项不能空着。';
    }
    if (!$f['multiline'] && str_contains($value, "\n")) {
        return '这一项只能写一行。';
    }
    $n = mb_strlen($value);
    if ($n > $f['max']) {
        return '最多 ' . $f['max'] . ' 个字，现在是 ' . $n . ' 个。';
    }
    if (str_contains($value, '|') && !in_array('wbr', $f['enc'], true)) {
        return '这一项里不能用竖线 |。';
    }
    if ($f['kind'] === 'pattern') {
        foreach ($f['vars'] as $var) {
            if (!str_contains($value, $var)) {
                return '这一项里要保留 ' . $var . '，它会换成数字。';
            }
        }
        preg_match_all('/\{[^}]*\}/', $value, $mm);
        foreach ($mm[0] as $ph) {
            if (!in_array($ph, $f['vars'], true)) {
                return '这一项里只能用 ' . implode(' ', $f['vars']) . '，' . $ph . ' 要删掉。';
            }
        }
    }
    if ($f['kind'] === 'url' && !preg_match('/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]+)?$/', $value)) {
        return '网址格式不对，要像 https://example.com 这样，结尾不要加 /。';
    }
    if ($f['kind'] === 'year' && !preg_match('/^[0-9]{4}$/', $value)) {
        return '请填四位数字的年份，比如 2026。';
    }
    return null;
}

/** Soft-max warnings (never block a save): key => message. */
function site_warnings(array $site): array
{
    $out = [];
    foreach (field_registry() as $key => $f) {
        $v = $site['fields'][$key] ?? null;
        if ($f['soft_max'] !== null && is_string($v) && mb_strlen($v) > $f['soft_max']) {
            $out['fields.' . $key] = SOFT_MAX_WARNING;
        }
    }
    return $out;
}

function text_problem(mixed $v, int $max, bool $required = true): ?string
{
    if (!is_string($v)) {
        return '这一项要填文字。';
    }
    if (!mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}]/u', $v)) {
        return '这一项里有看不见的特殊字符或换行，请删掉后再保存。';
    }
    if ($required && trim($v) === '') {
        return '这一项不能空着。';
    }
    if (mb_strlen($v) > $max) {
        return '最多 ' . $max . ' 个字，现在是 ' . mb_strlen($v) . ' 个。';
    }
    return null;
}

/** path => Chinese message; empty when valid. */
function site_validate(array $site): array
{
    $err = [];
    $reg = field_registry();
    $fields = $site['fields'] ?? null;
    if (!is_array($fields)) {
        return ['fields' => '网站文字的数据坏了。'];
    }
    foreach ($fields as $k => $v) {
        if (!isset($reg[$k])) {
            $err['fields.' . $k] = '没有这一项：' . $k;
        }
    }
    foreach ($reg as $k => $f) {
        if (!array_key_exists($k, $fields)) {
            $err['fields.' . $k] = '缺少这一项。';
            continue;
        }
        $p = field_problem($k, $fields[$k]);
        if ($p !== null) {
            $err['fields.' . $k] = $p;
        }
    }
    $faq = $site['faq'] ?? null;
    if (!is_array($faq) || !array_is_list($faq) || count($faq) < 1 || count($faq) > 12) {
        $err['faq'] = '问答至少要有 1 条，最多 12 条。';
    } else {
        foreach ($faq as $i => $row) {
            if (!is_array($row)) {
                $err["faq.$i"] = '这一条的数据坏了。';
                continue;
            }
            if (!is_string($row['nid'] ?? null) || !preg_match('/^[A-Z0-9\/]{0,12}$/', $row['nid'])) {
                $err["faq.$i.nid"] = '编号只能用大写字母、数字和 /，最多 12 个，也可以空着自动编号。';
            }
            foreach (['q' => 40, 'r' => 12, 'a' => 300] as $k => $max) {
                $p = text_problem($row[$k] ?? null, $max);
                if ($p !== null) {
                    $err["faq.$i.$k"] = $p;
                }
            }
            if (!is_bool($row['subscribe_note'] ?? null)) {
                $err["faq.$i.subscribe_note"] = '这一项只能是开或关。';
            }
        }
    }
    $facts = $site['type_facts'] ?? null;
    if (!is_array($facts) || !array_is_list($facts) || count($facts) < 1 || count($facts) > 9) {
        $err['type_facts'] = '数据格子至少 1 条，最多 9 条。';
    } else {
        foreach ($facts as $i => $row) {
            if (!is_array($row)) {
                $err["type_facts.$i"] = '这一条的数据坏了。';
                continue;
            }
            if (!is_string($row['k'] ?? null) || !preg_match('/^[A-Z0-9]{1,6}$/', $row['k'])) {
                $err["type_facts.$i.k"] = '代码只能用 1 到 6 个大写字母或数字。';
            }
            foreach (['val' => 14, 'line' => 120] as $k => $max) {
                $p = text_problem($row[$k] ?? null, $max);
                if ($p !== null) {
                    $err["type_facts.$i.$k"] = $p;
                }
            }
        }
    }
    $album = $site['album'] ?? null;
    if ($album !== null) {
        if (!is_array($album) || !array_is_list($album) || count($album) < 1 || count($album) > ALBUM_MAX) {
            $err['album'] = '照片至少要留 1 张，最多 ' . ALBUM_MAX . ' 张。';
        } else {
            foreach ($album as $i => $row) {
                if (!is_array($row)) {
                    $err["album.$i"] = '这张照片的数据坏了。';
                    continue;
                }
                if (!is_string($row['src'] ?? null) || album_dims($row['src']) === null) {
                    $err["album.$i.src"] = '这张照片找不到了，请删掉它，再重新上传。';
                }
                $p = text_problem($row['caption'] ?? null, ALBUM_CAPTION_MAX);
                if ($p !== null) {
                    $err["album.$i.caption"] = $p;
                }
                $p = text_problem($row['alt'] ?? null, ALBUM_ALT_MAX, false);
                if ($p !== null) {
                    $err["album.$i.alt"] = $p;
                }
            }
        }
    }
    return $err;
}

// ---------- 关于 · INSET A photos (the album) ----------

const ALBUM_SEED_SRC = 'assets/about.webp';
const ALBUM_SEED_DIMS = [1920, 1086];
const ALBUM_MAX = 8;
const ALBUM_CAPTION_MAX = 60;
const ALBUM_ALT_MAX = 150;
const UPLOAD_REL_RE = '#^uploads/\d{4}/\d{2}/[0-9a-f]{16}\.(?:webp|jpg)$#';

/** The one-photo album a site had before photos were editable: today's picture with the two legacy fields. */
function album_from_fields(array $fields): array
{
    return [['src' => ALBUM_SEED_SRC, 'caption' => (string) ($fields['about.figcaption'] ?? ''), 'alt' => (string) ($fields['about.img_alt'] ?? '')]];
}

/** The album to render: the saved list, or (content saved before the album existed) the one photo from the fields. */
function site_album(array $site): array
{
    $a = $site['album'] ?? null;
    if (is_array($a) && array_is_list($a) && $a) {
        return $a;
    }
    return album_from_fields(site_upgrade_fields(is_array($site['fields'] ?? null) ? $site['fields'] : []));
}

/** [w, h] of a photo the album may show (the bundled one, or an upload in the index); null for anything else. */
function album_dims(string $src): ?array
{
    if ($src === ALBUM_SEED_SRC) {
        return ALBUM_SEED_DIMS;
    }
    if (!preg_match(UPLOAD_REL_RE, $src)) {
        return null;
    }
    $i = uploads_index()['items'][$src] ?? null;
    return is_array($i) && (int) ($i['w'] ?? 0) > 0 ? [(int) $i['w'], (int) $i['h']] : null;
}

/**
 * Section reorder (owner request 2026-10-15, SPEC §18 item 24): saved copy that still holds an old default gets the new
 * one, so a site installed before the reorder shows the new numbering after the next publish. Keys never change; a value
 * the owner wrote themselves is left alone.
 */
const FIELD_UPGRADED_DEFAULTS = [
    'log.kicker' => ['02 / LOG' => '03 / LOG'],
    'type.kicker' => ['03 / TYPE' => '02 / TYPE'],
    'apch.done' => ['MINIMUMS。跑道可见，继续。' => '落地，滑行到停机位，看看最近飞了什么。'],
];

function site_upgrade_fields(array $fields): array
{
    foreach (FIELD_UPGRADED_DEFAULTS as $k => $map) {
        if (is_string($fields[$k] ?? null) && isset($map[$fields[$k]])) {
            $fields[$k] = $map[$fields[$k]];
        }
    }
    return $fields;
}

/** Canonical shape: line endings, missing keys from the seed or registry defaults, typed rows. Unknown keys are kept for site_validate to reject. */
function site_normalize(array $input): array
{
    $reg = field_registry();
    $seedFile = APP_DIR . '/seed/site.baseline.json';
    $seed = is_file($seedFile) ? (json_read($seedFile, [])['fields'] ?? []) : [];
    $fields = [];
    $in = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    foreach ($reg as $k => $f) {
        $v = $in[$k] ?? ($seed[$k] ?? $f['default']);
        if (is_string($v)) {
            // pasted text: CRLF from Windows/WeChat, tabs from a spreadsheet (invisible on a phone, so never an error)
            $v = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $v);
            if (!$f['multiline']) {
                $v = str_replace("\n", ' ', $v);
            }
        }
        $fields[$k] = $v;
    }
    $fields = site_upgrade_fields($fields);
    foreach ($in as $k => $v) {
        if (!isset($reg[$k])) {
            $fields[$k] = $v;
        }
    }
    $faq = [];
    foreach ((is_array($input['faq'] ?? null) ? $input['faq'] : []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $faq[] = [
            'nid' => is_string($row['nid'] ?? null) ? strtoupper(trim($row['nid'])) : '',
            'q' => is_string($row['q'] ?? null) ? trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $row['q'])) : '',
            'r' => is_string($row['r'] ?? null) ? trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $row['r'])) : '',
            'a' => is_string($row['a'] ?? null) ? trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $row['a'])) : '',
            'subscribe_note' => (bool) ($row['subscribe_note'] ?? false),
        ];
    }
    $facts = [];
    foreach ((is_array($input['type_facts'] ?? null) ? $input['type_facts'] : []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $facts[] = [
            'k' => is_string($row['k'] ?? null) ? strtoupper(trim($row['k'])) : '',
            'val' => is_string($row['val'] ?? null) ? trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $row['val'])) : '',
            'line' => is_string($row['line'] ?? null) ? trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $row['line'])) : '',
        ];
    }
    $album = [];
    if (is_array($input['album'] ?? null)) {
        $clean = fn($v) => is_string($v) ? trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $v)) : '';
        foreach ($input['album'] as $row) {
            if (is_array($row)) {
                $album[] = ['src' => is_string($row['src'] ?? null) ? trim($row['src']) : '', 'caption' => $clean($row['caption'] ?? ''), 'alt' => $clean($row['alt'] ?? '')];
            }
        }
    } else {
        $album = album_from_fields($fields);
    }
    if ($album) {
        // the first photo's words also sit in the two legacy keys (see the registry note)
        if ($album[0]['caption'] !== '') {
            $fields['about.figcaption'] = $album[0]['caption'];
        }
        $alt = $album[0]['alt'] !== '' ? $album[0]['alt'] : $album[0]['caption'];
        if ($alt !== '') {
            $fields['about.img_alt'] = $alt;
        }
    }
    $out = $input;
    $out['fields'] = $fields;
    $out['faq'] = $faq;
    $out['type_facts'] = $facts;
    $out['album'] = $album;
    return $out;
}
