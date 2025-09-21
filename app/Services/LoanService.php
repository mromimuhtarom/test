<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanService
{
    /**
     * Create a Loan
     *
     * @param  User  $user
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  int  $terms
     * @param  string  $processedAt
     *
     * @return Loan
     */
    public function createLoan(User $user, int $amount, string $currencyCode, int $terms, string $processedAt): Loan
    {
        return DB::transaction(function () use ($user, $amount, $currencyCode, $terms, $processedAt) {
            // Buat loan
            $loan = Loan::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'terms' => $terms,
                'outstanding_amount' => $amount,
                'currency_code' => $currencyCode,
                'status' => Loan::STATUS_DUE,
                'processed_at' => $processedAt,
            ]);

            // Hitung cicilan per term: distribusi sisa agar total sama
            $quotient = intdiv($amount, $terms); // 5000 / 3 = 1666
            $remainder = $amount % $terms;       // 5000 % 3 = 2

            $repayments = array_fill(0, $terms, $quotient);
            for ($i = $terms - $remainder; $i < $terms; $i++) {
                $repayments[$i] += 1;
            }

            $dueDate = Carbon::parse($processedAt);

            for ($i = 0; $i < $terms; $i++) {
                ScheduledRepayment::create([
                    'loan_id' => $loan->id,
                    'amount' => $repayments[$i],
                    'outstanding_amount' => $repayments[$i],
                    'currency_code' => $currencyCode,
                    'due_date' => $dueDate->copy()->addMonths($i + 1),
                    'status' => ScheduledRepayment::STATUS_DUE,
                ]);
            }

            return $loan->load('scheduledRepayments');
        });
    }

    /**
     * Repay Scheduled Repayments for a Loan
     *
     * @param  Loan  $loan
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  string  $receivedAt
     *
     * @return ReceivedRepayment
     */
    public function repayLoan(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        return DB::transaction(function () use ($loan, $amount, $currencyCode, $receivedAt) {

            $remainingPayment = $amount;

            $scheduledRepayments = $loan->scheduledRepayments()
                ->where('outstanding_amount', '>', 0)
                ->orderBy('due_date')
                ->get();

            foreach ($scheduledRepayments as $repayment) {
                if ($remainingPayment <= 0) break;

                if ($remainingPayment >= $repayment->outstanding_amount) {
                    // Lunas
                    $remainingPayment -= $repayment->outstanding_amount;
                    $repayment->outstanding_amount = 0;
                    $repayment->status = ScheduledRepayment::STATUS_REPAID;
                } else {
                    // Partial
                    $repayment->outstanding_amount -= $remainingPayment;
                    $repayment->status = ScheduledRepayment::STATUS_PARTIAL;
                    $remainingPayment = 0;
                }

                $repayment->save();
            }

            // Simpan received repayment
            $receivedRepayment = ReceivedRepayment::create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'currency_code' => $currencyCode,
                'received_at' => $receivedAt,
            ]);

            // Update outstanding_amount loan (integer)
            $loan->outstanding_amount = (int) $loan->scheduledRepayments()->sum('outstanding_amount');

            // Update status loan
            $loan->status = $loan->outstanding_amount == 0 ? Loan::STATUS_REPAID : Loan::STATUS_DUE;
            $loan->save();

            return $receivedRepayment;
        });
    }
}
