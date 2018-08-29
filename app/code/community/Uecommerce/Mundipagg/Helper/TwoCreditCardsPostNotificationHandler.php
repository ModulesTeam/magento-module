<?php

class Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler extends Mage_Core_Helper_Abstract
{
    private $log;
    private $notificationPostData;
    private $orderReference;
    private $transactionKey;
    private $creditCardTransactionStatus;
    private $capturedAmountInCents;
    private $mundipaggOrderStatus;

    const TRANSACTION_NOT_FOUND_ON_MAGENTO  = 'Transaction not found on Magento transactions: ';
    const TRANSACTION_NOT_FOUND_ON_ADDITIONAL_INFO  = 'Transaction not found on additional information: ';
    const TRANSACTION_ALREADY_UPDATED = 'OK - Transaction already updated with status: ';
    const TRANSACTION_UPDATED = 'MP - Two credit cards transaction update received: ';
    const CURRENT_ORDER_STATE = 'Current order state: ';
    const CURRENT_ORDER_STATUS = 'Current order status: ';
    const ORDER_HISTORY_ADD = 'Order history add: ';
    const CAPTURED_AMOUNT = 'Captured amount in cents: ';

    /**
     * @return mixed
     */
    public function getNotificationPostJson()
    {
        return $this->notificationPostData;
    }

    /**
     * @param mixed $notificationPostJson
     * @return Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler
     */
    public function setNotificationPostData($notificationPostData)
    {
        $this->notificationPostData = $notificationPostData;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getOrderReference()
    {
        return $this->orderReference;
    }

    /**
     * @param mixed $orderReference
     * @return Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler
     */
    public function setOrderReference($orderReference)
    {
        $this->orderReference = $orderReference;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getTransactionKey()
    {
        return $this->transactionKey;
    }

    /**
     * @param mixed $transactionKey
     * @return Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler
     */
    public function setTransactionKey($transactionKey)
    {
        $this->transactionKey = $transactionKey;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCreditCardTransactionStatus()
    {
        return $this->creditCardTransactionStatus;
    }

    /**
     * @param mixed $creditCardTransactionStatus
     * @return Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler
     */
    public function setCreditCardTransactionStatus($creditCardTransactionStatus)
    {
        $this->creditCardTransactionStatus = $creditCardTransactionStatus;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCapturedAmountInCents()
    {
        return $this->capturedAmountInCents;
    }

    /**
     * @param mixed $capturedAmountInCents
     * @return Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler
     */
    public function setCapturedAmountInCents($capturedAmountInCents)
    {
        $this->capturedAmountInCents = $capturedAmountInCents;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getMundipaggOrderStatus()
    {
        return $this->mundipaggOrderStatus;
    }

    /**
     * @param mixed $mundipaggOrderStatus
     * @return Uecommerce_Mundipagg_Helper_TwoCreditCardsPostNotificationHandler
     */
    public function setMundipaggOrderStatus($mundipaggOrderStatus)
    {
        $this->mundipaggOrderStatus = $mundipaggOrderStatus;
        return $this;
    }

    private function splitNotificationPostData($data)
    {
        $this->setNotificationPostData($data)
        ->setCapturedAmountInCents($data['CreditCardTransaction']['CapturedAmountInCents'])
        ->setCreditCardTransactionStatus($data['CreditCardTransaction']['CreditCardTransactionStatus'])
        ->setOrderReference($data['OrderReference'])
        ->setTransactionKey($data['CreditCardTransaction']['TransactionKey'])
        ->setMundipaggOrderStatus($data['OrderStatus']);
    }

    private function setLogHeader()
    {
        $this->log->setLogLabel("Order #{$this->getOrderReference()}");
        $this->log->info("Processing two credit cards order " );

        $info['Transaction key'] = $this->getTransactionKey();
        $info['CreditCardTransactionStatus: '] = $this->getCreditCardTransactionStatus();
        $info['Mundipagg OrderStatus'] = $this->getMundipaggOrderStatus();

        $this->log->info(json_encode($info, JSON_PRETTY_PRINT));
    }

    public function processTwoCreditCardsNotificationPost(
        Mage_Sales_Model_Order $order,
        $notificationPostData
    )
    {
        $this->log = new Uecommerce_Mundipagg_Helper_Log(__METHOD__);

        try {
            $this->splitNotificationPostData($notificationPostData);
            $this->setLogHeader();

            $comment =self::CURRENT_ORDER_STATE .
                $order->getState() .
                ' (' . $order->getStatus() . ')';

            $this->log->info($comment);

            $transaction = $this->getTransaction($order);

            $additionalInformation = $this->getAdditionalInformation($order);
            $cardPrefix = $this->discoverCardPrefix($additionalInformation);

            $this->addOrderHistoryStatusUpdate($order, $cardPrefix);
            $order->save();

            if (!$this->alreadyUpdated($additionalInformation, $cardPrefix)) {
                $this->capture($order, $transaction, $cardPrefix);
            }

            return 'OK';

        } catch (Exception $e) {
            $this->log->error($e->getMessage());

            return $e->getMessage();
        }
    }

    private function getTransaction($order)
    {
        $transactionHelper = Mage::helper('mundipagg/transaction');

        $transaction =
            $transactionHelper->getTransaction(
                $order->getEntityId(),
                $this->getTransactionKey()
            );

        if (empty($transaction->getTransactionId())) {
            $comment =
                SELF::TRANSACTION_NOT_FOUND_ON_MAGENTO .
                $this->getTransactionKey();

            Mage::throwException($comment);
            return;
        }

        return $transaction;
    }

    private function capture($order, $transaction, $cardPrefix)
    {
        $util= Mage::helper('mundipagg/util');

        $totalPaidInCents = $this->getTotalPaidInCents($order);
        $baseGrandTotalInCents = $util->floatToCents($order->getBaseGrandTotal());

        if ($this->getCreditCardTransactionStatus() == 'Captured') {
            $transaction->setOrderPaymentObject($order->getPayment());
            $transaction->setIsClosed(true)->save();
        }

        $this->log->info(SELF::CAPTURED_AMOUNT . $this->getCapturedAmountInCents());

        $this->updateCapturedAmount($order, $cardPrefix);

        if (
            $this->getMundipaggOrderStatus() == 'Paid' &&
            $totalPaidInCents == $baseGrandTotalInCents
        ) {
            $this->setOrderAsProcessing($order, $totalPaidInCents);
            $this->updateAdditionalInformation($order, $cardPrefix, $totalPaidInCents);
            $order->save();
            $this->addOrderHistoryStatusUpdate($order, $cardPrefix, true);
        }

        $order->save();

        return;
    }

    private function setOrderAsProcessing($order, $amountInCents)
    {
        try {
            $amount = $amountInCents * 0.01;
            $this->createInvoice($order, $amount);

            $order
                ->setState(Mage_Sales_Model_Order::STATE_PROCESSING)
                ->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING)
                ->setIsNotified(true);

            $order->save();

        } catch (Exception $e) {
            $this->log->error($e->getMessage());
            Mage::throwException($e->getMessage());
        }
    }

    private function createInvoice($order, $totalPaid)
    {
        $invoice = Mage::helper('mundipagg/invoice');

        return $invoice->create(
                $order,
                $totalPaid
            );
    }

    private function getAdditionalInformation($order)
    {
        $payment = $order->getPayment();
        return $payment->getAdditionalInformation();
    }

    private function setAdditionalInformation($order, $key, $value)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation($key, $value);
        $payment->save();
    }

    /**
     * Discover card sort order by TransactionKey in
     * additional information.
     * @param array $additionalInformation
     * @return string 1_ or 2_ for 2 credit cards
     * @throws Mage_Core_Exception
     */
    private function discoverCardPrefix($additionalInformation)
    {
        $transactionKey = $this->getTransactionKey();

        if (
            !empty($additionalInformation['1_TransactionKey']) &&
            $additionalInformation['1_TransactionKey'] == $transactionKey
        ) {
            return '1_';
        }

        if (
            !empty($additionalInformation['2_TransactionKey']) &&
            $additionalInformation['2_TransactionKey'] == $transactionKey
        ) {
            return '2_';
        }

        $util = Mage::helper('mundipagg/util');

        $comment =
            SELF::TRANSACTION_NOT_FOUND_ON_ADDITIONAL_INFO .
            $this->getTransactionKey() . "\n" .
            "Additional information: \n\n" .
            $util->arrayToString($additionalInformation);

        Mage::throwException($comment);
    }

    private function addOrderHistoryStatusUpdate($order, $cardPrefix, $notify = false)
    {
        $comment = $this->historyComment($order, $cardPrefix);

        $historyItem = $order->addStatusHistoryComment($comment);

        if ($notify) {
            $historyItem->setIsCustomerNotified(1)->save();
            $order->sendOrderUpdateEmail($notify = true, $comment);
        }

        $order->save();
        $this->log->info(self::ORDER_HISTORY_ADD . $comment);
    }

    private function historyComment($order, $cardPrefix)
    {
        return
            self::TRANSACTION_UPDATED .
            $this->getCreditCardTransactionStatus() . '<br>' .
            self::CURRENT_ORDER_STATE . $order->getState() .
            ' (' . $order->getStatus() . ')<br>' .
            'Transacion key: ' . $this->getTransactionKey() . '<br>' .
            'Card sort order: ' . str_replace('_', '', $cardPrefix)
        ;
    }

    private function alreadyUpdated($additionalInformation, $cardPrefix)
    {
        return
            $additionalInformation[$cardPrefix . 'CreditCardTransactionStatus'] ==
            $this->getCreditCardTransactionStatus();
    }

    private function updateCapturedAmount($order, $cardPrefix) {
        $totalPaidInCents = $this->getTotalPaidInCents($order);
        $order->setTotalPaid($totalPaidInCents * 0.01);

        $this->updateAdditionalInformation($order, $cardPrefix, $totalPaidInCents);

        $order->save();

        return $totalPaidInCents;
    }

    private function updateAdditionalInformation($order, $cardPrefix, $transactionPaidAmount)
    {
        $this->setAdditionalInformation(
            $order,
            $cardPrefix . 'CapturedAmountInCents',
            $transactionPaidAmount
        );
        $this->setAdditionalInformation(
            $order,
            $cardPrefix . 'CreditCardTransactionStatus',
            $this->getCreditCardTransactionStatus()
        );
    }

    private function getTotalPaidInCents($order)
    {
        $util= Mage::helper('mundipagg/util');

        $totalPaidInCents = 0;

        if ($order->getTotalPaid()) {
            $totalPaidInCents =  $util->floatToCents($order->getTotalPaid());
        }

        $totalPaidInCents += $this->getCapturedAmountInCents();

        return $totalPaidInCents;
    }
}