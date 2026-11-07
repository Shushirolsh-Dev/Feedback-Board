#!/bin/sh
set -eu
mkdir -p "$(dirname "${FEEDBACK_DB_PATH:-/app/storage/feedback.sqlite}")"
chown -R www-data:www-data "$(dirname "${FEEDBACK_DB_PATH:-/app/storage/feedback.sqlite}")"
exec su-exec www-data php -S 0.0.0.0:8080 -t public public/router.php
