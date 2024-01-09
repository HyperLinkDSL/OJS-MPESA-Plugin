<?php

/**
 * @file plugins/paymethod/mpesa/templates/MpesaPaymentForm.php
 *
 * Copyright (c) 2024 HyperLink DSL
 * Copyright (c) 2024 Otuoma Sanya
 * Distributed under the GNU GPL v3.
 * @class MpesaPaymentForm
 * @brief Mpesa payment plugin
 */

namespace APP\plugins\paymethod\mpesa;

use APP\core\Application;
use PKP\form\Form;
use PKP\payment\QueuedPayment;
use APP\template\TemplateManager;

class MpesaPaymentForm extends Form {
    public $_mpesaPaymentPlugin;

    /** @var QueuedPayment */
    public QueuedPayment $_queuedPayment;

    public function __construct($mpesaPaymentPlugin, $queuedPayment) {
        $this->_mpesaPaymentPlugin = $mpesaPaymentPlugin;
        $this->_queuedPayment = $queuedPayment;
        parent::__construct(null);
    }

    public function display($request = null, $template = null){

        $journal = $request->getJournal();
        $paymentManager = Application::getPaymentManager($journal);
        $queuedPaymentId = $this->_queuedPayment->getId();
        $pluginName = $this->_mpesaPaymentPlugin->getName();

        $templateMgr = TemplateManager::getManager($request);

        $templateMgr->display($this->getTemplateResource('example.tpl'));

    }
}