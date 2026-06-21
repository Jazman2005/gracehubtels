<?php
$dir = 'c:\\xampp\\htdocs\\msjpay';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($iterator as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'html'])) {
        $lines = file($file->getPathname());
        foreach ($lines as $i => $line) {
            $stripped = preg_replace(['/<\?/', '/\?>/', '/\?\?/', '/\?=/', '/\.php\?/', '/\?[a-zA-Z_]+=/', '/\?:/', '/\?[>\]\)\}]/'], '', $line);
            if (strpos($stripped, '?') !== false) {
                echo $file->getBasename() . ' (' . ($i+1) . '): ' . trim($line) . PHP_EOL;
            }
        }
    }
}
