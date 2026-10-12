<?php

namespace Tests\Unit;

use App\Services\TriageRules\EsiV4ResourceCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EsiV4ResourceCounterTest extends TestCase
{
    public static function handbookExamples(): array
    {
        return [
            'CBC and electrolytes' => [['blood_laboratory_test', 'blood_laboratory_test'], 1],
            'CBC and urinalysis' => [['blood_laboratory_test', 'urine_laboratory_test'], 1],
            'CBC and chest film' => [['blood_laboratory_test', 'plain_xray'], 2],
            'chest and skull films' => [['plain_xray', 'plain_xray'], 1],
            'cervical films and head CT' => [['plain_xray', 'ct_scan'], 2],
            'exam refill and oral medicine' => [['history_and_physical', 'prescription_refill', 'oral_medication'], 0],
            'ankle film and splint' => [['plain_xray', 'crutches_splint_or_sling'], 1],
            'simple procedure' => [['simple_procedure'], 1],
            'complex procedure' => [['complex_procedure'], 2],
            'explicit complete zero' => [[], 0],
        ];
    }

    #[DataProvider('handbookExamples')]
    public function test_counts_expected_services_using_handbook_resource_groups(array $items, int $expected): void
    {
        $result = (new EsiV4ResourceCounter)->count(['complete' => true, 'items' => $items]);

        $this->assertSame($expected, $result['count']);
        $this->assertNull($result['issue']);
    }

    public static function incompletePlans(): array
    {
        return ['empty' => [[], null], 'one group' => [['blood_laboratory_test', 'urine_laboratory_test'], null],
            'two established groups' => [['blood_laboratory_test', 'plain_xray'], 2]];
    }

    #[DataProvider('incompletePlans')]
    public function test_an_incomplete_plan_only_supports_the_two_or_more_branch(array $items, ?int $count): void
    {
        $result = (new EsiV4ResourceCounter)->count(['complete' => false, 'items' => $items]);

        $this->assertSame($count, $result['count']);
        $this->assertSame($count === null ? 'incomplete_resource_estimate' : null, $result['issue']);
    }

    public static function invalidPlans(): array
    {
        return ['missing completeness' => [['items' => []]], 'string completeness' => [['complete' => 'true', 'items' => []]],
            'unknown service' => [['complete' => true, 'items' => ['unspecified_test']]],
            'prior tests are not a plan' => [['complete' => true, 'items' => ['CBC already completed']]],
            'wrong item type' => [['complete' => true, 'items' => [false]]],
            'associative items' => [['complete' => true, 'items' => ['test' => 'plain_xray']]],
            'extra fields' => [['complete' => true, 'items' => [], 'count' => 2]]];
    }

    #[DataProvider('invalidPlans')]
    public function test_invalid_or_unsupported_plans_never_become_zero_resources(mixed $plan): void
    {
        $result = (new EsiV4ResourceCounter)->count($plan);

        $this->assertNull($result['count']);
        $this->assertNotNull($result['issue']);
    }
}
