<?php
// The mock supplier's starting catalog (used by run.php group supply and supply-fixture.php). Never deployed.
declare(strict_types=1);

const MOCK_APP_ID = '10086';
const MOCK_APP_KEY = 'mK9#xQ2&vR7!pZ4w';   // has & and # on purpose: urldecode/http_build_query must not break the signature

function supply_mock_state(): array
{
    $item = fn(array $o) => $o + ['id' => 0, 'description' => '', 'price' => '0', 'user_price' => '0', 'stock' => 100, 'delivery_way' => 0,
        'contact_type' => 0, 'password_status' => 0, 'draft_status' => 0, 'draft_premium' => '0', 'minimum' => 0, 'maximum' => 0,
        'config' => ['category' => [], 'sku' => [], 'wholesale' => []], 'widget' => null, 'seckill_status' => 0];
    return [
        'app_id' => MOCK_APP_ID,
        'app_key' => MOCK_APP_KEY,
        'balance' => 100000,   // ¥1000
        'knobs' => [],
        'calls' => [],
        'trades' => [],
        'by_trade' => [],
        'items' => [
            ['name' => '王者荣耀', 'children' => [
                $item(['id' => 1, 'code' => 'WZRY60', 'name' => '王者荣耀 60 点券（自动发卡）', 'price' => '6.50', 'user_price' => '5.80', 'stock' => 500,
                    'description' => '<p>官方 <b>60 点券</b>兑换码。</p><script>alert(1)</script><p>兑换：游戏内 设置 → 兑换码</p><img src=x onerror=alert(2)><ul><li>不退不换</li><li>到账即时</li></ul>']),
                $item(['id' => 2, 'code' => 'WZRYMAN', 'name' => '王者荣耀 皮肤礼包（人工）', 'price' => '30', 'user_price' => '28', 'stock' => 20, 'delivery_way' => 1,
                    'description' => '人工发货，一般 10 分钟内。']),
            ]],
            ['name' => '原神 <i>月卡</i>', 'children' => [
                $item(['id' => 3, 'code' => 'YSRACE', 'name' => '原神 充值卡', 'price' => '30', 'user_price' => '25', 'stock' => 80,
                    'config' => ['category' => ['空月祝福' => '25.00', '648 创世结晶' => '600.00'], 'sku' => [], 'wholesale' => []]]),
                $item(['id' => 4, 'code' => 'XSSNAME', 'name' => '<img src=x onerror=alert(9)>"><script>alert(8)</script>点卡', 'price' => '10', 'user_price' => '9', 'stock' => 9,
                    'description' => '<a href="javascript:alert(3)">点这里</a> [x](javascript:alert(4)) <iframe src="https://evil.example"></iframe>']),
            ]],
            ['name' => '暂不支持的', 'children' => [
                $item(['id' => 5, 'code' => 'SKU01', 'name' => '要选规格的卡', 'user_price' => '10', 'config' => ['category' => [], 'sku' => ['面值' => ['10元' => '0', '50元' => '40']], 'wholesale' => []]]),
                $item(['id' => 6, 'code' => 'WIDGET01', 'name' => '要填游戏账号的直充', 'user_price' => '10', 'widget' => [['name' => 'account', 'cn' => '游戏账号', 'type' => 'text']]]),
                $item(['id' => 7, 'code' => 'PHONE01', 'name' => '要手机号的卡', 'user_price' => '10', 'contact_type' => 1]),
                $item(['id' => 8, 'code' => 'PASS01', 'name' => '要查询密码的卡', 'user_price' => '10', 'password_status' => 1]),
                $item(['id' => 9, 'code' => 'MAIL01', 'name' => '要邮箱的卡', 'user_price' => '3', 'contact_type' => 2, 'minimum' => 2, 'maximum' => 5]),
            ]],
        ],
    ];
}
