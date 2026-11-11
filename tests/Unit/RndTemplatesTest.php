<?php

namespace Tests\Unit;

use App\Services\Rnd\FindingTemplates;
use PHPUnit\Framework\TestCase;

class RndTemplatesTest extends TestCase
{
    public function test_all_templates_render_with_zero_unfilled_placeholders_in_en_and_bn(): void
    {
        $sampleParams = [
            'website' => 'Dhaka Fashion',
            'currency' => 'BDT',
            'drop_pct' => '28.50',
            'prev_gross' => '100,000.00',
            'cur_gross' => '71,500.00',
            'diff_amount' => '28,500.00',
            'target_gross' => '95,000.00',
            'prev_orders' => 80,
            'cur_orders' => 55,
            'days' => 7,
            'n' => 100,
            'base_n' => 120,
            'n_c' => 25,
            'n_r' => 18,
            'base' => '5.00',
            'now' => '22.50',
            'rise_pp' => '17.50',
            'target' => '12.00',
            'missing_reason_pct' => '40.0',
            'courier' => 'Pathao',
            'rate' => '28.00',
            'other_rate' => '6.00',
            'returned' => 14,
            'z_score' => '3.45',
            'eligible' => 45,
            'employee' => 'Rafi',
            'cancelled' => 16,
            'emp_count' => 3,
            'product' => 'Cotton Panjabi',
            'stock' => 4,
            'cover_days' => '2.5',
            'units_30d' => 48,
            'rev_30d' => '48,000.00',
            'sku_count' => 3,
            'dead_days' => 60,
            'dead_value' => '18,000.00',
            'top_product' => 'Linen Shirt',
            'top_stock' => 25,
            'top_value' => '12,500.00',
            'missing_count' => 4,
            'top1_name' => 'Silk Panjabi',
            'top1_pct' => '52.00',
            'top1_rev' => '52,000.00',
            'top3_pct' => '81.00',
            'top3_rev' => '81,000.00',
            'rest_pct' => '48.00',
            'target_rest_pct' => '58.00',
            'prev_margin' => '38.00',
            'cur_margin' => '31.50',
            'drop_pp' => '6.50',
            'cur_cov' => '80.0',
            'prev_cov' => '52.0',
            'uncovered_pct' => '20.0',
            'cur_delivered' => 15,
            'prev_delivered' => 12,
            'disc_ret' => '24.00',
            'undisc_ret' => '6.00',
            'disc_aov' => '950.00',
            'undisc_aov' => '1,250.00',
            'disc_n' => 45,
            'undisc_n' => 80,
            'prev_rate' => '32.00',
            'cur_rate' => '18.00',
            'prev_buyers' => 60,
            'cur_buyers' => 75,
            'missing_cust_pct' => '5.0',
            'dq_score' => 54,
            'p_value' => '0.0012',
            'bad_summary' => 'Missing courier (45%)',
            'disabled_rules' => 'R04',
            'bad_indicators_list' => 'Missing courier: 45%',
            'confidence_reason_key' => 'high_stat',
        ];

        foreach (FindingTemplates::allTemplateKeys() as $key) {
            foreach (['en', 'bn'] as $locale) {
                $rendered = FindingTemplates::render($key, $sampleParams, $locale, $locale === 'bn');

                $allStrings = array_merge(
                    [$rendered['title'], $rendered['summary'], $rendered['confidence_reason'], $rendered['action'], $rendered['experiment']],
                    $rendered['hypotheses'],
                    $rendered['missing_data'],
                    array_values($rendered['success_metric'])
                );

                foreach ($allStrings as $str) {
                    $this->assertNotSame('', trim($str), "Empty string in {$key} ({$locale})");
                    $this->assertDoesNotMatchRegularExpression(
                        '/\{[a-zA-Z0-9_]+\}/',
                        $str,
                        "Unfilled placeholder found in {$key} ({$locale}): {$str}"
                    );
                }

                $expectedPrefix = $locale === 'bn' ? 'যাচাইহীন অনুমান:' : 'Unverified hypothesis:';
                foreach ($rendered['hypotheses'] as $h) {
                    $this->assertStringStartsWith($expectedPrefix, $h, "Hypothesis missing unverified marker in {$key} ({$locale})");
                }
            }
        }

        // R05 explicit non-blaming caveat verification in both EN and BN
        $r05En = FindingTemplates::render('R05.trigger', $sampleParams, 'en', false);
        $this->assertStringContainsString('workload and customer mix may differ', strtolower($r05En['summary']));

        $r05Bn = FindingTemplates::render('R05.trigger', $sampleParams, 'bn', true);
        $this->assertStringContainsString('কাজের চাপ ও ক্রেতার ধরন ভিন্ন হতে পারে', $r05Bn['summary']);
        $this->assertStringContainsString('Pathao', FindingTemplates::render('R04.trigger', $sampleParams, 'bn', true)['title']);
    }
}
