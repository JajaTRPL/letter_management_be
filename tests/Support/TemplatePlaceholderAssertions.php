<?php

namespace Tests\Support;

use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

final class TemplatePlaceholderAssertions
{
    private const PLACEHOLDER_PATTERN = '/\$\{([^}]+)\}/';
    private const LOWER_SNAKE_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /**
     * @return list<string>
     */
    public static function extractPlaceholdersFromDocx(string $path): array
    {
        return self::analyzeDocx($path)['placeholders'];
    }

    /**
     * @return array{placeholders: list<string>, syntax_errors: list<string>}
     */
    public static function analyzeDocx(string $path): array
    {
        $placeholders = [];
        $syntaxErrors = [];

        foreach (self::wordXmlEntries($path) as $entryName => $xml) {
            $analysis = self::analyzeXml($xml);
            $placeholders = array_merge($placeholders, $analysis['placeholders']);

            foreach ($analysis['syntax_errors'] as $error) {
                $syntaxErrors[] = "{$entryName}: {$error}";
            }
        }

        return [
            'placeholders' => self::sortedUnique($placeholders),
            'syntax_errors' => self::sortedUnique($syntaxErrors),
        ];
    }

    /**
     * @return array{placeholders: list<string>, syntax_errors: list<string>}
     */
    public static function analyzeXml(string $xml): array
    {
        $placeholders = [];
        $syntaxErrors = [];

        preg_match_all(self::PLACEHOLDER_PATTERN, $xml, $matches);
        foreach ($matches[1] as $name) {
            $placeholders[] = $name;
            $syntaxErrors = array_merge($syntaxErrors, self::placeholderNameIssues($name));
        }

        $offset = 0;
        while (($start = strpos($xml, '${', $offset)) !== false) {
            $end = strpos($xml, '}', $start + 2);
            if ($end === false) {
                $syntaxErrors[] = 'Unclosed placeholder starts with `${` and has no closing `}`.';
                break;
            }

            $rawName = substr($xml, $start + 2, $end - $start - 2);
            if (str_contains($rawName, '<') || str_contains($rawName, '>')) {
                $syntaxErrors[] = 'Split-run placeholder detected inside `${...}`.';
            }

            $offset = $end + 1;
        }

        $plainText = html_entity_decode((string) preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        preg_match_all(self::PLACEHOLDER_PATTERN, $plainText, $plainMatches);
        foreach ($plainMatches[0] as $index => $placeholder) {
            if (!str_contains($xml, $placeholder)) {
                $syntaxErrors[] = "Split-run placeholder detected for {$placeholder}.";
            }

            $name = $plainMatches[1][$index];
            $placeholders[] = $name;
            $syntaxErrors = array_merge($syntaxErrors, self::placeholderNameIssues($name));
        }

        return [
            'placeholders' => self::sortedUnique($placeholders),
            'syntax_errors' => self::sortedUnique($syntaxErrors),
        ];
    }

    /**
     * @param list<string> $placeholders
     * @param list<string> $allowed
     * @param list<string> $required
     * @param list<string> $forbidden
     * @return array{unknown: list<string>, missing: list<string>, forbidden: list<string>}
     */
    public static function contractViolations(
        array $placeholders,
        array $allowed,
        array $required = [],
        array $forbidden = [],
    ): array {
        $placeholders = self::sortedUnique($placeholders);
        $allowed = self::sortedUnique($allowed);
        $required = self::sortedUnique($required);
        $forbidden = self::sortedUnique($forbidden);

        return [
            'unknown' => self::sortedUnique(array_values(array_diff($placeholders, $allowed))),
            'missing' => self::sortedUnique(array_values(array_diff($required, $placeholders))),
            'forbidden' => self::sortedUnique(array_values(array_intersect($placeholders, $forbidden))),
        ];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public static function renderDocxToXml(string $path, array $values): string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'template_contract_render_') . '.docx';

        try {
            $processor = new TemplateProcessor($path);
            foreach ($values as $name => $value) {
                $processor->setValue($name, (string) ($value ?? ''));
            }

            $processor->saveAs($outputPath);

            return implode("\n", self::wordXmlEntries($outputPath));
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function unresolvedPlaceholdersInXml(string $xml): array
    {
        return self::analyzeXml($xml)['placeholders'];
    }

    /**
     * @return array<string, string>
     */
    public static function wordXmlEntries(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("DOCX file not found: {$path}");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open DOCX file: {$path}");
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || !self::isTemplateXmlEntry($name)) {
                    continue;
                }

                $contents = $zip->getFromName($name);
                if (is_string($contents)) {
                    $entries[$name] = $contents;
                }
            }

            if ($entries === []) {
                throw new RuntimeException("DOCX file has no inspectable Word XML entries: {$path}");
            }

            ksort($entries);

            return $entries;
        } finally {
            $zip->close();
        }
    }

    private static function isTemplateXmlEntry(string $name): bool
    {
        return $name === 'word/document.xml'
            || preg_match('/^word\/header\d+\.xml$/', $name) === 1
            || preg_match('/^word\/footer\d+\.xml$/', $name) === 1
            || in_array($name, ['word/footnotes.xml', 'word/endnotes.xml'], true);
    }

    /**
     * @return list<string>
     */
    private static function placeholderNameIssues(string $name): array
    {
        $issues = [];
        $placeholder = '${' . $name . '}';

        if (preg_match('/\s/', $name) === 1) {
            $issues[] = "Placeholder `{$placeholder}` contains whitespace.";
        }

        if (preg_match(self::LOWER_SNAKE_PATTERN, $name) !== 1) {
            $issues[] = "Placeholder `{$placeholder}` is not lower_snake_case.";
        }

        return $issues;
    }

    /**
     * @param array<int, string> $values
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
