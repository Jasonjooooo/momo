<?php
// Subscriber list (SPEC §5.8, §9). Email + time + consent text only. No code here sends mail.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const SUB_CAP = 20000;

final class SubscribersFull extends RuntimeException {}

function email_normalize(string $raw): ?string
{
    $e = strtolower(trim($raw));
    if ($e === '' || strlen($e) > 254 || preg_match('/[\x00-\x20\x7F,;<>"]/', $e)) {
        return null;
    }
    if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    $domain = substr($e, strrpos($e, '@') + 1);
    return str_contains($domain, '.') ? $e : null;
}

function subs_path(): string
{
    return data_path('subscribers.json');
}

function subs_read(): array
{
    $d = json_read(subs_path(), ['rev' => 0, 'items' => []]);
    return is_array($d) && is_array($d['items'] ?? null) ? $d : ['rev' => 0, 'items' => []];
}

/** 'new' | 'exists'. Throws SubscribersFull at the cap. The original record wins for a repeated address. */
function sub_add(string $email, string $consent, string $source = 'home'): string
{
    $email = email_normalize($email) ?? throw new InvalidArgumentException('bad email');
    $result = 'exists';
    json_update(subs_path(), function ($d) use ($email, $consent, $source, &$result) {
        $d = is_array($d) && is_array($d['items'] ?? null) ? $d : ['rev' => 0, 'items' => []];
        // Check the cap before the duplicate scan, so a full list answers the same way for an address
        // already on it and a new one; nobody can probe membership by watching for a different reply.
        if (count($d['items']) >= SUB_CAP) {
            throw new SubscribersFull('full');
        }
        foreach ($d['items'] as $it) {
            if ($it['email'] === $email) {
                $result = 'exists';
                return $d;
            }
        }
        $d['items'][] = ['email' => $email, 'created_at' => now_iso(), 'consent' => $consent, 'source' => $source];
        $d['rev'] = (int) ($d['rev'] ?? 0) + 1;
        $result = 'new';
        return $d;
    }, ['rev' => 0, 'items' => []]);
    return $result;
}

/** Newest first, filtered by a case-insensitive substring. */
function sub_list(string $q = '', int $page = 1, int $per = 50): array
{
    $items = array_reverse(subs_read()['items']);
    $q = mb_strtolower(trim($q));
    if ($q !== '') {
        $items = array_values(array_filter($items, fn($it) => str_contains($it['email'], $q)));
    }
    $total = count($items);
    $pages = max(1, (int) ceil($total / max(1, $per)));
    $page = max(1, min($page, $pages));
    return ['items' => array_slice($items, ($page - 1) * $per, $per), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function sub_count_since(int $ts): int
{
    $n = 0;
    foreach (subs_read()['items'] as $it) {
        if (strtotime($it['created_at']) >= $ts) {
            $n++;
        }
    }
    return $n;
}

function sub_delete(array $emails): int
{
    $set = array_flip(array_map(fn($e) => strtolower(trim((string) $e)), $emails));
    $removed = 0;
    json_update(subs_path(), function ($d) use ($set, &$removed) {
        $d = is_array($d) && is_array($d['items'] ?? null) ? $d : ['rev' => 0, 'items' => []];
        $keep = [];
        foreach ($d['items'] as $it) {
            if (isset($set[$it['email']])) {
                $removed++;
            } else {
                $keep[] = $it;
            }
        }
        $d['items'] = $keep;
        $d['rev'] = (int) ($d['rev'] ?? 0) + 1;
        return $d;
    }, ['rev' => 0, 'items' => []]);
    if ($removed > 0) {
        audit('subs_deleted', (string) $removed);
    }
    return $removed;
}

function sub_all_emails(): array
{
    return array_map(fn($it) => $it['email'], subs_read()['items']);
}

function csv_cell(string $v): string
{
    $t = ltrim($v, " \t\r\n\u{3000}");
    // a spreadsheet would read these as a formula (also after leading spaces or a tab): make them plain text
    if ($v !== '' && (($t !== '' && str_contains('=+-@', $t[0])) || $v[0] === "\t" || $v[0] === "\r")) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/** UTF-8 with BOM, CRLF, every cell quoted, formula-looking cells prefixed with '. */
function sub_csv(): string
{
    $rows = ["\u{FEFF}" . implode(',', array_map('csv_cell', ['邮箱', '订阅时间', '同意的内容']))];
    foreach (subs_read()['items'] as $it) {
        $rows[] = implode(',', array_map('csv_cell', [$it['email'], local_time($it['created_at']), (string) ($it['consent'] ?? '')]));
    }
    return implode("\r\n", $rows) . "\r\n";
}
