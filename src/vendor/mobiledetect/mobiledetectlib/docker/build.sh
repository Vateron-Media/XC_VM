echo "Start building ..."
rm -rf vendor/*
rm -f composer.lock composer.phar
set -xe
# Install composer with dev dependencies so we can run tests.
# Compose installs by default the dev dependencies.
composer install
