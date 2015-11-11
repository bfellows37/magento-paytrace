<?php

class Bradfellows_PayTrace_ManageController extends Mage_Core_Controller_Front_Action {

	const TEST_MODE = false;
	const DEMO_ACCOUNT = false;
	const CGI_URL_TD = 'https://paytrace.com/api/default.pay';

	public function indexAction() {
		$this->loadLayout();
    	$this->renderLayout();
    	//var_dump($this->getLayout()->getUpdate()->getHandles());
	}

	// front action for listing all a customer's payment methods
	public function listAction() {
		$customer_data=Mage::getSingleton('customer/session')->getCustomer();
		$customer_id = $customer_data->getEmail();

		$stored_payments = $this->getStoredPayments($customer_id);

		echo $stored_payments;
	}

	// front action for adding customer profile to paytrace,
	// and if successful adds the less sensitive metadata
	// to micro's offsite storage
	//TODO: make this call a add method from the payment method model
	public function addAction(){

		$customer_data=Mage::getSingleton('customer/session')->getCustomer();

		$postdata = new stdClass();
		$postdata->customer_id = $customer_data->getEmail();
		$postdata->cc_num = $this->getRequest()->getPost('CcNum');
		$postdata->month = $this->getRequest()->getPost('Month');
		$postdata->year	= $this->getRequest()->getPost('Year');
		$postdata->name = $this->getRequest()->getPost('Name');
		$postdata->cardtype = $this->getRequest()->getPost('CardType');

		$paytrace_response = $this->addPaytraceProfile($postdata);

		$retval = new stdClass();

		if(!preg_match("/error/i",$paytrace_response)){

			$meta_response = $this->addPaytraceMeta($postdata, $paytrace_response);
			//Mage::log($meta_response);

			if($meta_response){
				$retval->success = true;
			} else {
				$retval->success = false;
				$retval->success = 'There was a problem adding this payment method[2].';
			}

		} else {
			$postdata->cc_nm = substr($postdata->cc_num,0,-4);
			Mage::log($paytrace_response);
			Mage::log($postdata);

			$retval->success = false;
			$retval->message = 'There was a problem adding this payment method[1].';

		}
		echo json_encode($retval);
	}

	public function removeAction()
	{
		$customer_data=Mage::getSingleton('customer/session')->getCustomer();
		$token_id = $this->getRequest()->getPost('TokenId');

		// first attempt to delete from Paytrace
		$paytrace_response = $this->removePaytraceProfile($token_id);

		$retval = new stdClass();

		// then attempt to delete from meta
		if(!preg_match("/error/i",$paytrace_response)){
			$meta_response = $this->removePaytraceMeta($token_id);

			if($meta_response){
				$retval->success = true;
			} else {
				$retval->success = false;
				$retval->message = 'There was a problem removing this payment method.';
			}
		} else {
			$retval->success = false;
			$retval->message = 'There was a problem removing this payment method.';
		}

		echo json_encode($retval);
	}

	private function removePaytraceMeta($token_id)
	{
		$url = 'https://my.service.url/index.php/tkn/removeItem';
		$data = array(
			'tokenid' => $token_id,
		);
		$options = array(
			'http' => array(
				'header'	=> 'Content-type: application/x-www-form-urlencoded',
				'method'	=> 'POST',
				'content'	=> http_build_query($data),
			),
		);
		$context = stream_context_create($options);
		$result = file_get_contents($url,false,$context);
		$result = json_decode($result);

		return !$result->error;
	}

	private function addPaytraceMeta($postdata, $paytrace_response)
	{
		$pt_array = array();
		foreach(explode("|",$paytrace_response) as $pk=>$pv){
			$exp = explode("~",$pv);
			if(sizeof($exp)>1){
				$pt_array[$exp[0]] = $exp[1];
			}
		}

		$url = 'http://my.service.url/index.php/tkn/addItem';
		$data = array(
			'token_id'		=> $pt_array['CUSTOMERID'],
			'customer_id'	=> md5($postdata->customer_id),
			'last4'			=> substr($postdata->cc_num,-4),
			'expires'		=> "$postdata->month/$postdata->year",
			'name'			=> $postdata->name,
			'cardtype'		=> $postdata->cardtype,
		);

		$options = array(
			'http' => array(
				'header'	=> "Content-type: application/x-www-form-urlencoded",
				'method'	=> 'POST',
				'content'	=> http_build_query($data),
			),
		);
		$context = stream_context_create($options);
		$result = file_get_contents($url, false, $context);
		$result = json_decode($result);
		Mage::log('service response: '.$result);

		if($result->error) {
			return FALSE;
		}
		return TRUE;
	}

	// gets stored payments data from offsite storage
	//TODO: figure out how to get this into the payment method model and call it from here
	private function getStoredPayments($customer_id)
	{
		$url = 'http://my.service.url/index.php/tkn/listItems';
		//$url = 'http://192.168.50.132/microk12-ci/index.php/tkn/listItems';
		$data = array('customerid'=>md5($customer_id));

		$options = array(
			'http' => array(
				'header'	=> "Content-type: application/x-www-form-urlencoded",
				'method'	=> 'POST',
				'content'	=> http_build_query($data),
			),
		);
		$context = stream_context_create($options);
		$result = file_get_contents($url, false, $context);

		return $result;
	}

	// adds customer profile to Paytrace
	// TODO: put this in the payment method model and call it there
	private function addPaytraceProfile($postdata)
	{
		//$methodModel = ;
		//Mage::log(Mage::getModel('MicroK12_PayTrace_Model_PaymentMethod')->getConfigData("paytrace_password"));

		$transactionData = array(
			'UN' => self::DEMO_ACCOUNT ? 'demo123' : Mage::getModel('MicroK12_PayTrace_Model_PaymentMethod')->getConfigData("paytrace_user"),
			'PSWD' => self::DEMO_ACCOUNT ? 'demo123' : Mage::getModel('MicroK12_PayTrace_Model_PaymentMethod')->getConfigData("paytrace_password"),
			'TERMS' => 'Y',
			'METHOD' => 'CreateCustomer',
			'CUSTID' => md5($postdata->customer_id)."_".time(),
			'BNAME' => $postdata->name,
			'CC' => $postdata->cc_num,
			'EXPMNTH' => $postdata->month,
			'EXPYR' => $postdata->year,
		);

		// what does "parmlist" mean?
		$reqString = 'parmlist=';
		foreach($transactionData as $k=>$v){
			$reqString .= urlencode("$k~$v|");
		}
		// toggle test mode using the TEST_MOST constant
		if(self::TEST_MODE){ $retval .= urlencode("TEST~Y|"); }
		/*Mage::log($reqString);
		Mage::log($transactionData);*/

		$header = array("MIME-Version: 1.0","Content-type: application/x-www-form-urlencoded","Contenttransfer-encoding: text");
		$url = self::CGI_URL_TD;

		$ch = curl_init();

		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_VERBOSE,1);
		curl_setopt($ch,CURLOPT_PROXYTYPE,CURLPROXY_HTTP);

		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch,CURLOPT_POST,TRUE);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$reqString);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
		curl_setopt($ch,CURLOPT_TIMEOUT,10);

		$response = curl_exec($ch);

		if(self::TEST_MODE || self::DEMO_ACCOUNT || true==true){Mage::log("response: $response");}

		curl_close($ch);

		//die($response);

		return $response;

		/*$retval = new stdClass();
		$retval->success = true;
		$retval->message = 'Stored payment method added.';

		return json_encode($retval);*/
	}

	private function removePaytraceProfile($token_id)
	{
		$transactionData = array(
			'UN' => self::DEMO_ACCOUNT ? 'demo123' : Mage::getModel('MicroK12_PayTrace_Model_PaymentMethod')->getConfigData("paytrace_user"),
			'PSWD' => self::DEMO_ACCOUNT ? 'demo123' : Mage::getModel('MicroK12_PayTrace_Model_PaymentMethod')->getConfigData("paytrace_password"),
			'TERMS' => 'Y',
			'METHOD' => 'DeleteCustomer',
			'CUSTID' => $token_id,
		);

		$reqString = 'parmlist=';
		foreach($transactionData as $k=>$v) {
			$reqString.= urlencode("$k~$v|");
		}
		if(self::TEST_MODE){ $retval .= urlencode("TEST~Y|"); }

		$header = array("MIME-Version: 1.0","Content-type: application/x-www-form-urlencoded","Contenttransfer-encoding: text");
		$url = self::CGI_URL_TD;

		$ch = curl_init();

		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_VERBOSE,1);
		curl_setopt($ch,CURLOPT_PROXYTYPE,CURLPROXY_HTTP);

		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch,CURLOPT_POST,TRUE);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$reqString);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
		curl_setopt($ch,CURLOPT_TIMEOUT,10);

		$response = curl_exec($ch);

		if(self::TEST_MODE || self::DEMO_ACCOUNT || true == true){
			Mage::log("request: $reqString");
			Mage::log("response: $response");
		}

		curl_close($ch);

		//die($response);

		return $response;
	}

	//makes the manage page only available to logged in users
	public function preDispatch()
	{
		parent::preDispatch();
		$action = $this->getRequest()->getActionName();
		$loginUrl = Mage::helper('customer')->getLoginUrl();

		if(!Mage::getSingleton('customer/session')->authenticate($this,$loginUrl)) {
			$this->setFlag('', self::FLAG_NO_DISPATCH, true);
		}
	}
}
