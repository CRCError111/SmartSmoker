<?php
/**
 * Генератор иконок PWA
 * Запустить на сервере: php generate-icons.php
 * Требует расширение GD
 */

if (!extension_loaded('gd')) {
    die("GD extension not available\n");
}

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$outputDir = __DIR__ . '/icons';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    // Фон: фиолетовый градиент (имитация через заливку)
    $bg = imagecolorallocate($img, 124, 58, 237); // #7C3AED
    imagefill($img, 0, 0, $bg);

    // Скруглённые углы (маска)
    $radius = (int)($size * 0.22);
    imagefilledrectangle($img, $radius, 0, $size - $radius, $size, $bg);
    imagefilledrectangle($img, 0, $radius, $size, $size - $radius, $bg);
    imagefilledellipse($img, $radius, $radius, $radius * 2, $radius * 2, $bg);
    imagefilledellipse($img, $size - $radius, $radius, $radius * 2, $radius * 2, $bg);
    imagefilledellipse($img, $radius, $size - $radius, $radius * 2, $radius * 2, $bg);
    imagefilledellipse($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, $bg);

    // Эмодзи 🔥 через текст (ASCII fallback)
    $white = imagecolorallocate($img, 255, 255, 255);
    $fontSize = (int)($size * 0.45);
    $text = 'SS'; // Smart Smoker

    // Центрируем текст
    $font = 5; // встроенный шрифт GD
    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);
    $textW = strlen($text) * $charW;
    $x = (int)(($size - $textW) / 2);
    $y = (int)(($size - $charH) / 2);
    imagestring($img, $font, $x, $y, $text, $white);

    $filename = "$outputDir/icon-{$size}x{$size}.png";
    imagepng($img, $filename);
    imagedestroy($img);
    echo "Created: $filename\n";
}

// Badge icon (72x72, тёмный фон)
$badge = imagecreatetruecolor(72, 72);
$bg = imagecolorallocate($badge, 124, 58, 237);
imagefill($badge, 0, 0, $bg);
$white = imagecolorallocate($badge, 255, 255, 255);
imagestring($badge, 3, 26, 28, 'SS', $white);
imagepng($badge, "$outputDir/badge-72x72.png");
imagedestroy($badge);
echo "Created: $outputDir/badge-72x72.png\n";

echo "Done!\n";
