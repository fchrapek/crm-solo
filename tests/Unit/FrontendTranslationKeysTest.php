<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * I18NextTranslationsLoader serves every Laravel `:name` placeholder as
 * `{{name}}`, in keys and values alike, so a frontend key written with
 * `:name` never matches a served key and reaches the screen as raw text.
 */
final class FrontendTranslationKeysTest extends TestCase
{
    public function test_frontend_translation_keys_use_double_brace_placeholders(): void
    {
        $offenders = [];

        foreach ($this->frontendSources() as $path) {
            foreach ($this->colonPlaceholderKeys((string) file_get_contents($path)) as $key) {
                $offenders[] = basename($path).": t('{$key}')";
            }
        }

        $this->assertSame([], $offenders, 'Use {{word}} placeholders in frontend keys, not :word');
    }

    public function test_the_guard_catches_a_colon_placeholder_key(): void
    {
        $this->assertSame(['on :client'], $this->colonPlaceholderKeys("t('on :client', { client })"));
        $this->assertSame(['on :client'], $this->colonPlaceholderKeys('t(`on :client`)'));
        $this->assertSame(['on :client'], $this->colonPlaceholderKeys("/* label */ t('on :client', { client })"));
    }

    public function test_the_guard_ignores_labels_urls_comments_and_template_expressions(): void
    {
        $source = implode("\n", [
            "t('Parent: {{name}} at https://x.test 10:30')",
            "// t('old :name')",
            ' * t(\'docblock :name\')',
            't(`Hello ${ok ? name :fallback}`)',
        ]);

        $this->assertSame([], $this->colonPlaceholderKeys($source));
    }

    public function test_polish_plural_keys_carry_every_form(): void
    {
        $pl = json_decode((string) file_get_contents(__DIR__.'/../../lang/pl.json'), true, flags: JSON_THROW_ON_ERROR);
        $en = json_decode((string) file_get_contents(__DIR__.'/../../lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        foreach (array_keys($pl + $en) as $key) {
            if (! str_ends_with((string) $key, '_one')) {
                continue;
            }
            $base = mb_substr((string) $key, 0, -4);
            foreach (['_one', '_few', '_many', '_other'] as $form) {
                if (! array_key_exists($base.$form, $pl)) {
                    $missing[] = "pl: {$base}{$form}";
                }
            }
            foreach (['_one', '_other'] as $form) {
                if (! array_key_exists($base.$form, $en)) {
                    $missing[] = "en: {$base}{$form}";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * Literal first arguments of t() that carry a Laravel-style placeholder.
     * Lines that are only a comment (`//` or a docblock `*`) are skipped, and so are template literals with an
     * expression, whose key is only known at run time.
     *
     * @return list<string>
     */
    private function colonPlaceholderKeys(string $source): array
    {
        $code = preg_replace('/^\s*(\/\/|\*(?!\/)).*$/m', '', $source);
        preg_match_all('/\bt\(\s*([\'"`])((?:\\\\.|(?!\1).)*)\1/s', (string) $code, $matches, PREG_SET_ORDER);
        $keys = [];

        foreach ($matches as [, $quote, $key]) {
            if ($quote === '`' && str_contains($key, '${')) {
                continue;
            }
            if (preg_match('/(?<![\w:]):[A-Za-z_]\w*/', $key) === 1) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function frontendSources(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/js'));

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.tsx?$/', $file->getFilename()) === 1) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files);

        return $files;
    }
}
