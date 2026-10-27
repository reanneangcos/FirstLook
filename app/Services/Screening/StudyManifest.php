<?php

namespace App\Services\Screening;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

final class StudyManifest
{
    public const LANGUAGES = ['English', 'Tagalog', 'Bisaya', 'English–Tagalog', 'English–Bisaya', 'Tagalog–Bisaya'];

    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Input file is not readable.');
        }
        $manifest = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $this->validate($manifest);

        return $manifest;
    }

    public function validate(mixed $manifest): void
    {
        $rules = [
            'manifest' => ['required', 'array:split,source_sha256,cases'],
            'manifest.split' => ['required', Rule::in(['TEST'])],
            'manifest.source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'manifest.cases' => ['required', 'array', 'list', 'size:600'],
            'manifest.cases.*' => ['required', 'array:split,dataset_case_id,variant_id,language,patient'],
            'manifest.cases.*.split' => ['required', Rule::in(['TEST'])],
            'manifest.cases.*.dataset_case_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'manifest.cases.*.variant_id' => ['required', 'string', 'max:80', 'distinct', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'manifest.cases.*.language' => ['required', Rule::in(self::LANGUAGES)],
            'manifest.cases.*.patient' => ['required', 'array:'.implode(',', PatientFields::NAMES)],
        ];
        foreach (PatientFields::NAMES as $field) {
            $rules['manifest.cases.*.patient.'.$field] = $field === 'age'
                ? ['present', 'nullable', 'integer', 'min:18'] : ['present', 'nullable', 'string', 'max:3000'];
        }
        $validator = Validator::make(['manifest' => $manifest], $rules);
        if ($validator->fails()) {
            throw new RuntimeException('Invalid TEST manifest: '.implode(', ', $validator->errors()->keys()));
        }
        $groups = collect($manifest['cases'])->groupBy('dataset_case_id');
        if ($groups->count() !== 120 || $groups->contains(fn ($cases): bool => $cases->count() !== 5
            || $cases->pluck('language')->unique()->count() !== 5
            || array_diff(['English', 'Tagalog', 'Bisaya'], $cases->pluck('language')->all()) !== [])) {
            throw new RuntimeException('TEST must contain 120 canonical cases, each with English, Tagalog, Bisaya and two distinct code-switch variants.');
        }
    }

    /** Reference keys are separate from the provider inputs and must agree within each canonical case. */
    public function references(string $path, array $manifest): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Reference file is not readable.');
        }
        $rows = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $validator = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'list', 'size:600'],
            'rows.*' => ['required', 'array:variant_id,expert_priority'],
            'rows.*.variant_id' => ['required', 'string', 'distinct'],
            'rows.*.expert_priority' => ['required', 'integer', 'between:1,5'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Invalid reference key. Require variant_id and expert_priority for exactly 600 variants.');
        }
        $references = array_column($rows, 'expert_priority', 'variant_id');
        $ids = array_column($manifest['cases'], 'variant_id');
        if (array_diff($ids, array_keys($references)) !== [] || array_diff(array_keys($references), $ids) !== []) {
            throw new RuntimeException('Reference IDs do not exactly match the frozen input IDs.');
        }
        foreach (collect($manifest['cases'])->groupBy('dataset_case_id') as $cases) {
            if ($cases->map(fn (array $case): int => (int) $references[$case['variant_id']])->unique()->count() !== 1) {
                throw new RuntimeException('Reference priorities disagree within a canonical case. Resolve with the dataset custodian before freezing.');
            }
        }

        return array_map(intval(...), $references);
    }

    public static function validateId(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $id) || in_array($id, ['.', '..'], true)) {
            throw new RuntimeException('Use an identifier of 1–80 letters, numbers, dots, underscores or hyphens.');
        }
    }

    /** A local review register is frozen before predictions; it never changes the primary reference key. */
    public function disputedCases(string $path, array $manifest, array $references): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Disputed-case register is not readable.');
        }
        $records = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $validator = Validator::make(['records' => $records], [
            'records' => ['present', 'array', 'list', 'max:119'],
            'records.*' => ['required', 'array:dataset_case_id,reference_priority,reason,handbook_criterion,reviewer,reviewed_at,review_record'],
            'records.*.dataset_case_id' => ['required', 'string', 'distinct', Rule::in(array_column($manifest['cases'], 'dataset_case_id'))],
            'records.*.reference_priority' => ['required', 'integer', 'between:1,5'],
            'records.*.reason' => ['required', 'string', 'max:3000'],
            'records.*.handbook_criterion' => ['required', 'string', 'max:3000'],
            'records.*.reviewer' => ['required', 'string', 'max:300'],
            'records.*.reviewed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'records.*.review_record' => ['required', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Invalid disputed-case register: '.implode(', ', $validator->errors()->keys()));
        }
        $cases = collect($manifest['cases'])->groupBy('dataset_case_id');
        foreach ($records as $record) {
            if ((int) $record['reference_priority'] !== $references[$cases[$record['dataset_case_id']][0]['variant_id']]) {
                throw new RuntimeException('Disputed-case reference does not match the unchanged primary key.');
            }
        }

        return $records;
    }
}
