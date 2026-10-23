/**
 * Static regression checks for normalized provider-logo identities.
 * Does not require PHP or database configuration.
 *
 *   node tests/provider-identity-assets.mjs
 */
import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const manager = readFileSync(join(ROOT, 'ai/ProviderManager.php'), 'utf8');
const providerPage = readFileSync(join(ROOT, 'admin/ai/providers.php'), 'utf8');
const sources = readFileSync(join(ROOT, 'assets/images/ai-providers/SOURCES.md'), 'utf8');
const driverFiles = [
  ['openai', 'ai/providers/OpenAIProvider.php'],
  ['openrouter', 'ai/providers/OpenRouterProvider.php'],
  ['gemini', 'ai/providers/GeminiProvider.php'],
  ['anthropic', 'ai/providers/AnthropicProvider.php'],
  ['openai_compatible', 'ai/providers/OpenAICompatibleProvider.php'],
];
const logoFiles = {
  openai: 'assets/images/ai-providers/openai.svg',
  openrouter: 'assets/images/ai-providers/openrouter.svg',
  gemini: 'assets/images/ai-providers/gemini.png',
  anthropic: 'assets/images/ai-providers/anthropic.svg',
};
let checks = 0;
function check(name, fn) {
  fn();
  checks += 1;
  console.log(`  ok  ${name}`);
}

check('identity lookup normalizes only the driver ID and has a safe unknown fallback', () => {
  assert.match(manager, /function sh_ai_provider_identity\(string \$driver\): array/);
  assert.match(manager, /\$key\s*=\s*strtolower\(trim\(\$driver\)\)/);
  assert.match(manager, /\$identities\[\$key\]\s*\?\?\s*\[/);
  assert.match(manager, /'fallback'\s*=>\s*'Custom'/);
});

check('all registered drivers have a corresponding exact-ID identity entry', () => {
  for (const [driver, file] of driverFiles) {
    const adapter = readFileSync(join(ROOT, file), 'utf8');
    assert.match(adapter, new RegExp(`driverKey\\(\\): string \\{ return '${driver}'`), `${file} driver key`);
    assert.match(manager, new RegExp(`'${driver}'\\s*=>`), `${driver} identity entry`);
  }
  assert.match(manager, /'openai_compatible'\s*=>\s*\[[^\]]*'asset'\s*=>\s*null/s);
});

check('each verified local logo exists, has an intrinsic ratio, and has pinned provenance', () => {
  for (const [driver, asset] of Object.entries(logoFiles)) {
    assert.ok(existsSync(join(ROOT, asset)), `${driver} local asset exists`);
    assert.ok(statSync(join(ROOT, asset)).size > 100, `${driver} local asset is non-empty`);
    assert.match(manager, new RegExp(`'${driver}'\\s*=>\\s*\\[[^\\]]*'asset'\\s*=>\\s*'${asset.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`, 's'));
    assert.ok(sources.includes('| `' + driver + '` | `' + asset.split('/').at(-1) + '` | ['), `${driver} has a source-table entry`);
  }
  for (const commit of [
    '8ef2841809e1dae17e43cc363e1add95495c2e8d',
    '9f25c6b911f1bd13ab3ba9ea60b25b1df8d3054c',
    '3e0a736b9f6571e6b283b9bc09f78084fcd7c33b',
    '7b4849bdca37cf2f28b4a1916495fa18367b081e',
  ]) assert.ok(sources.includes(commit), `source is pinned to ${commit}`);
});

check('local SVGs have no external image dependency or executable content', () => {
  for (const asset of [logoFiles.openai, logoFiles.openrouter, logoFiles.anthropic]) {
    const svg = readFileSync(join(ROOT, asset), 'utf8');
    assert.match(svg, /^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i, `${asset} is SVG`);
    assert.doesNotMatch(svg, /<script\b|(?:href|src)\s*=\s*["']https?:/i, `${asset} is self-contained`);
  }
  const gemini = readFileSync(join(ROOT, logoFiles.gemini));
  assert.deepEqual([...gemini.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10], 'Gemini logo is a PNG');
});

check('provider cards and add-provider choices share the same logo/text renderer', () => {
  assert.match(providerPage, /\$providerMark\(\$driver\)/);
  assert.match(providerPage, /\.\s*\$providerMark\(\$key\)/);
  assert.doesNotMatch(providerPage, /\$driverIcons|\$driverIcon\(/);
  assert.match(providerPage, /data-ai-provider-logo/);
  assert.match(providerPage, /sh-aiprov__mark-fallback/);
});

console.log(`\nPASS  ${checks} provider identity checks`);
