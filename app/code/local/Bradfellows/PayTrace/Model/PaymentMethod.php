<?php

// http://www.magentocommerce.com/wiki/5_-_modules_and_development/payment/create-payment-method-module

/**
 * Paytrace CC module adapter
 */

class Bradfellows_PayTrace_Model_PaymentMethod extends Mage_Paygate_Model_Authorizenet
{

	const CGI_URL_TD = 'https://paytrace.com/api/default.pay';

	/**
	 * for internal testing
	 * TEST_MODE appends the test flag to requests
	 * DEMO_ACCOUNT ovverrides UN,PSWD,CC,CC,AMT kv pairs for Paytrace to use demo account authorizations
	 * DEMO_AUTH_AMT overrides auth amount if DEMO_ACCOUNT == true - used to manipulate response codes for testing
	 * 		see: http://help.paytrace.com/api (bottom of page)
	 */
	const TEST_MODE = FALSE;
	const DEMO_ACCOUNT = FALSE;
	const DEMO_AUTH_AMT = 0.50;

	/**
	 * Approval Codes
	 */

	/**
	 * unique internal payment method identifier
	 */
	protected $_code = 'paytrace';

	protected $_isGateway				=true;	// is this payment method a gateway?
	protected $_canAuthorize			=true;	// can auth online?
	protected $_canCapture				=false;	// can capture funds online?
	protected $_canCapturePartial		=false;	// can capture partial amounts online?
	protected $_canRefund				=false;	// can refund online?
	protected $_canVoid					=false;	// can void transactions online?
	protected $_canUseInternal			=true;	// can use this payment method in admin panel?
	protected $_canUseCheckout			=true;	// can use this payment method on checkout payment page?
	protected $_canUseForMultiShipping	=false;	// is this payment method suitable for multi-shipping checkout?
	protected $_canSaveCc				=true;	// can save credit card information for future processing?

	protected $_formBlockType = 'paytrace/tokenized_cc';
	protected $_infoBlockType = 'paytrace/tokenized_info';

	/**
	 * implement auth, capture, and void public methods
	 * @see examples of transaction specific public methods in Mage_Paygate_Model_Authorizenet
	 */

	public function authorize(Varien_Object $payment, $amount)
	{
		$token = $this->_getToken();

		$orderId = $payment->getOrder()->getIncrementId();
		$order = $payment->getOrder();
		if(self::TEST_MODE || self::DEMO_ACCOUNT){Mage::log("attempt to auth payment on paytrace module for order id: $orderId / amount: $amount");}

		// build parmstring
		$txString = $this->_prepareAuthRequest($payment,$amount,$token);

		// send request to paytrace, set response
		$response = $this->_requestTransaction($txString);

		// check response for error
		if($this->_isResponseError($response)) {
			// if is error, throw exception
			Mage::throwException("Sorry, there was trouble communicating with the card authorization network. Please try again later.");
			return $this; // something is broken, exit early
		}

		// if it didn't exit in the if above, no communication errors. parse it
		$responseData = $this->_parseResponse($response);

		// check if approved
		if(!$this->_isApproved($responseData)){
			// set an exception if not
			Mage::throwException("Transaction declined.");
		}
		else {
			// otherwise close the payment and record the transaction id
			if(self::TEST_MODE || self::DEMO_ACCOUNT){Mage::log(print_r($responseData,1));}

			$payment->setTransactionId($responseData['TRANSACTIONID']);
			$payment->setAdditionalInformation(array('response'=>$responseData)); // why doesn't this work?
			$payment->setIsTransactionClosed(0);
		}

		// always return $this
		return $this;
	}

	protected function _isApproved($responseArray)
	{
		if(empty($responseArray['APPCODE'])) {return false;}
		return true;
	}

	// split up the response string and return a hash
	protected function _parseResponse($response)
	{
		$arr = array();

		foreach(explode("|",$response) as $pair){
			if(!empty($pair)){
				$pArr = explode("~",$pair);
				$arr[$pArr[0]] = $pArr[1];
			}
		}

		return $arr;
	}


	// checks if response returned an error
	protected function _isResponseError($response){
		// keep it simple, just do a regex match
		if(empty($response) || preg_match('/ERROR~/',$response)){ return true; }
		return false;
	}


	// prepares transaction request string for Paytrace
	protected function _prepareAuthRequest($payment,$amount,$token)
	{
		// name/value pairs:
		// UN, PSWD, TERMS, METHOD, TRANXTYPE, AMOUNT, CC, EXPMNTH, EXPYR

		$cc			= $payment->getCcNumber();
		$expmnth	= $payment->getCcExpMonth();
		$expyr		= $payment->getCcExpYear();
		$last4		= $payment->getCcLast4();
		$csc		= $payment->getCcCid();

		// create a hash to hold the k/v pairs
		// build a different hash for tokenized payments
		if($token == 'manual'){
			$transactionData = array(
				'UN' => self::DEMO_ACCOUNT ? 'demo123' : $this->getConfigData('paytrace_user'),
				'PSWD' => self::DEMO_ACCOUNT ? 'demo123' : $this->getConfigData('paytrace_password'),
				'TERMS' => 'Y',
				'METHOD' => 'ProcessTranx',
				'TRANXTYPE' => 'Authorization',
				'AMOUNT' => self::DEMO_ACCOUNT ? self::DEMO_AUTH_AMT : $amount,
				'CC' => $cc,
				'EXPMNTH' => $expmnth,
				'EXPYR' => $expyr,
				'CSC' => self::DEMO_ACCOUNT ? '999' : $csc,
			);
		} else {
			$transactionData = array(
				'UN' => self::DEMO_ACCOUNT ? 'demo123' : $this->getConfigData('paytrace_user'),
				'PSWD' => self::DEMO_ACCOUNT ? 'demo123' : $this->getConfigData('paytrace_password'),
				'TERMS' => 'Y',
				'METHOD' => 'ProcessTranx',
				'TRANXTYPE' => 'Authorization',
				'AMOUNT' => self::DEMO_ACCOUNT ? self::DEMO_AUTH_AMT : $amount,
				'CUSTID' => $token,
			);
		}

		// loop through hash, building the request string
		// what does "parmlist" mean?
		$retval = 'parmlist=';
		foreach($transactionData as $k=>$v){
			$retval .= urlencode("$k~$v|");
		}

		// set test mode using the TEST_MOST constant
		if(self::TEST_MODE){ $retval .= urlencode("TEST~Y|"); }
		if(self::TEST_MODE || self::DEMO_ACCOUNT){Mage::log($retval);}

		// return the request string
		return $retval;
	}

	// requests a transaction against paytrace with curl
	protected function _requestTransaction($txString)
	{
		// this is basically their example code for PHP, simplified

		$header = array("MIME-Version: 1.0","Content-type: application/x-www-form-urlencoded","Contenttransfer-encoding: text");
		$url = self::CGI_URL_TD;

		$ch = curl_init();

		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_VERBOSE,1);
		curl_setopt($ch,CURLOPT_PROXYTYPE,CURLPROXY_HTTP);

		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch,CURLOPT_POST,TRUE);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$txString);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
		curl_setopt($ch,CURLOPT_TIMEOUT,10);

		$response = curl_exec($ch);

		if(self::TEST_MODE || self::DEMO_ACCOUNT || true==true){Mage::log("response: $response");}

		curl_close($ch);

		return $response;
	}

	/**
	 * Note to self:
	 * Custom fields are stored using the addData
	 */
	public function assignData($data)
	{
		if(!($data instanceof Varien_Object)) {
			$data = new Varien_Object($data);
		}
        $info = $this->getInfoInstance();

		$info->setAdditionalData(serialize(array('cc_token'=>$data['cc_token'])));

		if( $data['cc_token'] == 'manual' ){
	        $info->setCcType($data->getCcType())
	            ->setCcOwner($data->getCcOwner())
	            ->setCcLast4(substr($data->getCcNumber(), -4))
	            ->setCcNumber($data->getCcNumber())
	            ->setCcCid($data->getCcCid())
	            ->setCcExpMonth($data->getCcExpMonth())
	            ->setCcExpYear($data->getCcExpYear())
	            ->setCcSsIssue($data->getCcSsIssue())
	            ->setCcSsStartMonth($data->getCcSsStartMonth())
	            ->setCcSsStartYear($data->getCcSsStartYear())
	            ;
		} else {
			$customer_tokens = $this->_getPaytraceTokens($info);
			$paytrace = null;
			foreach($customer_tokens as $tk=>$tv){
				if($tv->token_id == $data['cc_token']){
					$paytrace = $tv;
				}
			}

			$last4 = empty($paytrace->token_id) ? '' : $paytrace->last4;

			$info->setCcType('')
	            ->setCcOwner('')
	            ->setCcLast4($last4)
	            ->setCcNumber('999999999999'.$last4)
	            ->setCcCid('')
	            ->setCcExpMonth('')
	            ->setCcExpYear('')
	            ->setCcSsIssue('')
	            ->setCcSsStartMonth('')
	            ->setCcSsStartYear('')
	            ;
		}

		return $this;
	}

	// overriding the validate method. I want to keep some but not others
	// TODO: merge in base class and parent class validation logic, right now it validates anything.
	// TODO: two validation paths - one for selecting a saved card, one for entering a card
	public function validate()
	{
		$info = $this->getInfoInstance();
        $errorMsg = false;
        $availableTypes = explode(',',$this->getConfigData('cctypes'));

		$token = $this->_getToken($info);

		if($token == 'manual'){
			parent::validate();
		} else {
			$this->_validateToken();
		}

		return $this;
	}

	private function _validateToken()
	{
		// token info
		$info = $this->getInfoInstance();
		$token = $this->_getToken($info);

		$customer_tokens = $this->_getPaytraceTokens($info);

		$found = false;
		foreach($customer_tokens as $tk=>$tv){
			if($tv->token_id == $token) {
				$found = true;
			}
		}

		if(!$found){Mage::throwException("There was a problem loading selected payment method.");}
	}

	private function _getPaytraceTokens($info)
	{
		//customer info
		$customer_data=Mage::getSingleton('customer/session')->getCustomer();
		$customer_id = $customer_data->getEmail();

		//request info
		$url = 'PATH TO TOKEN STORAGE';
		$data = array('customerid'=>md5($customer_id));

		$options = array(
			'http' => array(
				'header'	=> "Content-type: application/x-www-form-urlencoded",
				'method'	=> 'POST',
				'content'	=> http_build_query($data),
			),
		);
		$context = stream_context_create($options);

		//execute request
		$result = file_get_contents($url, false, $context);

		return json_decode($result);
	}

	private function _getToken($info = null)
	{
		if($info == null){
			$info = $this->getInfoInstance();
		}

		$addData = unserialize($info->getAdditionalData());

		if (empty($addData['cc_token'])) {
			$token = false;
		} else {
			$token = $addData['cc_token'];
		}

		return $token;
	}
}
