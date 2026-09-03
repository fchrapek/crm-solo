<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Lead;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for lead capture and edit. Every vocabulary rule reads
 * config('leadgen.*') via the Lead model — no enums duplicated here.
 *
 * `source` and `pipeline` are only accepted on create. The model throws if
 * they are ever dirtied on update; this request keeps the user out of that
 * error by not offering them in the first place.
 */
final class LeadsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $pipeline = $isCreate
            ? (string) $this->input('pipeline')
            : (string) $this->route('lead')?->pipeline;

        $stages = in_array($pipeline, Lead::pipelines(), true)
            ? Lead::rowStages($pipeline)
            : [];

        return [
            'pipeline' => [
                $isCreate ? 'required' : 'prohibited',
                Rule::in(Lead::pipelines()),
            ],
            // "No source tag -> the channel doesn't exist." Required at
            // capture, prohibited afterwards — it is the attribution the
            // plan's measured rates are grouped by.
            'source' => [
                $isCreate ? 'required' : 'prohibited',
                Rule::in(Lead::sources()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            // Optional on create: omitted means the entry stage for the
            // source. 'visitor' is absent from rowStages() and so is rejected
            // here too — it is an analytics boundary, not a row state.
            'stage' => ['nullable', Rule::in($stages)],
            'notes' => ['nullable', 'string'],
            // Only slugs are accepted; points and tier are derived from config
            // on read and must never be posted by the client.
            'score_factors' => ['nullable', 'array', $this->rejectUnknownCategories($pipeline)],
            ...$this->scoreFactorRules($pipeline),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source.prohibited' => __('Lead source is set at capture and cannot be changed.'),
            'pipeline.prohibited' => __('Lead pipeline cannot be changed after capture.'),
        ];
    }

    /**
     * Per-category rules only cover categories that exist, so an unknown one
     * ('trigger' on kiwwwi, which scores no triggers) would slip through
     * validation and surface as the model's exception — a 500 where a 422
     * belongs. Close that here.
     */
    private function rejectUnknownCategories(string $pipeline): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pipeline): void {
            if (! is_array($value) || ! in_array($pipeline, Lead::pipelines(), true)) {
                return;
            }
            $known = array_keys(Lead::scoringMap($pipeline));
            foreach (array_keys($value) as $category) {
                if (! in_array((string) $category, $known, true)) {
                    $fail(__('Scoring category :category is not part of this pipeline.', ['category' => $category]));
                }
            }
        };
    }

    /**
     * One rule per scoring category the pipeline defines, each pinned to that
     * category's own factor slugs. Built from config rather than listed, so a
     * re-tuned plan needs no change here — and an outbound factor posted onto
     * an inbound lead fails validation instead of silently scoring 0.
     *
     * @return array<string, mixed>
     */
    private function scoreFactorRules(string $pipeline): array
    {
        if (! in_array($pipeline, Lead::pipelines(), true)) {
            return [];
        }

        $rules = [];
        foreach (Lead::scoringMap($pipeline) as $category => $factors) {
            $rules["score_factors.{$category}"] = ['sometimes', 'array'];
            $rules["score_factors.{$category}.*"] = ['string', Rule::in(array_keys($factors))];
        }

        return $rules;
    }
}
