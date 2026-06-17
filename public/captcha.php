<?php
// 图形验证码生成器 (SVG, 无需 GD 扩展)
// ----------------------------------------------------
require_once __DIR__ . '/../src/Auth.php';
Auth::initSession();

// 生成 4 位验证码 (去除易混淆字符 0/O/1/I/l)
$chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['captcha_code'] = $code;

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$w = 130;
$h = 44;
$colors = ['#6366f1', '#8b5cf6', '#ec4899', '#0ea5e9', '#10b981'];

echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
echo '<rect width="100%" height="100%" fill="#0f172a"/>';

// 干扰线
for ($i = 0; $i < 5; $i++) {
    $x1 = random_int(0, $w); $y1 = random_int(0, $h);
    $x2 = random_int(0, $w); $y2 = random_int(0, $h);
    $c = $colors[random_int(0, count($colors) - 1)];
    echo '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="' . $c . '" stroke-width="1" opacity="0.4"/>';
}

// 干扰点
for ($i = 0; $i < 30; $i++) {
    $cx = random_int(0, $w); $cy = random_int(0, $h);
    echo '<circle cx="' . $cx . '" cy="' . $cy . '" r="1" fill="#94a3b8" opacity="0.5"/>';
}

// 字符 (随机旋转/颜色)
$len = strlen($code);
for ($i = 0; $i < $len; $i++) {
    $ch = htmlspecialchars($code[$i], ENT_QUOTES);
    $x = 18 + $i * 28;
    $y = random_int(28, 34);
    $rot = random_int(-25, 25);
    $c = $colors[random_int(0, count($colors) - 1)];
    echo '<text x="' . $x . '" y="' . $y . '" font-family="monospace" font-size="26" font-weight="bold" fill="' . $c . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')">' . $ch . '</text>';
}

echo '</svg>';
