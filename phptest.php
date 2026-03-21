<?php
ini_set("display_errors", 1);
echo "ZipArchive: " . (class_exists("ZipArchive") ? "YES" : "NO") . "\n";
echo "PHP ini: " . php_ini_loaded_file() . "\n";
echo "Extensions: " . implode(", ", array_filter(get_loaded_extensions(), fn($e) => in_array($e, ["zip","zlib","mbstring"]))) . "\n";
?>