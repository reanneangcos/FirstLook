<?php

namespace App\Console\Commands;

use App\Services\Screening\StudyManifest;
use App\Services\Screening\StudyMetrics;
use App\Services\Screening\StudyProtocol;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('study:freeze {input : Label-free TEST JSON} {--study-id= : New frozen experiment identifier} {--reference= : Separate reference-key JSON} {--disputed-cases= : Optional local JSON review register for prespecified sensitivity exclusions} {--seed=42 : First of three order seeds} {--secondary-run= : Prespecified run 1, 2 or 3 for the English-only McNemar test} {--dataset-review-record= : Existing dataset release/review record ID} {--pilot-record= : Existing approved development pilot record ID} {--dry-run : Validate without writing the freeze}')]
#[Description('Freeze inputs, reference hash, reviewed criteria, code and settings; no LLM requests')]
class FreezeStudy extends Command
{
    public function handle(StudyManifest $loader, StudyProtocol $protocol): int
    {
        try {
            $id = (string) $this->option('study-id');
            StudyManifest::validateId($id);
            $protocol->assertReady();
            $manifest = $loader->read($this->argument('input'));
            $reference = (string) $this->option('reference');
            $references = $loader->references($reference, $manifest);
            $disputedPath = (string) $this->option('disputed-cases');
            $disputed = $disputedPath === '' ? [] : $loader->disputedCases($disputedPath, $manifest, $references);
            $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483645]]);
            $secondaryRun = filter_var($this->option('secondary-run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
            if ($seed === false || $secondaryRun === false || trim((string) $this->option('dataset-review-record')) === '' || trim((string) $this->option('pilot-record')) === '') {
                throw new RuntimeException('Supply a seed, a prespecified --secondary-run (1–3), and the real dataset-review and development-pilot record IDs.');
            }
            $payload = ['study_id' => $id, 'manifest' => $manifest, 'manifest_sha256' => StudyProtocol::hash($manifest),
                'reference_sha256' => hash_file('sha256', $reference), 'snapshot' => $protocol->snapshot(),
                'seeds' => [$seed, $seed + 1, $seed + 2], 'order_algorithm' => 'sha256(seed:variant_id)',
                'statistics' => ['bootstrap_resamples' => 2000, 'bootstrap_seed' => 42,
                    'metrics_version' => StudyMetrics::VERSION,
                    'secondary_run' => $secondaryRun, 'secondary_language' => 'English',
                    'mcnemar_exact_below_discordant' => 25, 'secondary_population' => 'common_valid_pairs'],
                'sensitivity' => ['disputed_cases' => $disputed,
                    'register_sha256' => $disputedPath === '' ? null : hash_file('sha256', $disputedPath),
                    'procedure' => 'Exclude all five versions and all three runs of each registered canonical case from a supplementary analysis only. Keep the primary reference key unchanged.'],
                'dataset_review_record' => $this->option('dataset-review-record'), 'pilot_record' => $this->option('pilot-record'),
                'frozen_at' => now()->toIso8601String()];
            if ($this->option('dry-run')) {
                $this->info('Valid freeze proposal: 120 cases × 5 versions × 3 runs. No writes or requests.');

                return self::SUCCESS;
            }
            $lock = Cache::lock('study:'.$id, 86400);
            if (! $lock->get()) {
                throw new RuntimeException('Study is locked by another process.');
            }
            try {
                $path = 'studies/'.$id.'/freeze.json';
                if (Storage::disk('local')->exists($path)) {
                    throw new RuntimeException('This study ID is already frozen. Existing freezes cannot be overwritten.');
                }
                if (! Storage::disk('local')->put($path, json_encode(['sha256' => StudyProtocol::hash($payload), 'payload' => $payload],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Could not save the freeze.');
                }
                $this->info('Frozen: '.$path.'. No API requests made. Reference labels were not included in the frozen patient inputs.');
            } finally {
                $lock->release();
            }
        } catch (RuntimeException|\JsonException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
