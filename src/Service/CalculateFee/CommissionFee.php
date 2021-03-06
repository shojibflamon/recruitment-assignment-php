<?php

namespace ShojibFlamon\Service\CalculateFee;

use ShojibFlamon\Service\Currency\ConvertCurrency;
use ShojibFlamon\Service\Enums\ClientType;
use ShojibFlamon\Service\Enums\Currencies;
use ShojibFlamon\Service\Enums\OperationType;

class CommissionFee
{
    private $data = [];
    private $convertCurrency;

    private $freeWithdraw;
    private $freeWithdrawLimitList;

    private $freeCredit;
    private $clientCreditLimitList;

    private $clientTransactionDateList;

    private $result;

    private $transactionRow;

    private $allDepositCharge;
    private $businessWithdrawCharge;
    private $privateWithdrawCharge;

    private $decimalPointCalculation;
    private $baseCurrency;

    public function __construct(ConvertCurrency $convertCurrency, $freeCredit, $freeWithdraw)
    {
        $this->convertCurrency = $convertCurrency;

        $this->freeCredit = $freeCredit;
        $this->clientCreditLimitList = [];

        $this->freeWithdraw = $freeWithdraw;
        $this->freeWithdrawLimitList = [];

        $this->clientTransactionDateList = [];

        $this->result = [];

        $this->decimalPointCalculation = 2;

        $this->allDepositCharge = 0.03;
        $this->businessWithdrawCharge = 0.5;
        $this->privateWithdrawCharge = 0.3;

        $this->baseCurrency = Currencies::EUR;
    }

    /**
     * @return void
     */
    public function getCsvFromInput()
    {
        $this->data = file('input.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }

    /**
     * @return void
     */
    public function calculate()
    {
        $rows = array_map('str_getcsv', $this->data);
        foreach ($rows as $row) {
            $this->transactionRow = $row;
            $this->result[] = $this->calculateFeePerTransaction();
        }
    }

    /**
     * @return string|void
     */
    private function calculateFeePerTransaction()
    {
        $amount = $this->getAmount();

        if ($this->isOperationTypeDeposit()) {
            return $this->getCommissionFee($amount, $this->allDepositCharge);
        }

        if ($this->isOperationTypeWithdraw()) {
            if ($this->isClientTypeBusiness()) {
                return $this->getCommissionFee($amount, $this->businessWithdrawCharge);
            }

            if ($this->isClientTypePrivate()) {
                $clientId = $this->getClintId();
                $isConverted = false;

                if (!array_key_exists($clientId, $this->clientCreditLimitList)) {
                    $this->clientCreditLimitList[$clientId] = $this->freeCredit;
                    $this->freeWithdrawLimitList[$clientId] = 1;
                }

                $clientCreditLimit = $this->getClientCreditLimit($clientId);

                $currency = $this->getCurrency();

                if (!$this->isCurrencyEuro($currency)) {
                    $amount = $this->convertCurrency->setAmount($amount)
                        ->setFrom($currency)
                        ->setTo($this->baseCurrency)
                        ->setDecimalPoint($this->decimalPointCalculation)
                        ->conversion();
                    $isConverted = true;
                }

                if ($this->isFirstThreeTransaction($clientId)) {
                    if ($amount >= $clientCreditLimit) {

                        $chargeableAmount = $amount - $clientCreditLimit;

                        if ($isConverted) {
                            $chargeableAmount = $this->convertCurrency->setAmount($chargeableAmount)
                                ->setFrom($this->baseCurrency)
                                ->setTo($currency)
                                ->setDecimalPoint($this->decimalPointCalculation)
                                ->conversion();
                        }

                        $charge = $this->privateWithdrawCharge;

                        $this->clientCreditLimitList[$clientId] = 0;

                    } else {
                        $chargeableAmount = $amount;
                        $this->clientCreditLimitList[$clientId] -= $amount;
                        $charge = 0;
                    }
                } else {
                    $chargeableAmount = $amount;
                    $this->clientCreditLimitList[$clientId] -= $amount;
                    $charge = $this->privateWithdrawCharge;
                }

                $this->clientTransactionDateList[$clientId] = $this->getTransactionDate();

                return $this->getCommissionFee($chargeableAmount, $charge);
            }
        }
    }

    /**
     * @param $amount
     * @param $charge
     * @return string
     */
    private function getCommissionFee($amount, $charge): string
    {
//        return $this->roundUp($amount * $charge * 0.01, $this->decimalPointCalculation);
        return $this->ceiling($amount * $charge * 0.01, $this->decimalPointCalculation);
    }

    /**
     * @param $clientId
     * @return mixed
     * Get the client credit limit
     * If more than one transaction in a week (Monday to Sunday) then return rest of the credit
     * else full credit (1000.00 EUR)
     */
    private function getClientCreditLimit($clientId)
    {
        $clientTransactionDate = ($this->clientTransactionDateList[$clientId]) ?? '';

        if (!in_array($clientTransactionDate, $this->getPreviousDatesInCurrentWeek())) {
            $this->freeWithdrawLimitList[$clientId] = 1;
            return $this->clientCreditLimitList[$clientId] = $this->freeCredit;
        }

        $this->freeWithdrawLimitList[$clientId] += 1;
        return $this->clientCreditLimitList[$clientId];
    }

    /**
     * @return array
     * Get the date list of a week (Monday to Sunay)
     * Start Date = Monday
     * End Date = Transaction Day
     */
    private function getPreviousDatesInCurrentWeek(): array
    {
        $dates = [];
        $givenDate = strtotime($this->getTransactionDate());
        $dayOfWeek = date('N', $givenDate);
        $firstDayOfWeek = date('Y-m-d', strtotime("- {$dayOfWeek} day", $givenDate));

        for ($i = 1; $i <= $dayOfWeek; $i++) {
            $dates[] = date('Y-m-d', strtotime("+ {$i} day", strtotime($firstDayOfWeek)));
        }
        return $dates;
    }

    /**
     * @param $clientId
     * @return bool
     * Check the transaction is less than free withdraw limit or not
     */
    private function isFirstThreeTransaction($clientId): bool
    {
        return $this->freeWithdrawLimitList[$clientId] <= $this->freeWithdraw;
    }

    /**
     * @param $currency
     * @return bool
     */
    private function isCurrencyEuro($currency): bool
    {
        return $currency == Currencies::EUR;
    }

    /**
     * @return bool
     */
    private function isClientTypeBusiness(): bool
    {
        return $this->getClintType() == ClientType::BUSINESS;
    }

    /**
     * @return bool
     */
    private function isClientTypePrivate(): bool
    {
        return $this->getClintType() == ClientType::PRIVATE;
    }

    /**
     * @return bool
     */
    private function isOperationTypeDeposit(): bool
    {
        return $this->getOperationType() == OperationType::DEPOSIT;
    }

    /**
     * @return bool
     */
    private function isOperationTypeWithdraw(): bool
    {
        return $this->getOperationType() == OperationType::WITHDRAW;
    }

    /**
     * @return mixed
     */
    private function getTransactionDate()
    {
        return $this->transactionRow[0];
    }

    /**
     * @return mixed
     */
    private function getClintId()
    {
        return $this->transactionRow[1];
    }

    /**
     * @return mixed
     */
    private function getClintType()
    {
        return $this->transactionRow[2];
    }

    /**
     * @return mixed
     */
    private function getOperationType()
    {
        return $this->transactionRow[3];
    }

    /**
     * @return mixed
     */
    private function getAmount()
    {
        return $this->transactionRow[4];
    }

    /**
     * @return mixed
     */
    private function getCurrency()
    {
        return $this->transactionRow[5];
    }

    /**
     * @param $value
     * @return string
     */
    private function roundUp($value): string
    {
        $mult = pow(10, 0);
        $ceil = ceil($value * $mult) / $mult;
        return number_format($ceil, 0, '.', '');
    }

    /**
     * @param $value
     * @param int $decimal
     * @return string
     * Round up amount with 2 decimal places
     */
    private function ceiling($value, int $decimal = 0): string
    {
        $offset = 0.5;
        if ($decimal !== 0) {
            $offset /= pow(10, $decimal);
        }

        $final = round($value + $offset, $decimal, PHP_ROUND_HALF_DOWN);
        return number_format($final, '2', '.', '');
    }

    /**
     * @return array
     */
    public function getResult(): array
    {
        return $this->result;
    }

    /**
     * @return void
     */
    public function printItem()
    {
        foreach ($this->result as $item) {
            print $item . PHP_EOL;
        }
    }
}