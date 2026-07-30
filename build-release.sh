#!/bin/bash
set -e

VERSION="$1"
if [ -z "$VERSION" ]; then
  echo "Usage: bash build-release.sh <version>"
  exit 1
fi

echo "Building release v${VERSION}..."

# Keep every public version declaration in sync.
VERSION="${VERSION}" perl -pi -e 's/^( \* Version: ).*/$1$ENV{VERSION}/' shift64-woo-search.php
VERSION="${VERSION}" perl -pi -e 's/^( \* Version: ).*/$1$ENV{VERSION}/' mu-plugins/shift64-woo-search-bootstrap.php
VERSION="${VERSION}" perl -pi -e 's/(SHIFT64_WOO_SEARCH_VERSION'"'"', '"'"')[^'"'"']+('"'"')/$1$ENV{VERSION}$2/' shift64-woo-search.php
VERSION="${VERSION}" perl -pi -e 's/^(Stable tag: ).*/$1$ENV{VERSION}/' readme.txt
npm version "${VERSION}" --no-git-tag-version --allow-same-version >/dev/null

DIR_NAME="shift64-woo-search"

# Create clean ZIP with consistent directory name
TMPDIR=$(mktemp -d)
PLUGIN_DIR="${TMPDIR}/${DIR_NAME}"
mkdir -p "${PLUGIN_DIR}"

# The Astro Starlight site is development documentation, not plugin runtime code.
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
	--exclude='.circleci' \
  --exclude='.claude' \
  --exclude='.agents' \
  --exclude='.ai' \
  --exclude='.husky' \
  --exclude='.playwright-mcp' \
	--exclude='.vscode' \
	--exclude='.editorconfig' \
	--exclude='.distignore' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='.releaserc.json' \
  --exclude='.gitignore' \
  --exclude='.phpcs.xml.dist' \
  --exclude='phpcs.xml' \
  --exclude='phpunit.xml' \
	--exclude='phpunit.xml.dist' \
  --exclude='.phpunit.result.cache' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='build-release.sh' \
  --exclude='*.md' \
  --exclude='.env' \
  --exclude='.env.example' \
  --exclude='tests' \
  --exclude='bin' \
  --exclude='docs' \
  --exclude='test-results' \
  --exclude='playwright-report' \
  --exclude='playwright.config.*' \
  --exclude='*.sql' \
  --exclude='*.tar.gz' \
  --exclude='*.zip' \
  . "${PLUGIN_DIR}/"

cd "${TMPDIR}"
zip -qr "shift64-woo-search.zip" "${DIR_NAME}/"
mv -f "shift64-woo-search.zip" "${OLDPWD}/shift64-woo-search.zip"
cd "${OLDPWD}"
rm -rf "${TMPDIR}"

echo "Created shift64-woo-search.zip (v${VERSION}, dir: ${DIR_NAME})"
