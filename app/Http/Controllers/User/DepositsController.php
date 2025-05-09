<?php

namespace App\Http\Controllers\User;

use App\Events\DepositRequestEvent;
use App\Handlers\PaymentHandler;
use App\Http\Controllers\Controller;
use App\Interfaces\CreateChargeInput;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Traits\DepositsTrait;
use App\Traits\EmailNotificationTrait;
use Illuminate\Http\Request;
use Webpatser\Uuid\Uuid;

class DepositsController extends Controller
{
    use DepositsTrait;
    use EmailNotificationTrait;

    public function index(Request $request)
    {
        $deposits = Deposit::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($handler) {

                if (request('currency', ''))
                    $handler->where('crypto_currency', request('currency'));

                if (request('status', ''))
                    $handler->where('status', request('status'));

                if (request()->get('to', '') || request('from', '')) {
                    $handler->whereBetween(
                        'created_at',
                        [
                            request('from', now("-7days")),
                            request('to', now())
                        ]
                    );
                }
            })
            ->cursorPaginate(request('rpp', 20))
            ->appends('rpp', request('rpp', ''))
            ->appends('from', request('from', ''))
            ->appends('to', request('to', ''));

        $currencies = Currency::where('status', '1')->get()->all();

        foreach ($currencies as $key => $currency) {

            $wallet = Wallet::whereBelongsTo($request->user())
                ->where('currency_id', $currency->id)->get()->first();

            if (!$wallet) {
                $wallet = Wallet::create([
                    'user_id' => $request->user()->id,
                    'username' => $request->user()->username,
                    'currency_id' => $currency->id,
                    'currency_code' => $currency->code,
                ]);
            }

            $currencies[$key]->balance = $wallet->balance;
        }

        // Get all plans
        $plans = Plan::where('status', "1")->get()->all();
        
        // If a plan is selected from URL, find it in the plans array
        $selectedPlan = null;
        if ($request->has('plan')) {
            $planName = strtolower($request->get('plan'));
            foreach ($plans as $plan) {
                if (strtolower($plan->title) === $planName) {
                    $selectedPlan = $plan;
                    break;
                }
            }
        }

        return view('pages.user.deposits', [
            'deposits' => $deposits,
            'plans' => $plans,
            'currencies' => $currencies,
            'selectedPlan' => $selectedPlan
        ]);
    }

    public function view(Request $request, $id)
    {
        $deposit = Deposit::whereBelongsTo($request->user())
            ->findOrFail($id);

        $deposit->status_link = "";
        $deposit->payment_link = "";

        if (empty($deposit->details)) {
            $deposit->update([
                'details' => json_encode([
                    'cryptoAmount' => $deposit->amount,
                    'cryptoAddress' => $deposit->address,
                    'paymentLink' => route('user.deposits.view', ['id' => $deposit->id]),
                    'statusLink' => route('user.deposits.view', ['id' => $deposit->id])
                ])
            ]);
        }

        $details = json_decode($deposit->details, true);
        $deposit->payment_link = $details['paymentLink'] ?? "";
        $deposit->status_link = $details['statusLink'] ?? "";

        $transactions = Transaction::whereBelongsTo($request->user())
            ->where('log_type', 'deposit')
            ->where('transaction_id', $id)->get()->all();

        return view('pages.user.view-deposit', [
            'deposit' => $deposit,
            'transactions' => $transactions
        ]);
    }

    public function create(Request $request, PaymentHandler $paymentHandler)
    {
        $request->validate([
            'plan_id' => 'required|numeric',
            'amount' => 'required|numeric',
            'currency_id' => 'required|numeric',
            'deposit_from' => 'required|string'
        ]);

        $user = $request->user();
        $plan = Plan::findOrFail($request->plan_id);
        $currency = Currency::find($request->currency_id);
        $wallet = Wallet::whereBelongsTo($user)->where('currency_id', $currency->id)->get()->first();
        $amount = $request->amount;
        $charges = $this->getDepositCharges($currency, $amount);

        if (!$currency->status) {
            return back()->with("error", "This currency is currently uavailable.");
        }

        // verify the amount is within range for selected plan
        if ($plan->minimum > $amount) {
            return back()->with("error", sprintf("Amount ($%d) is lower than minimum deposit ($%d) for this plan", $request->amount, $plan->minimum));
        }

        if (!empty($plan->maximum) && $plan->maximum < $amount) {
            $message = sprintf("Amount ($%d) is higher than maximum deposit ($%d) for this plan", $request->amount, $plan->maximum);
            return back()->with("error", $message);
        }

        // check if payment is via balance
        if ($request->deposit_from == "balance") {
            if ($wallet->balance < ($charges + $amount)) {
                return back()->with(
                    "error",
                    sprintf(
                        "Insufficent wallet balance. Balance: %f; Charges + Deposit: %f",
                        $wallet->balance,
                        $amount + $charges
                    )
                );
            }
        }

        $transactionID = Uuid::generate()->string;

        $deposit = Deposit::create([
            'user_id' => $user->id,
            'deposit_from' => $request->deposit_from,
            'username' => $user->username,
            'plan_id' => $plan->id,
            'plan_title' => $plan->title,
            'transaction_id' => $transactionID,
            'percentage' => $plan->percentage,
            'profit_frequency' => $plan->profit_frequency,
            'address' => $currency->deposit_address,
            'amount' => $request->amount,
            'charges' => $this->getDepositCharges($currency, $request->amount),
            'crypto_currency' => $currency->code,
        ]);

        // Send email notifications
        $this->sendDepositNotification($deposit, $user);

        if ($request->deposit_from == "balance" && $wallet->decrement('balance', $amount)) {
            $approve = $this->approveDeposit($deposit->id);
            return back()->with($approve['success'] ? "success" : "error", $approve['message']);
        }

        // process immediately if autodeposit is available.
        if ($currency->deposit_processor) {
            // Set default deposit details
            $deposit->update([
                'crypto_amount' => $amount,
                'address' => $currency->deposit_address,
                'details' => json_encode([
                    'cryptoAmount' => $amount,
                    'cryptoAddress' => $currency->deposit_address,
                    'paymentLink' => route('user.deposits.view', ['id' => $deposit->id]),
                    'statusLink' => route('user.deposits.view', ['id' => $deposit->id])
                ])
            ]);

            return redirect()
                ->route('user.deposits.view', ['id' => $deposit->id])
                ->with("success", "Deposit request received. Please proceed to complete transaction.");
        }

        // dispatch this event if the deposit is not to be processed automatically.
        if ($deposit) {
            // Set default deposit details for manual deposits
            $deposit->update([
                'crypto_amount' => $amount,
                'address' => $currency->deposit_address,
                'details' => json_encode([
                    'cryptoAmount' => $amount,
                    'cryptoAddress' => $currency->deposit_address,
                    'paymentLink' => route('user.deposits.view', ['id' => $deposit->id]),
                    'statusLink' => route('user.deposits.view', ['id' => $deposit->id])
                ])
            ]);

            DepositRequestEvent::dispatch($deposit);
            return redirect()
                ->route('user.deposits.view', ['id' => $deposit->id])
                ->with("success", "Deposit request received. Please proceed to complete transaction.");
        }

        return back()->with("error", 'Unable to process request at the moment. Please try again later');
    }
}
