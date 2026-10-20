<?php

namespace App\Console\Commands;

use App\Services\Screening\NormalizedPatientFacts;
use App\Services\TriageRules\DraftPatientMappings;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleLayer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('esi:patient-preview {--json : Export source-linked proposals and evidence traces} {--markdown : Print a readable rule-review report} {--input= : Optional JSON object with extracted_facts and normalized_facts}')]
#[Description('Preview unvalidated patient-report mappings; never approve rules, call the LLM or save study results')]
class PreviewPatientMappings extends Command
{
    public function handle(): int
    {
        $catalog = new ProvisionalEsiRuleCatalog(config('esi.provisional_rules', []), config('esi.provisional_approvals', []));
        $engine = new ProvisionalEsiRuleLayer($catalog);
        $examples = [];
        if ($this->option('input') !== null) {
            $path = $this->option('input');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 100_000) {
                $this->error('Provide a readable fictional facts JSON file under 100 KB.');

                return self::FAILURE;
            }
            try {
                $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->error('The facts file is not valid JSON.');

                return self::FAILURE;
            }
            if (! is_array($input) || ! is_array($input['extracted_facts'] ?? null) || ! array_is_list($input['extracted_facts'])
                || count($input['extracted_facts']) > 15 || ! is_array($input['normalized_facts'] ?? null)) {
                $this->error('Expected extracted_facts (at most 15) and normalized_facts lists.');

                return self::FAILURE;
            }
            $examples[] = ['id' => 'user_supplied_fictional_preview', 'input' => $input,
                'result' => $engine->preview($input['extracted_facts'], $input['normalized_facts'])];
        } else {
            foreach ($this->fictionalInputs() as $id => $input) {
                $examples[] = ['id' => $id, 'input' => $input, 'result' => $engine->preview($input['extracted_facts'], $input['normalized_facts'])];
            }
        }
        $report = ['purpose' => 'UNVALIDATED drafts for nurse review. Constructed examples demonstrate execution, not clinical accuracy or dataset coverage.',
            'study_eligible' => false, 'writes_database' => false, 'calls_llm' => false,
            'draft_version' => DraftPatientMappings::VERSION, 'rule_version' => $catalog->version(),
            'concept_definitions' => NormalizedPatientFacts::definitions(), 'rules' => $catalog->rules(), 'proposals' => DraftPatientMappings::proposals(), 'examples' => $examples,
            'limits' => ['Does not cover all possible conditions.', 'Rules match normalized concepts. Language interpretation is performed by the LLM and requires separate evaluation.',
                'No normal vital signs, physical findings, diagnoses or patient resource counts are inferred.',
                'The negative-report bundles and resource profiles require clinical review before study use.',
                'No validation, nurse approval or final-study classification is created by a preview.']];
        if ($this->option('markdown')) {
            $this->line($this->markdownReport($report));
        } elseif ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->warn($report['purpose']);
            $this->table(['Fictional example', 'Draft candidate', 'Stopped at'], array_map(fn (array $example): array => [
                $example['id'], $example['result']['candidate_label'], $example['result']['stopped_at'],
            ], $examples));
            $this->line('All scored priorities remain null. Use --json to inspect conditions, sources, limitations and pending approvals.');
        }

        return self::SUCCESS;
    }

    private function markdownReport(array $report): string
    {
        $lines = ['# FirstLook: draft patient-response mappings', '',
            '**Status: unvalidated proposals. No clinical approvals have been recorded.**', '',
            'Draft version: `'.$report['draft_version'].'`. Rule version: `'.$report['rule_version'].'`.', '',
            'These are selected handbook examples adapted to patient reports. They do not cover every possible condition. '
                .'Luna normalizes language and clear typos into shared concepts. The concept meanings and proposed A/B exclusions require clinical review. '
                .'A nurse must assess the adaptation, not merely confirm the original ESI handbook.', '',
            'Source: [Gilboy et al., ESI v4 Implementation Handbook, 2012 Edition](https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf), AHRQ 12-0014, November 2011.',
            'Verified source SHA-256: `'.DraftPatientMappings::SOURCE_SHA256.'`.', '',
            'Rules require an explicit adult age. Conditions within a rule use AND. The first concept identifies the presentation; its absence from the record does not establish a negative decision. Later rules require explicit A/B exclusions. Original quotations remain available for audit. '
                .'Missing or unknown information does not mean no. Tests requested/completed and devices do not become resource counts.', '',
            'Every preview has `priority=null` and `study_eligible=false`. The separate candidate is for review only. '
                .'A candidate 3 always leaves Decision D unassessed and requires nurse evaluation and vital signs.', '',
            '## How to review', '',
            'For each rule, confirm or revise the positive conditions, earlier-decision exclusions and expected-resource rationale. '
                .'Record unsupported situations and ambiguous concept definitions. Test multilingual extraction separately. Mark approve/revise/reject and retain the signed review with reviewer name, date, record ID and exact definition hash. '
                .'A validated dataset label is not a substitute for this review.', ''];
        foreach ($report['rules'] as $id => $rule) {
            $proposal = $report['proposals'][$id] ?? [];
            $lines[] = '## '.$id.' — '.($proposal['title'] ?? 'Configured mapping');
            $lines[] = '';
            $lines[] = '- Decision: '.$rule['decision_point'].'; proposed candidate: '.($rule['priority'] === 3 ? 'provisional ESI 3' : 'ESI '.$rule['priority']).'.';
            $lines[] = '- Review status: '.$rule['reviewer_approval']['status'].'.';
            $lines[] = '- Source location: '.$rule['handbook_section'].'.';
            $lines[] = '- Definition hash: `'.ProvisionalEsiRuleCatalog::definitionHash(config('esi.provisional_rules')[$id]).'`.';
            $lines[] = '';
            $lines[] = '### Required patient evidence';
            $lines[] = '';
            foreach (['Supporting conditions' => $rule['conditions'], ...$rule['exclusions']] as $purpose => $conditions) {
                $lines[] = '**'.(in_array($purpose, ['A', 'B'], true) ? 'Proposed exclusion of '.$purpose : $purpose).'**';
                $lines[] = '';
                foreach ($conditions as $condition) {
                    $lines[] = isset($condition['concept'])
                        ? '- `'.$condition['concept'].'`: **'.$condition['state'].'** — '.NormalizedPatientFacts::definitions()[$condition['concept']]
                        : '- `'.$condition['field'].'`, state `'.$condition['state'].'`: '.implode(' OR ', $condition['values']);
                }
                $lines[] = '';
            }
            $lines[] = '**Adaptation and limits:** '.$rule['adaptation_rationale'];
            $lines[] = '';
            if ($rule['resource_rationale'] !== null) {
                $lines[] = '**Resource rationale to review:** '.$rule['resource_rationale'];
                $lines[] = '';
            }
            $lines[] = 'Review decision: __________  Reviewer: __________  Date: __________  Record ID: __________';
            $lines[] = '';
            $lines[] = 'Required revisions / concept meanings / exclusions: __________________________________';
            $lines[] = '';
        }
        $lines[] = '## Constructed execution examples';
        $lines[] = '';
        $lines[] = 'These examples were built to demonstrate the definitions. Their matches are not accuracy findings or validation results.';
        $lines[] = '';
        $lines[] = '| Example | Draft candidate | Decision reached |';
        $lines[] = '| --- | --- | --- |';
        foreach ($report['examples'] as $example) {
            $lines[] = '| '.$example['id'].' | '.$example['result']['candidate_label'].' | '.$example['result']['stopped_at'].' |';
        }

        return implode("\n", $lines);
    }

    /** Constructed concept fixtures exercise execution; they do not test LLM language understanding. */
    private function fictionalInputs(): array
    {
        $examples = [];
        foreach (DraftPatientMappings::definitions() as $id => $definition) {
            $concepts = [];
            foreach ([$definition['conditions'], ...array_values($definition['exclusions'])] as $conditions) {
                foreach ($conditions as $condition) {
                    $concepts[$condition['concept']] = $condition['state'];
                }
            }
            $examples[$id] = $this->fixture($concepts);
        }
        $abdominal = $examples['DRAFT-C-RIGHT-LOWER-ABDOMEN'];
        $abdominal['normalized_facts'] = array_values(array_filter($abdominal['normalized_facts'],
            fn (array $fact): bool => !in_array($fact['concept'], ['breathing_without_help', 'responds_to_voice'], true)));
        $examples['NO-A-B-EXCLUSION'] = $abdominal;
        $examples['PAIN-SCORE-ALONE'] = ['extracted_facts' => [$this->fact('age', '34'), $this->fact('reported_severity', '10/10')], 'normalized_facts' => []];
        $examples['TESTS-AND-DEVICE-ONLY'] = ['extracted_facts' => [$this->fact('age', '34'),
            $this->fact('tests_requested', 'CBC and X-ray'), $this->fact('medical_devices', 'Catheter')], 'normalized_facts' => []];
        $examples['UNLISTED-PRESENTATION'] = $this->fixture(['unmapped_symptom' => 'reported']);
        $examples['CONTRADICTORY-EXCLUSION'] = $this->fixture(['breathing' => 'contradictory']);
        $examples['NON-ADULT'] = $examples['DRAFT-C-ANKLE'];
        $examples['NON-ADULT']['extracted_facts'][0] = $this->fact('age', '12');
        return $examples;
    }

    private function fixture(array $concepts): array
    {
        $normalized = [];
        $quotes = [];
        foreach ($concepts as $concept => $state) {
            $quote = $concept.': '.$state.' (constructed software fixture).';
            $quotes[] = $quote;
            $normalized[] = ['concept' => $concept, 'state' => $state,
                'evidence' => [['field' => 'symptom_description', 'quote' => $quote]]];
        }
        return ['extracted_facts' => [$this->fact('age', '34'), $this->fact('symptom_description', implode(' ', $quotes))],
            'normalized_facts' => $normalized];
    }

    private function fact(string $field, string $value, string $state = 'reported'): array
    {
        return ['field' => $field, 'state' => $state, 'value' => $value, 'evidence' => $value];
    }
}
