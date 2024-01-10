<?php

/**
 * @file plugins/paymethod/mpesa/templates/Utilities.php
 *
 * Copyright (c) 2024 HyperLink DSL
 * Copyright (c) 2024 Otuoma Sanya
 * Distributed under the GNU GPL v3.
 * @class Utilities
 * @brief Mpesa payment page
 */

namespace APP\plugins\paymethod\mpesa;

use APP\core\Application;

class Utilities {

    public MpesaPlugin $plugin;

    public function __construct(MpesaPlugin $plugin){
        $this->plugin = $plugin;
    }

    public function STKPush($BusinessShortCode, $Amount, $PartyA, $PartyB, $PhoneNumber, $CallBackURL, $AccountReference, $TransactionDesc): bool|string
    {

        $context = Application::get()->getRequest()->getContext();

        $testMode = $this->plugin->isTestMode($context);

        if( $testMode ){
            $reqUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
            $token = $this->generateSandBoxToken();
        }else{
            $reqUrl = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
            $token = $this->generateLiveToken();
        }

        $timestamp = '20' . date(    "ymdhis");
        $passKey = $this->plugin->getSetting($context, "mpesaPassKey");
        $password= base64_encode($BusinessShortCode.$passKey.$timestamp);
        $transactionType = "CustomerPayBillOnline";

        $reqHeaders = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        ];

        $reqBody = [
            'BusinessShortCode' => $BusinessShortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $transactionType,
            'Amount' => $Amount,
            'PartyA' => $PartyA,
            'PartyB' => $PartyB,
            'PhoneNumber' => $PhoneNumber,
            'CallBackURL' => $CallBackURL,
            'AccountReference' => $AccountReference,
            'TransactionDesc' => $TransactionDesc,
        ];
        $httpClient = Application::get()->getHttpClient();

        $resp = $httpClient->request('POST', $reqUrl, [
            'headers' => $reqHeaders,
            'json' => $reqBody,
        ]);

        return $resp->getBody();
    }

    public function querySTKStatus($context, $checkoutRequestID){

        try {
            $token = $this->plugin->isTestMode($context)
                ? $this->generateSandBoxToken()
                : $this->generateLiveToken();

            $testUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
            $liveUrl = 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
            $businessShortCode = $this->plugin->getSetting($context, 'businessShortCode');

            $reqUrl = $this->plugin->isTestMode($context) ? $testUrl : $liveUrl;

            $timestamp = '20' . date(    "ymdhis");
            $passKey = $this->plugin->getSetting($context, 'mpesaPassKey');
            $password= base64_encode($businessShortCode.$passKey.$timestamp);
            $reqHeaders = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ];

            $reqBody = [
                'BusinessShortCode' => $businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestID
            ];
            $httpClient = Application::get()->getHttpClient();
            $resp = $httpClient->request('POST', $reqUrl, [
                'headers' => $reqHeaders,
                'json' => $reqBody,
            ]);

            return $resp->getBody();

        } catch (\Exception $e) {
            error_log($e->getMessage());
            return $e->getMessage();
        }
    }
    public function generateLiveToken(){

        $context = Application::get()->getRequest()->getContext();

        $consumerId = $this->plugin->getSetting($context->getId(), 'consumerId');
        $consumerSecret = $this->plugin->getSetting($context->getId(), 'consumerSecret');

        if(!isset($consumerId)||!isset($consumerSecret)){
            die("LIVE - please declare the consumer key and consumer secret as defined in the documentation");
        }
        $reqUrl = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

        $credentials = base64_encode($consumerId . ':' . $consumerSecret);
        $httpClient = Application::get()->getHttpClient();
        $response = $httpClient->request('GET', $reqUrl, [
            'headers' => [ 'Authorization' => 'Basic ' . $credentials]
        ]);

        $respBody = $response->getBody()->getContents();
        $decodedResp = json_decode($respBody);
        return $decodedResp->access_token;

    }
    public function generateSandBoxToken(){

        $context = Application::get()->getRequest()->getContext();

        $consumerId = $this->plugin->getSetting($context->getId(), 'consumerId');
        $consumerSecret = $this->plugin->getSetting($context->getId(), 'consumerSecret');

        if(!isset($consumerId)||!isset($consumerSecret)){
            die("SANDBOX - please declare the consumer key and consumer secret as defined in the documentation");
        }
        $reqUrl = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

        $credentials = base64_encode($consumerId . ':' . $consumerSecret);
        $httpClient = Application::get()->getHttpClient();
        $response = $httpClient->request('GET', $reqUrl, [
            'headers' => [ 'Authorization' => 'Basic ' . $credentials]
        ]);

        $respBody = $response->getBody()->getContents();
        $decodedResp = json_decode($respBody);
        return $decodedResp->access_token;

    }

}