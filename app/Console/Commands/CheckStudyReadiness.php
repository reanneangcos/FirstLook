<?php

namespace App\Console\Commands;

use App\Services\Screening\StudyProtocol;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('study:check {--json : Print the readiness report as JSON} {--review : Print the handbook and patient-mapping review packet as JSON}')]
#[Description('Check final-study configuration without requests or database writes')]
class CheckStudyReadiness extends Command
{
    public function handle(StudyProtocol $protocol): int
    {
        $report = $protocol->readiness();
        if ($this->option('json') || $this->option('review')) {
            $this->line(json_encode($this->option('review') ? $protocol->reviewPacket() : $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->info($report['ready'] ? 'Configuration ready for freezing; clinical review validity remains the researcher’s responsibility.' : 'Final comparison is blocked before any API request.');
            foreach ($report['blockers'] as $blocker) {
                $this->line('- '.$blocker);
            }
            $this->line($report['limitation']);
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
