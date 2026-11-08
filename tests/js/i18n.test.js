import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setLanguage, translate, t, detectLanguage, language, LANGUAGES, misses } from '../../public/js/i18n.js';
import { DE } from '../../public/js/i18n-de.js';

test('exact keys and patterns', () => {
  setLanguage('de');
  assert.equal(language(), 'de');
  assert.equal(translate('Print'), 'Drucken');
  assert.equal(translate('  Start   over '), 'Neu beginnen', 'whitespace does not matter');
  assert.equal(translate('12 mm tape · 76 px at 180 dpi'), '12 mm Band · 76 px bei 180 dpi');
  assert.equal(translate('3 labels printed · 75.6 mm'), '3 Etiketten gedruckt · 75.6 mm');
  assert.equal(translate('Black on White'), 'Schwarz auf Weiß', 'parts that are keys are translated too');
  assert.equal(translate('Next number for {n}'), 'Nächste Nummer für {n}');
  assert.equal(translate('Something new'), null);
  assert.equal(translate('Drucken'), null);
  assert.equal(t('Something new'), 'Something new');
  assert.equal(t('Print'), 'Drucken');
  setLanguage('en');
  assert.equal(translate('Print'), null);
  assert.equal(t('Print'), 'Print');
  setLanguage('xx');
  assert.equal(language(), 'en');
  assert.equal(misses.size, 0);
  assert.deepEqual(LANGUAGES.map((l) => l[0]), ['en', 'de']);
});

test('language detection: saved choice, then the browser', () => {
  assert.equal(detectLanguage('de', ['en-US']), 'de');
  assert.equal(detectLanguage('en', ['de-DE']), 'en');
  assert.equal(detectLanguage('', ['fr-FR', 'de-AT', 'en']), 'de');
  assert.equal(detectLanguage('xx', ['en-GB']), 'en');
  assert.equal(detectLanguage(null, ['fr']), 'en');
  assert.equal(detectLanguage(undefined), 'en');
});

test('the German dictionary is complete and consistent', () => {
  for (const [key, value] of Object.entries(DE)) {
    assert.ok(value.trim(), `empty translation for "${key}"`);
    const names = (s) => [...s.matchAll(/\{([\w ]+)\}/g)].map((m) => m[1]).sort();
    // every placeholder of the translation comes from the key (literal ones like {Spaltenname} excepted)
    for (const name of names(value)) {
      if (!names(key).includes(name)) assert.match(name, /^[A-ZÄÖÜ]/, `"${name}" in the translation of "${key}"`);
    }
  }
});
