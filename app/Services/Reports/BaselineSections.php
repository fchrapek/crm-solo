<?php

declare(strict_types=1);

namespace App\Services\Reports;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;

/** Report baseline as Billing lists it: one section per heading with its top-level list items, an untitled one before any heading. */
final class BaselineSections
{
    /**
     * @return list<array{title: string|null, items: int}>
     */
    public function of(?string $markdown): array
    {
        if ($markdown === null || mb_trim($markdown) === '') {
            return [];
        }

        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $document = (new MarkdownParser($environment))->parse($markdown);

        $sections = [];
        $current = null;
        foreach ($document->children() as $block) {
            if ($block instanceof Heading) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['title' => mb_trim(StringContainerHelper::getChildText($block)), 'items' => 0];
            } elseif ($block instanceof ListBlock && $block->parent() instanceof Document) {
                $current ??= ['title' => null, 'items' => 0];
                foreach ($block->children() as $item) {
                    if ($item instanceof ListItem) {
                        $current['items']++;
                    }
                }
            }
        }
        $sections[] = $current ?? ['title' => null, 'items' => 0];

        return $sections;
    }
}
