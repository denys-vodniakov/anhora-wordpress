const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
  path.join(
    __dirname,
    '..',
    'anhora',
    'includes',
    'woocommerce',
    'class-anhora-woo-mapper.php'
  ),
  'utf8'
);
const syncSource = fs.readFileSync(
  path.join(
    __dirname,
    '..',
    'anhora',
    'includes',
    'woocommerce',
    'class-anhora-woo-catalog-sync.php'
  ),
  'utf8'
);

test('variable products retain every published WooCommerce variation', () => {
  assert.match(source, /\$product->get_children\(\)/);
  assert.doesNotMatch(source, /get_available_variations\s*\(/);
  assert.match(
    source,
    /'publish'\s*!==\s*\$variation->get_status\(\)/
  );
  assert.match(source, /if\s*\(\s*!\s*\$variants\s*\)\s*{\s*return null;/s);
});

test('catalog facets are decoded before entering the universal contract', () => {
  assert.match(source, /self::clean_text\(\s*\$category->name\s*\)/);
  assert.match(
    source,
    /self::clean_text\(\s*wc_attribute_label\(/
  );
  assert.match(source, /html_entity_decode\s*\(/);
  assert.match(source, /ENT_QUOTES\s*\|\s*ENT_HTML5/);
});

test('a catalog contract upgrade queues one outbound full snapshot', () => {
  assert.match(syncSource, /public const CONTRACT_VERSION\s*=\s*2;/);
  assert.match(syncSource, /wp_schedule_single_event\([^;]+UPGRADE_HOOK/s);
  assert.match(syncSource, /\$result\s*=\s*self::sync_full\(\)/);
  assert.match(
    syncSource,
    /update_option\(\s*self::CONTRACT_OPTION,\s*self::CONTRACT_VERSION,\s*false\s*\)/s
  );
  assert.match(syncSource, /commit_snapshot[\s\S]+CONTRACT_OPTION/);
});
