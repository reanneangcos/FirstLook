<?php

namespace App\Console\Commands;

use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\EsiV4RuleLayer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('esi:preview {--json : Print the catalog, fictional inputs and draft results as JSON}')]
#[Description('Preview handbook rules and provisional ESI 3 for nurse review; writes no study records')]
class PreviewEsiRules extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $catalog = new EsiV4RuleCatalog(config('esi.approvals', []), deferVitalSigns: true);
        $engine = new EsiV4RuleLayer($catalog);
        $facts = [['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34']];
        $cases = [];
        foreach ($this->scenarios() as $id => $values) {
            $observations = [];
            foreach ($values as $field => $value) {
                $observations[$field] = [
                    'state' => $value === null ? 'unknown' : 'reported', 'value' => $value,
                    'source' => 'review_vignette',
                    'evidence' => 'FICTIONAL rule demonstration: '.$field.' = '.json_encode($value, JSON_THROW_ON_ERROR),
                    'recorded_by' => 'FICTIONAL REVIEW DEMONSTRATION', 'record_id' => $id,
                    'recorded_at' => '2026-10-11T00:00:00+08:00',
                ];
            }
            $cases[] = ['id' => $id, 'facts' => $facts, 'observations' => $observations,
                'result' => $engine->preview($facts, $observations)];
        }
        $report = ['purpose' => 'Draft for nurse review. Fictional observations only; no clinical approval or study results.',
            'writes_database' => false, 'calls_llm' => false, 'study_eligible' => false,
            'source_document' => EsiV4RuleCatalog::SOURCE_DOCUMENT,
            'rule_version' => $catalog->version(), 'rules' => $catalog->rules(), 'examples' => $cases];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->warn($report['purpose']);
        $this->line('Source: '.EsiV4RuleCatalog::SOURCE_DOCUMENT['title'].', '
            .EsiV4RuleCatalog::SOURCE_DOCUMENT['edition'].', '.EsiV4RuleCatalog::SOURCE_DOCUMENT['publication_number'].'.');
        $this->table(['Fictional condition', 'Candidate', 'Stopped at'], array_map(fn (array $case): array => [
            $case['id'], $case['result']['candidate_label'], $case['result']['stopped_at'],
        ], $cases));
        $this->info('No OpenAI request, database write, approval change or final-study priority was produced.');
        $this->line('Use --json for the source-linked rule definitions, full input states and evidence traces.');

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> */
    private function scenarios(): array
    {
        $excluded = ['immediate_lifesaving_intervention_required' => false, 'high_risk_situation' => false,
            'acute_confusion_lethargy_disorientation' => false, 'severe_pain_or_distress' => false];

        return [
            'Immediate lifesaving intervention required' => ['immediate_lifesaving_intervention_required' => true],
            'A negative; established high risk' => ['immediate_lifesaving_intervention_required' => false, 'high_risk_situation' => true],
            'A negative; acute mental-status change' => ['immediate_lifesaving_intervention_required' => false, 'acute_confusion_lethargy_disorientation' => true],
            'A negative; qualifying severe distress' => ['immediate_lifesaving_intervention_required' => false, 'severe_pain_or_distress' => true],
            'A/B negative; zero expected resources' => $excluded + ['expected_esi_resources' => 0],
            'A/B negative; one expected resource' => $excluded + ['expected_esi_resources' => 1],
            'A/B negative; two expected resources; no vitals' => $excluded + ['expected_esi_resources' => 2],
            'A unknown despite two expected resources' => array_replace($excluded, ['immediate_lifesaving_intervention_required' => null, 'expected_esi_resources' => 2]),
            'B unknown despite two expected resources' => array_replace($excluded, ['high_risk_situation' => null, 'expected_esi_resources' => 2]),
            'A/B negative; resource estimate unknown' => $excluded + ['expected_esi_resources' => null],
            'Only age available from patient facts' => [],
            'Assessed immediate intubation requirement' => ['immediate_interventions_required' => ['intubation']],
            'Oxygen alone cannot establish or exclude A' => ['immediate_interventions_required' => ['oxygen_administration']],
            'A/B negative; expected blood and urine labs' => $excluded + [
                'expected_resource_plan' => ['complete' => true, 'items' => ['blood_laboratory_test', 'urine_laboratory_test']],
            ],
            'A/B negative; expected lab and X-ray; no vitals' => $excluded + [
                'expected_resource_plan' => ['complete' => true, 'items' => ['blood_laboratory_test', 'plain_xray']],
            ],
            'A/B negative; only exam and oral medication expected' => $excluded + [
                'expected_resource_plan' => ['complete' => true, 'items' => ['history_and_physical', 'oral_medication']],
            ],
            'A/B negative; incomplete list with only one resource' => $excluded + [
                'expected_resource_plan' => ['complete' => false, 'items' => ['plain_xray']],
            ],
            'Conflicting zero count and two-resource plan' => $excluded + [
                'expected_esi_resources' => 0,
                'expected_resource_plan' => ['complete' => true, 'items' => ['blood_laboratory_test', 'plain_xray']],
            ],
        ];
    }
}
