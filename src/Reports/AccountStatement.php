<?php

/**
 * Eloquent IFRS Accounting
 *
 * @author    Edward Mungai
 * @copyright Edward Mungai, 2020, Germany
 * @license   MIT
 */

namespace IFRS\Reports;

use Carbon\Carbon;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Ledger;
use IFRS\Models\Transaction;

use IFRS\Exceptions\MissingAccount;
use Illuminate\Database\Query\Builder;

class AccountStatement
{
    /**
     * Account Statement Currency.
     *
     * @var Currency
     */
    protected $currency;

    /**
     * Account Statement Account.
     *
     * @var Account
     */
    protected $account;

    /**
     * Account Statement Entity.
     *
     * @var Entity
     */
    protected $entity;

    /**
     * Account Statement balances.
     *
     * @var array
     */
    public $balances = [
        "opening" => 0,
        "closing" => 0
    ];

    /**
     * Account Statement period.
     *
     * @var array
     */
    public $period = [
        "startDate" => null,
        "endDate" => null
    ];

    /**
     * Account Statement transactions.
     *
     * @var array
     */
    public $transactions = [];

    /**
     * Build Statement Query
     *
     * @return Builder
     */
    protected function buildQuery()
    {
        $transactionModel = new Transaction;
        $ledgerModel = new Ledger();
        $query = DB::table(
            $transactionModel->getTable()
        )
            ->leftJoin($ledgerModel->getTable(), $transactionModel->getTable() . '.id', '=', $ledgerModel->getTable() . '.transaction_id')
            ->where($transactionModel->getTable() . '.deleted_at', null)
            ->where($transactionModel->getTable() . '.entity_id', $this->entity->id)
            ->where($transactionModel->getTable() . '.transaction_date', ">=", $this->period['startDate'])
            ->where($transactionModel->getTable() . '.transaction_date', "<=", $this->period['endDate'])
            ->where($transactionModel->getTable() . '.currency_id', $this->currency->id)
            ->select(
                $transactionModel->getTable() . '.id',
                $transactionModel->getTable() . '.transaction_date',
                $transactionModel->getTable() . '.transaction_no',
                $transactionModel->getTable() . '.reference',
                $transactionModel->getTable() . '.transaction_type',
                $transactionModel->getTable() . '.narration'
            )->distinct();

        $query->where(
            function ($query) use ($ledgerModel) {
                $query->where($ledgerModel->getTable() . '.post_account', $this->account->id)
                    ->orwhere($ledgerModel->getTable() . '.folio_account', $this->account->id);
            }
        );

        return $query;
    }

    /**
     * Print Account Statement attributes.
     *
     * @return object
     */
    public function attributes()
    {
        return (object) [
            "Account" => $this->account->name,
            "Currency" => $this->currency->name,
            "Entity" => $this->entity->name,
            "Period" => $this->period,
            "Balances" => $this->balances,
            "Transactions" => $this->transactions
        ];
    }

    /**
     * Construct Account Statement for the account for the period.
     *
     * @param int    $accountId
     * @param int    $currencyId
     * @param string $startDate
     * @param string $endDate
     */
    public function __construct(
        int $accountId = null,
        int $currencyId = null,
        string $startDate = null,
        string $endDate = null
    ) {
        if (is_null($accountId)) {
            throw new MissingAccount("Account Statement");
        } else {
            $this->account = Account::find($accountId);
        }

        $this->entity = Auth::user()->entity;

        $this->period['startDate'] = is_null($startDate) ? ReportingPeriod::periodStart() : Carbon::parse($startDate);
        $this->period['endDate'] = is_null($endDate) ? Carbon::now() : Carbon::parse($endDate);
        $this->currency = is_null($currencyId) ? $this->entity->currency : Currency::find($currencyId);
    }

    /**
     * Get Account Statement Transactions.
     */
    public function getTransactions(): void
    {
        $query = $this->buildQuery();
        $this->balances['opening'] = $this->account->openingBalance(ReportingPeriod::year($this->period['startDate']));
        $this->balances['closing'] += $this->balances['opening'];

        $balance = $this->balances['opening'];
        foreach ($query->get() as $transaction) {
            $transaction->debit = $transaction->credit = 0;

            $contribution = Ledger::contribution($this->account, $transaction->id);
            $this->balances['closing'] += $contribution;
            $balance += $contribution;
            $transaction->balance = $balance;

            $contribution > 0 ? $transaction->debit = abs($contribution) : $transaction->credit = abs($contribution);

            $transaction->transaction_type = config('ifrs')['transactions'][$transaction->transaction_type];

            array_push($this->transactions, $transaction);
        }
    }
}
