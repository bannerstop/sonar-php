#!/bin/sh
# Installs the dev dependencies for one PHP version into .vendor/<version>, resolved as if that PHP version ran
# Composer (a copy of composer.json with config.platform.php set), so a PHP 7.2 run gets PHPUnit 8, a PHP 8.5 run
# gets PHPUnit 12. Runs inside the composer:2 image, see the Makefile.
set -eu
version="$1"   # e.g. 72
platform="$2"  # e.g. 7.2.5
php -r '
$json = json_decode(file_get_contents("composer.json"), true);
$json["config"]["platform"]["php"] = $argv[1];
file_put_contents("composer-".$argv[2].".json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$platform" "$version"
COMPOSER="composer-$version.json" COMPOSER_VENDOR_DIR="/app/.vendor/$version" \
    composer update --no-interaction --no-progress
