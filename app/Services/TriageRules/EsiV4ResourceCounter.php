<?php

namespace App\Services\TriageRules;

final class EsiV4ResourceCounter
{
    /**
     * Explicit expected services only. These tokens are not patient symptoms,
     * orders, completed tests, or devices already in place. Table 5-1 and the
     * examples on printed p. 37 of the supplied May 2005 handbook define the
     * groups. Repeated tests within a group count once.
     *
     * @var array<string, array{group: string, weight: int}>
     */
    public const SERVICES = [
        'blood_laboratory_test' => ['group' => 'laboratory', 'weight' => 1],
        'urine_laboratory_test' => ['group' => 'laboratory', 'weight' => 1],
        'ecg' => ['group' => 'ecg', 'weight' => 1],
        'plain_xray' => ['group' => 'plain_xray', 'weight' => 1],
        'ct_scan' => ['group' => 'ct_scan', 'weight' => 1],
        'mri' => ['group' => 'mri', 'weight' => 1],
        'ultrasound' => ['group' => 'ultrasound', 'weight' => 1],
        'angiography' => ['group' => 'angiography', 'weight' => 1],
        'iv_hydration' => ['group' => 'iv_hydration', 'weight' => 1],
        'iv_medication' => ['group' => 'parenteral_or_nebulized_medication', 'weight' => 1],
        'im_medication' => ['group' => 'parenteral_or_nebulized_medication', 'weight' => 1],
        'nebulized_medication' => ['group' => 'parenteral_or_nebulized_medication', 'weight' => 1],
        'specialty_consultation' => ['group' => 'specialty_consultation', 'weight' => 1],
        'simple_procedure' => ['group' => 'simple_procedure', 'weight' => 1],
        'complex_procedure' => ['group' => 'complex_procedure', 'weight' => 2],
        'history_and_physical' => ['group' => 'history_and_physical', 'weight' => 0],
        'point_of_care_test' => ['group' => 'point_of_care_test', 'weight' => 0],
        'saline_lock' => ['group' => 'saline_lock', 'weight' => 0],
        'oral_medication' => ['group' => 'oral_medication', 'weight' => 0],
        'tetanus_immunization' => ['group' => 'tetanus_immunization', 'weight' => 0],
        'prescription_refill' => ['group' => 'prescription_refill', 'weight' => 0],
        'phone_call_to_primary_care' => ['group' => 'phone_call_to_primary_care', 'weight' => 0],
        'simple_wound_care' => ['group' => 'simple_wound_care', 'weight' => 0],
        'crutches_splint_or_sling' => ['group' => 'crutches_splint_or_sling', 'weight' => 0],
    ];

    /** @return array{count: ?int, complete: bool, groups: array<string, int>, issue: ?string} */
    public function count(mixed $plan): array
    {
        $result = ['count' => null, 'complete' => false, 'groups' => [], 'issue' => 'invalid_resource_plan'];
        if (! is_array($plan) || ! is_bool($plan['complete'] ?? null)
            || ! is_array($plan['items'] ?? null) || ! array_is_list($plan['items'])
            || array_diff(array_keys($plan), ['complete', 'items']) !== []) {
            return $result;
        }
        $result['complete'] = $plan['complete'];
        foreach ($plan['items'] as $service) {
            if (! is_string($service) || ! isset(self::SERVICES[$service])) {
                $result['issue'] = 'unsupported_resource_type';

                return $result;
            }
            $definition = self::SERVICES[$service];
            $result['groups'][$definition['group']] = $definition['weight'];
        }
        ksort($result['groups']);
        $count = array_sum($result['groups']);
        if (! $plan['complete'] && $count < 2) {
            $result['issue'] = 'incomplete_resource_estimate';

            return $result;
        }
        $result['count'] = $count;
        $result['issue'] = null;

        return $result;
    }
}
