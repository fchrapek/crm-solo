<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Parser\Block as BlockParser;
use League\CommonMark\Extension\ConfigurableExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Parser\MarkdownParser;
use League\Config\ConfigurationBuilderInterface;
use Throwable;

/**
 * The block structure of a markdown text by line number (1-based, lines split
 * on \r\n, \n or \r), read with league/commonmark's block parser: which lines
 * are code (fenced or indented, inside lists and blockquotes too), which lines
 * form one paragraph or heading, and every heading with its level, ATX or
 * Setext. Inline parsing is left out, since only block positions are needed.
 */
final class MarkdownBlocks
{
    /** Above this many lines the parse tree costs more memory than a write should spend. */
    public const MAX_LINES = 20000;

    private static ?MarkdownParser $parser = null;

    /**
     * @param  array<int, true>  $codeLines
     * @param  array<int, int>  $groups  first line => last line of a paragraph or heading
     * @param  list<array{level: int, start: int, end: int}>  $headings
     */
    private function __construct(
        public readonly array $codeLines,
        public readonly array $groups,
        public readonly array $headings,
    ) {}

    /** Null when the text has too many lines to parse, or cannot be parsed (invalid UTF-8). */
    public static function parse(string $text): ?self
    {
        if (preg_match_all('/\r\n|\n|\r/', $text) > self::MAX_LINES) {
            return null;
        }

        try {
            $document = self::parser()->parse($text);
        } catch (Throwable) {
            return null;
        }

        $codeLines = [];
        $groups = [];
        $headings = [];
        $walker = $document->walker();
        while ($event = $walker->next()) {
            $node = $event->getNode();
            if (! $event->isEntering()) {
                continue;
            }
            if ($node instanceof FencedCode || $node instanceof IndentedCode) {
                for ($line = (int) $node->getStartLine(); $line <= (int) $node->getEndLine(); $line++) {
                    $codeLines[$line] = true;
                }
            } elseif ($node instanceof Paragraph || $node instanceof Heading) {
                $groups[(int) $node->getStartLine()] = (int) $node->getEndLine();
                if ($node instanceof Heading) {
                    $headings[] = ['level' => $node->getLevel(), 'start' => (int) $node->getStartLine(), 'end' => (int) $node->getEndLine()];
                }
            }
        }

        return new self($codeLines, $groups, $headings);
    }

    private static function parser(): MarkdownParser
    {
        if (self::$parser !== null) {
            return self::$parser;
        }

        $environment = new Environment;
        // The CommonMark settings without its inline parsers and renderers.
        $environment->addExtension(new class implements ConfigurableExtensionInterface
        {
            public function configureSchema(ConfigurationBuilderInterface $builder): void
            {
                (new CommonMarkCoreExtension)->configureSchema($builder);
            }

            public function register(EnvironmentBuilderInterface $environment): void
            {
                $environment
                    ->addBlockStartParser(new BlockParser\BlockQuoteStartParser, 70)
                    ->addBlockStartParser(new BlockParser\HeadingStartParser, 60)
                    ->addBlockStartParser(new BlockParser\FencedCodeStartParser, 50)
                    ->addBlockStartParser(new BlockParser\HtmlBlockStartParser, 40)
                    ->addBlockStartParser(new BlockParser\ThematicBreakStartParser, 20)
                    ->addBlockStartParser(new BlockParser\ListBlockStartParser, 10)
                    ->addBlockStartParser(new BlockParser\IndentedCodeStartParser, -100);
            }
        });

        return self::$parser = new MarkdownParser($environment);
    }
}
