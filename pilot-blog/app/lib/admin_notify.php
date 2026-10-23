<?php
// Admin controllers for 微信提醒 (SPEC §21): the PushPlus token (write-only: only its last 4 characters are ever shown),
// the per-event switches, a test message sent right away, and the recent send log. Plain server-rendered forms.
declare(strict_types=1);
defined('BLOG_APP') || exit;

function admin_notify(array $errors = []): void
{
    $settings = settings_get();
    view('notify', [
        'title' => '微信提醒',
        'settings' => $settings,
        'events' => notify_events($settings),
        'token_hint' => notify_token_hint(),
        'token_set_at' => notify_token_set_at(),
        'log' => notify_log(),
        'errors' => $errors,
    ]);
}

function admin_notify_token(): void
{
    $token = trim(post_str('token', 400));
    if ($token === '') {
        flash('error', '没有保存：请先粘贴 token。');
        redirect('notify');
    }
    try {
        notify_token_set($token);
        audit('notify_token_set');
        flash('ok', '已保存，token 结尾是 ' . (string) notify_token_hint() . '。点「发一条测试」看看微信能不能收到。');
    } catch (ValidationError $e) {
        http_response_code(400);
        flash('error', '没有保存：' . (string) reset($e->fields));
        admin_notify($e->fields);
        return;
    } catch (Throwable $e) {
        error_log('admin notify.token: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('notify');
}

function admin_notify_token_remove(): void
{
    try {
        notify_token_remove();
        audit('notify_token_removed');
        flash('ok', 'token 已删除，不再发微信提醒。');
    } catch (Throwable $e) {
        error_log('admin notify.token_remove: ' . get_class($e));
        flash('error', '没有删除：服务器出错了。');
    }
    redirect('notify');
}

function admin_notify_events(): void
{
    $on = [];
    foreach (array_keys(NOTIFY_EVENTS) as $k) {
        $on[$k] = post_str('ev_' . $k, 2) === '1';
    }
    try {
        settings_save(['notify' => $on], (int) post_str('rev', 20));
        flash('ok', '已保存提醒设置。');
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        error_log('admin notify.events: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('notify');
}

/** Sends at once (not after the response) so the page can say whether it worked. */
function admin_notify_test(): void
{
    try {
        $r = notify_deliver('test', '测试：微信提醒接通了', '这是「' . admin_brand() . '」后台发来的测试消息。以后有新订单、买家点了「我已付款」等事，会这样提醒你。'
            . "\n后台：" . notify_admin_url('notify'));
        audit('notify_test', $r['ok'] ? 'ok' : 'failed');
        flash($r['ok'] ? 'ok' : 'error', $r['msg']);
    } catch (Throwable $e) {
        error_log('admin notify.test: ' . get_class($e));
        flash('error', '没有发出：服务器出错了。');
    }
    redirect('notify');
}
