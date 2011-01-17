<?php

App::import('Core', 'HttpSocket');

class AuthnetSource extends DataSource {

	/**
	*
	* The description of this data source
	*
	* @var string
	*/
	public $description = 'Authorize.net DataSource';

	/**
	*
	* Default configuration
	*
	* @var array
	*/
	public $_baseConfig = array(
		"server" => 'test',
		"test_request" => false,
		"login" => NULL,
		"key" => NULL,
		"email" => false,

		"duplicate_window" => "120",
		"payment_method" => "CC",
		"default_type" => "AUTH_CAPTURE",
		'delimit_response' => true,
		"response_delimiter" => "|",
		"response_encapsulator" => "",
		'api_version' => '3.1',
		'payment_method' => 'CC',
		'relay_response' => false
		);

	/**
	*
	* Translation for Authnet POST data keys from default config keys
	*
	* @var array
	*/
	public $_translation = array(
		'card_num' => 'card_number',
		'exp_date' => 'expiration',
		'type' => 'default_type',
		'trans_id' => 'transaction_id',
		'login' => 'login',
		'tran_key' => 'key',
		'test_request' => 'test_request',
		'duplicate_window' => 'duplicate_window',
		'delim_data' => 'delimit_response',
		'delim_char' => 'response_delimiter',
		'encap_char' => 'response_encapsulator',
		'relay_response' => 'relay_response',
		'version' => 'api_version',
		'method' => 'payment_method',
		'email_customer' => 'email',
		'first_name' => 'billing_first_name',
		'last_name' => 'billing_last_name',
		'company' => 'billing_company',
		'street' => 'billing_street',
		'city' => 'billing_city',
		'state' => 'billing_state',
		'zip' => 'billing_zip',
		'country' => 'billing_country',
		'phone' => 'billing_phone',
		'fax' => 'billing_fax',
		'email' => 'billing_email',
		);

	//public $cacheSources = false;

	/**
	*
	* These fields are often defined in the data set, but don't need to be sent to Authnet
	*
	* @var array
	*/
	public $_fieldsToIgnore = array(
		'AuthnetPluginVersion', 	'datasource', 	'logModel',
		'test_account', 	'test_cvv', 	'test_expire', 	'test_name', 	'test_address', 	'test_zip',
		);
	
	/**
	*
	* HttpSocket object
	*
	* @var object
	*/
	public $Http;

	/**
	*
	* Set configuration and establish HttpSocket with appropriate test/production url.
	*
	* @param config an array of configuratives to be passed to the constructor to overwrite the default
	*/

	public function __construct($config) {
		parent::__construct($config);
		$this->Http = new HttpSocket();
	}

	/**
	*
	* Not currently possible to read data posted authorize.net. Method not implemented.
	*
	*/

	public function read(&$Model, $queryData = array()) {
		return false;
	}

	/**
	*
	* Create a new single or ARB transaction
	*
	*/

	public function create(&$Model, $fields = array(), $values = array()) {
		$data = array_combine($fields, $values);
		$data = Set::merge($this->config, $data);
		$result = $this->__request($Model, $data);
		return $result;
	}

	/**
	*
	* Capture a previously authorized transaction
	*
	*/
	public function update(&$Model, $fields = null, $values = null) {
		$data = array_combine($fields, $values);
		if ((float)$data['amount'] >= 0) {
			$data = Set::merge($data, array('default_type' => 'PRIOR_AUTH_CAPTURE'));
		} else {
			// if a negative value is passed, assuming refund
			$data = Set::merge($data, array('default_type' => 'CREDIT'));
		}
		$data = Set::merge($this->config, $data);
		return $this->__request($Model, $data);
	}

	/**
	*
	* Void an authorize.net transaction
	*
	*/
	public function delete(&$Model, $id = null) {
		if (empty($id)) {
			$id = $Model->id;
		}
		$data = array(
			'transaction_id' => $id,
			'default_type' => 'VOID'
			);
		$data = Set::merge($this->config, $data);
		return $this->__request($Model, $data);
	}

	/**
	*
	* Unsupported methods other CakePHP model and related classes require.
	*
	*/
	public function listSources() {}

	/**
	*
	* Translate keys to a value Authorize.net expects in posted data, as well as encapsulating where relevant. Returns false
	* if no data is passed, otherwise array of translated data.
	*
	* @param array $data
	* @return mixed
	*/

	private function __prepareDataForPost($data = null) {
		if (empty($data)) {
			return false;
		}

		$encapsulators = array('line_items','taxes','freight','duty');
		$return = array();
		
		$data = array_diff_key($data, array_flip($this->_fieldsToIgnore));
		
		$_translator = array_combine(array_values($this->_translation), array_keys($this->_translation));
		
		foreach ($data as $key => $value) {
			if (empty($value)) {
				continue;
			}
			if (in_array($key, $encapsulators)) {
				if (is_array($value)) {
					$value = implode('<|>', $value);
				}
			}

			if (in_array($key, $this->_translation)) {
				$key = $_translator[$key];
			}

			$return["x_{$key}"] = $value;
		}

		return $return;
	}
	
	/**
	* Parse the response data from a post to authorize.net
	* @param object $Model
	* @param string $response
	* @param array $input
	* @param string $url
	* @return array
	*/
	private function __parseResponse(&$Model, $response, $input=null, $url=null) {
		$status = 'unknown';
		$error = $transaction_id = null;
		if (!empty($response) && is_string($response)) {
			if (!empty($response[1]) && $response[1] == $this->config['response_delimiter']) {
				$response = explode($this->config['response_delimiter'], $response);
			}
		}
		$ami_post_response_fields = array(
			'response_code',
			'response_subcode',
			'response_reason_code',
			'response_reason_text',
			'authorization_code',
			'avs_response',
			'transaction_id',
			'invoice_number',
			'description',
			'amount',
			'method',
			'transaction_type',
			'customer_id',
			'first_name',
			'last_name',
			'company',
			'address',
			'city',
			'state',
			'zip',
			'country',
			'phone',
			'fax',
			'email',
			'ship_first_name',
			'ship_last_name',
			'ship_company',
			'ship_address',
			'ship_city',
			'ship_state',
			'ship_zip',
			'ship_country',
			'tax',
			'duty',
			'freight',
			'tax_exempt',
			'po_number',
			'md5_hash',
			'card_code_response',
			'cardholder_authentication_verification_response',
			'account_number',
			'card_type',
			'split_tender_id',
			'requested_amount',
			'balance_on_card',
			);
		$response = array_combine($ami_post_response_fields, array_slice($response, 0, count($ami_post_response_fields)));
		$response_codes = array(
			'0' => 'unknown',
			'1' => 'good',
			'2' => 'declined',
			'3' => 'error',
			'4' => 'held for review'
			);
		$status = $response_codes[$response["response_code"]];
		$Model->id = 0;
		if ($response["response_code"] == 1) {
			$data = array();
			// good
			if (!empty($response["transaction_id"])) {
				$Model->id = $transaction_id = $response["transaction_id"];
				$Model->setInsertID($Model->id);
				$data[$Model->alias] = $response;
			} elseif (!empty($response["authorization_code"])) {
				if (isset($Model->requestData) && isset($Model->requestData["transaction_id"])) {
					$Model->id = $transaction_id = $response["transaction_id"] = $Model->requestData["transaction_id"];
					$Model->setInsertID($Model->id);
					$data[$Model->alias] = $response;
				}
				$data[$Model->alias] = $response;
			} elseif (isset($input["x_trans_id"]) && !empty($input["x_trans_id"])) {
				$Model->id = $transaction_id = $input["x_trans_id"];
				$Model->setInsertID($Model->id);
				$data[$Model->alias] = $response;
			}
			$data = Set::merge($Model->data, $data);
			$Model->set($data);
		} else {
			// bad
			$subcodesToFields = array(
				'5' => 'amount',
				'6' => 'card_number',
				'7' => 'expiration',
				'8' => 'expiration',
				'15' => 'transaction_id',
				'16' => 'transaction_id',
				'17' => 'card_number',
				'27' => 'billing_street',
				'28' => 'card_number',
				'33' => 'VARIED',
				'37' => 'card_number',
				'47' => 'amount',
				'48' => 'amount',
				'49' => 'amount',
				'50' => 'transaction_id',
				'51' => 'amount',
				'54' => 'transaction_id',
				'55' => 'amount',
				'72' => 'authorization_code',
				'74' => 'duty',
				'75' => 'freight',
				'76' => 'taxes',
				'127' => 'billing_street',
				'243' => 'recurring',
				//'310' => 'transaction_id',
				//'311' => 'transaction_id',
				'315' => 'card_number',
				'316' => 'expiration',
				'317' => 'expiration'
				);

			if (array_key_exists($response["response_subcode"], $subcodesToFields)) {
				if ($response["response_reason_code"] == 33) {
					if (stristr($response[3], 'expiration date')) {
						$field = 'expiration';
					} elseif (stristr($response[3], 'transaction ID')) {
						$field = 'transaction_id';
					} else {
						$field = 'card_number';
					}
				} else {
					$field = $subcodesToFields[$response[2]];
				}
				$Model->invalidate($field, $response[3]);
			} else {
				$Model->invalidate('declined', array($status, $response["response_reason_code"], $response["response_reason_text"]));
			}
			$error = "{$status}: [{$response['response_reason_code']}] {$response['response_reason_text']}";
		}
		$avs_responses = array(
			'A' => 'Address (Street) matches, ZIP does not',
			'B' => 'Address information not provided for AVS check',
			'E' => 'AVS error',
			'G' => 'Non-U.S. Card Issuing Bank',
			'N' => 'No Match on Address (Street) or ZIP',
			'P' => 'AVS not applicable for this transaction',
			'R' => 'Retry — System unavailable or timed out',
			'S' => 'Service not supported by issuer',
			'U' => 'Address information is unavailable',
			'W' => 'Nine digit ZIP matches, Address (Street) does not',
			'X' => 'Address (Street) and nine digit ZIP match',
			'Y' => 'Address (Street) and five digit ZIP match',
			'Z' => 'Five digit ZIP matches, Address (Street) does not',
			);
		$avs_response = $avs_responses[($response["avs_response"])];
		
		
		$response_reason = $response['response_reason_text'];
		return compact('status', 'transaction_id', 'error', 'response', 'response_reason', 'avs_response', 'input', 'data', 'url');
	}

	/**
	*
	* Post data to authorize.net. Returns false if there is an error,
	* or an array of the parsed response from authorize.net if valid
	*
	* @param array $request
	* @return mixed
	*/
	private function __request(&$Model, $data) {
		if (empty($data)) {
			return false;
		}
		if (!empty($data['server'])) {
			$server = $data['server'];
			unset($data['server']);
		} else {
			$server = $this->config['server'];
		}
		if ($server == 'live') {
			$url = 'https://secure.authorize.net/gateway/transact.dll';
		} else {
			$url = 'https://test.authorize.net/gateway/transact.dll';
		}
		$data = $this->__prepareDataForPost($data);
		$this->Http->reset();
		$response = $this->Http->post($url, $data, array(
			'header' => array(
    			'Connection' => 'close',
    			'User-Agent' => 'CakePHP Authnet Plugin v.'.$this->config['AuthnetPluginVersion'],
				)
			));
		
		if ($this->Http->response['status']['code'] != 200) {
			$Model->errors[] = $error = 'AuthnetSource: Error: Could not connect to authorize.net... bad credentials?';
			trigger_error(__d('adobe_connect', $error, true), E_USER_WARNING);
			return false;
		}
		$Model->response = $return = $this->__parseResponse($Model, $response, $data, $url);
		
		// log to an array on the model
		if (isset($Model->log) && is_array($Model->log)) {
			$Model->log[] = $return;
		}
		// log to a model (database table), if setup on the model
		if (isset($Model->logModel) && is_object($Model->logModel)) {
			$Model->logModel->create(false);
			$Model->logModel->save($return);
		}
		
		return $return;
	}
}

?>
