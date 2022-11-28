#!/bin/bash

read -p "Have you bumped up the version in newspack-custom-content-migrator.php? [y/n] " CONFIRM_VERSION
if [ "y" != "$CONFIRM_VERSION" ]; then exit; fi
echo "Creating a release archive..."

# Reinstall dependencies.
rm -rf vendor 2> /dev/null
composer install

# Flush release folder.
# - repeat rm twice to delete hidden files
rm -rf release 2> /dev/null
rm -rf release 2> /dev/null
mkdir release

# Create zip but exclude dev things.
zip -qr release/newspack-custom-content-migrator.zip ../newspack-custom-content-migrator \
	-x \
	"../newspack-custom-content-migrator/create_release.sh" \
	"../newspack-custom-content-migrator/release/*" \
	"../newspack-custom-content-migrator/bin/*" \
	"../newspack-custom-content-migrator/tests/*" \
	"../newspack-custom-content-migrator/phpunit*" \
	"../newspack-custom-content-migrator/.phpunit*" \
	"../newspack-custom-content-migrator/phpcs*" \
	"../newspack-custom-content-migrator/.phpcs*" \
	"../newspack-custom-content-migrator/.travis.yml" \
	"../newspack-custom-content-migrator/.git*" \
	"../newspack-custom-content-migrator/.git/*" \
	"../newspack-custom-content-migrator/.distignore" \
	"../newspack-custom-content-migrator/.DS_Store" \
	"../newspack-custom-content-migrator/.env*" \
	"../newspack-custom-content-migrator/.idea" \
	"../newspack-custom-content-migrator/*.log*"

echo "Created ./release/newspack-custom-content-migrator.zip"
