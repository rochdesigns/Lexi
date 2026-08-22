<?php

namespace App\Console\Commands;

use App\Models\EnvelopeAssignment;
use App\Models\User;
use App\Services\BudgetCycle;
use App\Services\EnvelopeService;
use Illuminate\Console\Command;

class SeedEnvelopeCutoverAssignments extends Command
{
    protected $signature = 'envelopes:seed-cutover';

    protected $description = 'Seed one envelope_assignments row per existing envelope for the current cycle, so rollover has a starting point.';

    public function handle(EnvelopeService $envelopes): int
    {
        User::query()->each(function (User $user) use ($envelopes) {
            $budgetCycle = new BudgetCycle($user->setting);
            $current = $budgetCycle->current();

            foreach ($envelopes->assignableEnvelopes($user) as $envelope) {
                $exists = EnvelopeAssignment::query()
                    ->whereMorphedTo('envelope', $envelope)
                    ->whereDate('cycle_start', $current->start->toDateString())
                    ->exists();

                if ($exists) {
                    continue;
                }

                EnvelopeAssignment::create([
                    'user_id' => $user->id,
                    'envelope_type' => $envelopes->envelopeType($envelope),
                    'envelope_id' => $envelope->id,
                    'cycle_start' => $current->start->toDateString(),
                    'assigned' => $envelope->defaultAssignedAmount(),
                ]);
            }
        });

        $this->info('Envelope cutover seeding complete.');

        return self::SUCCESS;
    }
}
