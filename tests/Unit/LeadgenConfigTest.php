<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lead;
use Tests\TestCase;

/**
 * Guards the STRUCTURE of the lead-gen contract (config/leadgen.php), never
 * its vocabulary. Editing the config — adding a pipeline, a stage, a source
 * or a scoring factor, or relabelling any of them — is a supported act and
 * must not break this suite; violating a structural rule the Lead model
 * relies on must always break it.
 *
 * The shipped numbers (assumed rates, targets, thresholds) are illustrative
 * defaults an instance is expected to replace, so nothing here pins a value.
 */
final class LeadgenConfigTest extends TestCase
{
    public function test_sources_are_a_unique_list_of_slugs(): void
    {
        $sources = config('leadgen.sources');

        $this->assertIsArray($sources);
        $this->assertNotEmpty($sources);
        $this->assertSame(array_values($sources), $sources, 'sources must be a list, not a map');

        foreach ($sources as $source) {
            $this->assertIsString($source);
            $this->assertNotSame('', mb_trim($source));
        }

        $this->assertSame($sources, array_values(array_unique($sources)), 'source slugs must be unique');
    }

    public function test_every_pipeline_declares_stages_and_an_explicit_entry_stage(): void
    {
        $pipelines = config('leadgen.pipelines');

        $this->assertIsArray($pipelines);
        $this->assertNotEmpty($pipelines);

        foreach ($pipelines as $key => $pipeline) {
            $stages = $pipeline['stages'] ?? null;
            $this->assertIsArray($stages, "{$key}: stages must be declared");
            $this->assertNotEmpty($stages, "{$key}: stages must not be empty");
            $this->assertSame($stages, array_values(array_unique($stages)), "{$key}: stage slugs must be unique");

            if (array_key_exists('label', $pipeline)) {
                $this->assertIsString($pipeline['label'], "{$key}: a declared label must be a string");
                $this->assertNotSame('', mb_trim($pipeline['label']), "{$key}: a declared label must not be blank");
            }

            // Declared, never inferred as stages[0] — an analytics-only head
            // (like kiwwwi's 'visitor') may sit in front of the entry stage.
            $this->assertArrayHasKey('entry_stage', $pipeline, "{$key}: entry_stage must be declared explicitly");
            $this->assertContains($pipeline['entry_stage'], Lead::rowStages($key),
                "{$key}: entry_stage must be a holdable row stage");
        }
    }

    public function test_analytics_only_stages_are_declared_but_excluded_from_row_stages(): void
    {
        foreach (config('leadgen.pipelines') as $key => $pipeline) {
            $analyticsOnly = $pipeline['analytics_only_stages'] ?? [];
            $this->assertIsArray($analyticsOnly, "{$key}: analytics_only_stages must be an array");

            foreach ($analyticsOnly as $stage) {
                $this->assertContains($stage, $pipeline['stages'],
                    "{$key}: an analytics-only stage must still be a declared funnel stage");
            }

            $rowStages = Lead::rowStages($key);
            $this->assertNotEmpty($rowStages, "{$key}: at least one holdable row stage must remain");
            $this->assertSame([], array_intersect($rowStages, $analyticsOnly),
                "{$key}: analytics-only stages must never be holdable by a lead row");
        }
    }

    public function test_entry_stage_overrides_reference_real_sources_and_row_stages(): void
    {
        foreach (config('leadgen.pipelines') as $key => $pipeline) {
            foreach ($pipeline['entry_stage_overrides'] ?? [] as $source => $stage) {
                $this->assertContains($source, config('leadgen.sources'),
                    "{$key}: override source [{$source}] must be a declared source");
                $this->assertContains($stage, Lead::rowStages($key),
                    "{$key}: override stage [{$stage}] must be a holdable row stage");
            }
        }
    }

    public function test_scoring_weights_are_integer_points_keyed_by_slug(): void
    {
        foreach (config('leadgen.pipelines') as $key => $pipeline) {
            foreach ($pipeline['scoring'] ?? [] as $category => $factors) {
                $this->assertIsString($category, "{$key}: scoring categories must be slugs");
                $this->assertIsArray($factors, "{$key}.{$category}: factors must map slug => points");

                foreach ($factors as $slug => $points) {
                    $this->assertIsString($slug, "{$key}.{$category}: factor keys must be slugs");
                    $this->assertIsInt($points,
                        "{$key}.{$category}.{$slug}: points must be an integer (negative is allowed)");
                }
            }
        }
    }

    public function test_tier_thresholds_are_ordered_and_routing_keys_are_real_tiers(): void
    {
        $goldMin = config('leadgen.tiers.gold_min');
        $oakMin = config('leadgen.tiers.oak_min');

        $this->assertIsInt($goldMin);
        $this->assertIsInt($oakMin);
        $this->assertGreaterThan($oakMin, $goldMin, 'gold_min must sit above oak_min');

        foreach (config('leadgen.pipelines') as $key => $pipeline) {
            foreach ($pipeline['tier_routing'] ?? [] as $tier => $action) {
                $this->assertContains($tier, Lead::TIERS, "{$key}: [{$tier}] is not a tier");
                $this->assertIsString($action, "{$key}: tier [{$tier}] routing action must be a slug");
                $this->assertNotSame('', mb_trim($action), "{$key}: tier [{$tier}] routing action must not be blank");
            }
        }
    }

    public function test_won_stage_is_the_last_row_stage_of_every_pipeline(): void
    {
        foreach (array_keys(config('leadgen.pipelines')) as $key) {
            $rowStages = Lead::rowStages($key);

            $this->assertSame(end($rowStages), Lead::wonStage($key),
                "{$key}: the won stage is derived as the pipeline's last holdable stage");
        }
    }

    public function test_label_maps_only_reference_declared_slugs(): void
    {
        $this->assertIsArray(config('leadgen.source_labels', []), 'source_labels must be a slug => label map');

        foreach (config('leadgen.source_labels', []) as $slug => $label) {
            $this->assertContains($slug, config('leadgen.sources'), "source_labels: unknown source [{$slug}]");
            $this->assertIsString($label);
            $this->assertNotSame('', mb_trim($label), "source_labels: label for [{$slug}] must not be blank");
        }

        foreach (config('leadgen.pipelines') as $key => $pipeline) {
            $factorSlugs = [];
            foreach ($pipeline['scoring'] ?? [] as $factors) {
                $factorSlugs = [...$factorSlugs, ...array_keys($factors)];
            }

            $maps = [
                'stage_labels' => $pipeline['stages'] ?? [],
                'factor_labels' => $factorSlugs,
                'category_labels' => array_keys($pipeline['scoring'] ?? []),
                'routing_labels' => array_values($pipeline['tier_routing'] ?? []),
            ];

            foreach ($maps as $mapKey => $validSlugs) {
                $this->assertIsArray($pipeline[$mapKey] ?? [], "{$key}.{$mapKey} must be a slug => label map");

                foreach ($pipeline[$mapKey] ?? [] as $slug => $label) {
                    $this->assertContains($slug, $validSlugs, "{$key}.{$mapKey}: unknown slug [{$slug}]");
                    $this->assertIsString($label);
                    $this->assertNotSame('', mb_trim($label), "{$key}.{$mapKey}: label for [{$slug}] must not be blank");
                }
            }
        }
    }
}
