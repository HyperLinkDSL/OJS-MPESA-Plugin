<?php
namespace APP\plugins\paymethod\mpesa;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\form\Form;
use PKP\plugins\Hook;
use PKP\plugins\PaymethodPlugin;

class MpesaPlugin extends PaymethodPlugin {

    public function getPaymentForm($context, $queuedPayment): Form {

        $paymentForm = new Form($this->getTemplateResource('mpesa_request_payment.tpl'));

        $paymentManager = Application::getPaymentManager($context);
        $submitUrl = $this->getRequest()->url(null, 'payment', 'plugin', [$this->getName(), 'daraja-callback'], ['queuedPaymentId' => $queuedPayment->getId()]);

        $paymentForm->setData([
            'itemName' => $paymentManager->getPaymentName($queuedPayment),
            'itemAmount' => $queuedPayment->getAmount() > 0 ? $queuedPayment->getAmount() : null,
            'itemCurrencyCode' => $queuedPayment->getAmount() > 0 ? $queuedPayment->getCurrencyCode() : null,
            'queuedPaymentId' => $queuedPayment->getId(),
            'pluginName' => $this->getName(),
            'submitUrl' => $submitUrl,
        ]);
    return $paymentForm;
    }

    public function handle($args, $request): void {
        $journal = $request->getJournal();
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
        $paymentManager = Application::getPaymentManager($journal);
        $queuedPaymentId = $args[1];

        $darajaCallback = $this->getRequest()->url(null, 'payment', 'plugin', [$this->getName(), 'daraja-callback', $queuedPaymentId], []);
        $action = $args[0];

        $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId);
        if (!$queuedPayment) {
            throw new \Exception("Invalid queued payment ID {$queuedPaymentId}!");
        }

        if ($action == 'simulate'){

            if ($request->isGet()) {
                throw new \Exception("Invalid request in simulate stkPush");
            }

            try {

                $businessShortCode = $this->getSetting($journal->getId(), 'businessShortCode');
                $amount = $queuedPayment->getAmount();
                $partyA = $request->getUserVar('phoneNumber');
                $partyB = $businessShortCode;
                $phoneNumber = $partyA;
                $callBackUrl = $darajaCallback; //$request->url(null, 'payment', 'plugin', [$this->getName(), 'return'], ['queuedPaymentId' => $queuedPaymentId]);

                $accountReference = $paymentManager->getPaymentName($queuedPayment);
                $transactionDesc  = $accountReference;

                $utilities = new Utilities($this);

                $stkPush = $utilities->STKPush($businessShortCode, $amount, $partyA, $partyB, $phoneNumber, $callBackUrl, $accountReference, $transactionDesc );
                $decoded_resp = json_decode($stkPush);

                $checkoutReqId   = $decoded_resp->{'CheckoutRequestID'};
                $respDescription = $decoded_resp->{'ResponseDescription'};

                if ($decoded_resp->{'ResponseCode'} == 0){ //success, accepted for processing

                    $templateMgr = TemplateManager::getManager($request);

                    $templateMgr->assign("phoneNumber", $partyA);
                    $templateMgr->assign("pluginName", $this->getName());
                    $templateMgr->assign("queuedPaymentId", $queuedPaymentId);
                    $templateMgr->assign("checkoutReqId", $checkoutReqId);
                    $templateMgr->display(
                        $this->getTemplateResource('mpesa_confirm_payment.tpl')
                    );
                }else{ // an error occurred
                    throw new \Exception($checkoutReqId. " FAILED: " . $respDescription);
                }

            } catch (\Exception $e) {
                error_log('MPESA transaction exception: ' . $e->getMessage());
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign('message', 'plugins.paymethod.mpesa.error');
                $templateMgr->display($this->getTemplateResource('frontend/pages/message.tpl'));
            }
        }

        if ($action == 'daraja-callback'){ //handle the callback from daraja

            $callbackResp = file_get_contents('php://input');
            $decodedResp = json_decode($callbackResp)->{'Body'}->{'stkCallback'};

            $mpesaReqId = $decodedResp->{'CheckoutRequestID'};

            if($decodedResp->{'ResultCode'} == '0'){

                $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());

                $callbackMetadataItems = $decodedResp->{'CallbackMetadata'}->{'Item'};
                $receiptNumber = null;

                foreach ($callbackMetadataItems as $key => $value) {
                    if ($key == 'MpesaReceiptNumber') $receiptNumber = $value; break;
                }
                if ($receiptNumber) {
                    $this->addSettings('mpesa_receipt_for'.$mpesaReqId, $receiptNumber);
                }

            }else{
                error_log("MPESA CheckoutRequestID: ".$mpesaReqId);
                error_log($decodedResp->{'ResultDesc'});
            }
        }

        if ($action == 'confirm-payment'){ //query the transaction status

            if ($request->isGet()) {
                throw new \Exception("Invalid request in confirm-payment");
            }

            $utilities = new Utilities($this);
            $checkoutReqId = $request->getUserVar('checkoutReqId');
            $transactionStatus = $utilities->querySTKStatus($journal, $checkoutReqId);

            $templateMgr = TemplateManager::getManager($request);

            $decodedResp = json_decode($transactionStatus);
            $resultCode = null;

            if ($decodedResp->{'ResponseCode'} == '0') { $resultCode = $decodedResp->{'ResultCode'}; }

            if ($resultCode == '0'){
                $templateMgr->assign('respMsg', __('plugins.paymethod.mpesa.transactionSucceeded'));
            }else{
                $templateMgr->assign('failedMsg', __('plugins.paymethod.mpesa.transactionFailed'));
                $templateMgr->assign('respMsg', $decodedResp->{'ResultDesc'});
            }
            $templateMgr->display(
                $this->getTemplateResource('mpesa_transaction_status.tpl')
            );

        }

    }

    public function register($category, $path, $mainContextId = NULL): bool{

        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            $this->addLocaleData();
            Hook::add('Form::config::before', [$this, 'addSettings']);

            $request = Application::get()->getRequest();

            $templateMgr = TemplateManager::getManager($request);
            $mpesaLogoUrl = $request->getBaseUrl() . '/' . $this->getPluginPath() . '/images/mpesa-logo.png';

            $templateMgr->assign('mpesaLogoUrl', $mpesaLogoUrl);
            $styleUrl = $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/style.css';
            $templateMgr->addStyleSheet('mpesaStyles', $styleUrl);
        }

        return $success;
    }

    public function saveSettings(string $hookname, array $args): void {
        $slimRequest = $args[0];
        $request = $args[1];
        $updatedSettings = $args[3];

        $allParams = $slimRequest->getParsedBody();
        $saveParams = [];
        foreach ($allParams as $param => $val) {
            switch ($param) {
                case 'mpesaTestMode':
                    $saveParams[$param] = $val === 'true';
                    break;
                case 'consumerId':
                case 'mpesaPassKey':
                case 'businessShortCode':
                case 'consumerSecret':
                    $saveParams[$param] = (string) $val;
                    break;
            }
        }
        $contextId = $request->getContext()->getId();
        foreach ($saveParams as $param => $val) {
            $this->updateSetting($contextId, $param, $val);
            $updatedSettings->put($param, $val);
        }
    }

    public function addSettings($hookName, $form): void {
        // TODO: Implement saveSettings() method.
        import('lib.pkp.classes.components.forms.context.PKPPaymentSettingsForm'); // Load constant
        if ($form->id !== FORM_PAYMENT_SETTINGS) {
            return;
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        $form->addGroup([
            'id' => 'mpesapayment',
            'label' => 'MPESA Fee Payment',
            'showWhen' => 'paymentsEnabled',
        ])
            ->addField(new \PKP\components\forms\FieldOptions('mpesaTestMode', [
                'label' => 'Test mode',
                'options' => [
                    ['value' => true, 'label' => __('common.enable')]
                ],
                'value' => (bool) $this->getSetting($context->getId(), 'mpesaTestMode'),
                'groupId' => 'mpesapayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('consumerId', [
                'label' => 'Consumer ID',
                'value' => $this->getSetting($context->getId(), 'consumerId'),
                'groupId' => 'mpesapayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('consumerSecret', [
                'label' => 'Consumer Secret',
                'value' => $this->getSetting($context->getId(), 'consumerSecret'),
                'groupId' => 'mpesapayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('mpesaPassKey', [
                'label' => 'Pass Key',
                'value' => $this->getSetting($context->getId(), 'mpesaPassKey'),
                'groupId' => 'mpesapayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('businessShortCode', [
                'label' => 'Business Short Code',
                'value' => $this->getSetting($context->getId(), 'businessShortCode'),
                'groupId' => 'mpesapayment',
            ]));

    }

    public function getDisplayName(): string{
        return 'MPESA Payment';
    }
    public function getName(): string{
        return 'MpesaPayment';
    }

    public function getDescription(): string {
        return 'Allows MPESA integration with OJS >= 3.4';
    }
    public function isConfigured($context): bool {
        if (!$context) {
            return false;
        }
        if ($this->getSetting($context->getId(), 'consumerId') == '') {
            return false;
        }
        return true;
    }

    public function isTestMode($context): bool {

        if (!$context) return false;

        if ($this->getSetting($context->getId(), 'mpesaTestMode') == '1') {
            return true;
        }
        return false;
    }

}