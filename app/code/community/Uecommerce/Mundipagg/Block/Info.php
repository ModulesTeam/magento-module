<?php
/**
 * Uecommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Uecommerce EULA.
 * It is also available through the world-wide-web at this URL:
 * http://www.uecommerce.com.br/
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the extension
 * to newer versions in the future. If you wish to customize the extension
 * for your needs please refer to http://www.uecommerce.com.br/ for more information
 *
 * @category   Uecommerce
 * @package    Uecommerce_Mundipagg
 * @copyright  Copyright (c) 2012 Uecommerce (http://www.uecommerce.com.br/)
 * @license    http://www.uecommerce.com.br/
 */

/**
 * Mundipagg Payment module
 *
 * @category   Uecommerce
 * @package    Uecommerce_Mundipagg
 * @author     Uecommerce Dev Team
 */
class Uecommerce_Mundipagg_Block_Info extends Mage_Payment_Block_Info
{

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('mundipagg/payment/info/mundipagg.phtml');
    }

    /**
     * Retrieve order model instance
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        return Mage::registry('current_order');
    }

    /**
     * Retrieve invoice model instance
     *
     * @return Mage_Sales_Model_Order_Invoice
     */
    public function getInvoice()
    {
        return Mage::registry('current_invoice');
    }

    /**
     * Retrieve shipment model instance
     *
     * @return Mage_Sales_Model_Order_Shipment
     */
    public function getShipment()
    {
        return Mage::registry('current_shipment');
    }

    /**
     * Retrieve payment method
     */
    public function getFormaPagamento()
    {
        return $this->getInfo()->getAdditionalInformation('PaymentMethod');
    }

    /**
     * @param $ccQty credit card quantity
     * @param $ccPos credit card position
     * @return array|mixed|null
     */
    public function getCcBrand($ccPos)
    {
        return $this->getInfo()->getAdditionalInformation("{$ccPos}_CreditCardBrand");
    }

    public function getCcValue($ccPos)
    {
        $value = $this->getInfo()->getAdditionalInformation("{$ccPos}_AmountInCents") * 0.01;

        return Mage::helper('core')->currency($value, true, false);
    }

    public function getInstallmentsNumber($ccQty, $ccPos)
    {
        $methodCode = $this->getInfo()->getAdditionalInformation('method');

        $new = $this->getInfo()
            ->getAdditionalInformation(
                "{$methodCode}_token_{$ccQty}_{$ccPos}"
            );
        $new = $new === 'new' ? 'new_' : '';

        $installments = $this->getInfo()->getAdditionalInformation(
            "{$methodCode}_" . $new . "credito_parcelamento_{$ccQty}_{$ccPos}"
        );

        $installments .= "x";

        return $installments;
    }

    public function getAuthorizationCode($ccPos)
    {
        $authCode = $this->getInfo()->getAdditionalInformation("{$ccPos}_AuthorizationCode");

        if (empty($authCode)) {
            $authCode = "N/A";
        }

        return $authCode;
    }

    public function getTransactionId($ccPos)
    {
        $txnId = $this->getInfo()->getAdditionalInformation("{$ccPos}_TransactionIdentifier");

        if (empty($txnId)) {
            $txnId = "N/A";
        }

        return $txnId;
    }
}
