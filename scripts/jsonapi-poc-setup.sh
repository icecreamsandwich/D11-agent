#!/usr/bin/env bash
# JSON:API POC setup — run from project root: ddev exec bash scripts/jsonapi-poc-setup.sh
# (or: bash scripts/jsonapi-poc-setup.sh if drush is on your PATH inside ddev ssh)
set -e

DRUSH="vendor/bin/drush"

echo "== Enabling modules (jsonapi + basic_auth are in core) =="
$DRUSH en jsonapi basic_auth -y

echo "== Allowing all JSON:API operations (read + create/update/delete) =="
$DRUSH config:set jsonapi.settings read_only 0 -y

echo "== Permissions =="
# Anonymous + authenticated: read content and user profiles.
$DRUSH role:perm:add anonymous 'access content,access user profiles'
$DRUSH role:perm:add authenticated 'access content,access user profiles'

# Dedicated role for API write access.
$DRUSH role:create api_client 'API Client' 2>/dev/null || echo "role api_client already exists"
$DRUSH role:perm:add api_client 'create article content,edit own article content,delete own article content'

echo "== API user (apiuser / api123) =="
$DRUSH user:create apiuser --mail=apiuser@example.com --password=api123 2>/dev/null || echo "user apiuser already exists"
$DRUSH user:role:add api_client apiuser

echo "== Sample articles =="
$DRUSH php:eval '
$storage = \Drupal::entityTypeManager()->getStorage("node");
$existing = $storage->loadByProperties(["type" => "article", "title" => "JSON:API POC Article 1"]);
if (!$existing) {
  for ($i = 1; $i <= 5; $i++) {
    $storage->create([
      "type" => "article",
      "title" => "JSON:API POC Article $i",
      "body" => ["value" => "<p>Sample body for POC article $i, exposed via JSON:API.</p>", "format" => "basic_html"],
      "status" => 1,
      "uid" => 1,
    ])->save();
  }
  print "Created 5 sample articles\n";
} else {
  print "Sample articles already exist, skipping\n";
}'

$DRUSH cr

echo ""
echo "Done. Try:"
echo "  https://d-11.ddev.site/jsonapi                       (entry point)"
echo "  https://d-11.ddev.site/jsonapi/node/article          (articles)"
echo "  https://d-11.ddev.site/jsonapi/user/user             (users)"
echo "  https://d-11.ddev.site/poc-app/index.html            (frontend app)"
echo "  Write credentials: apiuser / api123"
