<?php
/**
 *
 * PayPal Donation extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2015 Skouat
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * Special Thanks to the following individuals for their inspiration:
 *    David Lewis (Highway of Life) http://startrekguide.com
 *    Micah Carrick (email@micahcarrick.com) http://www.micahcarrick.com
 */

namespace skouat\ppde\controller;

use phpbb\config\config;
use phpbb\event\dispatcher_interface;
use phpbb\language\language;
use phpbb\path_helper;
use phpbb\request\request;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ipn_listener
{
	const ASCII_RANGE = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/** Setup the PayPal variables list with default values and conditions to check.
	 * Example:
	 *      array(
	 *          'name' => 'txn_id'
	 *          'default' => ''
	 *          'condition_check' => array('ascii' => true),
	 *      ),
	 *      array(
	 *          'name' => 'business'
	 *          'default' => ''
	 *          'condition_check' => array('length' => array('value' => 127, 'operator' => '<=')),
	 *      ),
	 * The index 'name' and 'default' are mandatory.
	 * The index 'condition_check' is optional
	 *
	 */
	private static $paypal_vars_table = array(
		array('name' => 'confirmed', 'default' => false),    // Used to check if the payment is confirmed
		array('name' => 'exchange_rate', 'default' => ''),   // Exchange rate used if a currency conversion occurred
		array('name' => 'mc_currency', 'default' => ''),     // Currency
		array('name' => 'mc_gross', 'default' => 0.00),      // Amt received (before fees)
		array('name' => 'mc_fee', 'default' => 0.00),        // Amt of fees
		array('name' => 'payment_status', 'default' => ''),  // Payment status. e.g.: 'Completed'
		array('name' => 'settle_amount', 'default' => 0.00), // Amt received after currency conversion (before fees)
		array('name' => 'settle_currency', 'default' => ''), // Currency of 'settle_amount'
		array('name' => 'test_ipn', 'default' => false),     // Used when transaction come from Sandbox platform
		array('name' => 'txn_type', 'default' => ''),        // Transaction type - Should be: 'web_accept'
		array(  // Primary merchant e-mail address
				'name'            => 'business',
				'default'         => '',
				'condition_check' => array('length' => array('value' => 127, 'operator' => '<=')),
				'force_settings'  => array('length' => 127, 'lowercase' => true),
		),
		array(  // Sender's First name
				'name'            => 'first_name',
				'default'         => array('', true),
				'condition_check' => array('length' => array('value' => 64, 'operator' => '<=')),
				'force_settings'  => array('length' => 64),
		),
		array(  // Equal to: $this->config['sitename']
				'name'            => 'item_name',
				'default'         => array('', true),
				'condition_check' => array('length' => array('value' => 127, 'operator' => '<=')),
				'force_settings'  => array('length' => 127),
		),
		array(  // Equal to: 'uid_' . $this->user->data['user_id'] . '_' . time()
				'name'            => 'item_number',
				'default'         => '',
				'condition_check' => array('length' => array('value' => 127, 'operator' => '<=')),
				'force_settings'  => array('length' => 127),
		),
		array(  // Sender's Last name
				'name'            => 'last_name',
				'default'         => array('', true),
				'condition_check' => array('length' => array('value' => 64, 'operator' => '<=')),
				'force_settings'  => array('length' => 64),
		),
		array(  // Memo entered by the donor
				'name'            => 'memo',
				'default'         => array('', true),
				'condition_check' => array('length' => array('value' => 255, 'operator' => '<=')),
				'force_settings'  => array('length' => 255),
		),
		array(  // The Parent transaction ID, in case of refund.
				'name'            => 'parent_txn_id',
				'default'         => '',
				'condition_check' => array('ascii' => true, 'length' => array('value' => 19, 'operator' => '<=')),
				'force_settings'  => array('length' => 19),
		),
		array(  // PayPal sender email address
				'name'            => 'payer_email',
				'default'         => '',
				'condition_check' => array('length' => array('value' => 127, 'operator' => '<=')),
				'force_settings'  => array('length' => 127),
		),
		array(  // PayPal sender ID
				'name'            => 'payer_id',
				'default'         => '',
				'condition_check' => array('ascii' => true, 'length' => array('value' => 13, 'operator' => '<=')),
				'force_settings'  => array('length' => 13),
		),
		array(  // PayPal sender status (verified or unverified)
				'name'            => 'payer_status',
				'default'         => 'unverified',
				'condition_check' => array('length' => array('value' => 13, 'operator' => '<=')),
				'force_settings'  => array('length' => 13),
		),
		array(  // Payment Date/Time in the format 'HH:MM:SS Mmm DD, YYYY PDT'
				'name'            => 'payment_date',
				'default'         => '',
				'condition_check' => array('length' => array('value' => 28, 'operator' => '<=')),
				'force_settings'  => array('length' => 28, 'strtotime' => true),
		),
		array(  // Payment type (echeck or instant)
				'name'            => 'payment_type',
				'default'         => '',
				'condition_check' => array('content' => array('echeck', 'instant')),
		),
		array(  // Secure Merchant Account ID
				'name'            => 'receiver_id',
				'default'         => '',
				'condition_check' => array('ascii' => true, 'length' => array('value' => 13, 'operator' => '<=')),
				'force_settings'  => array('length' => 13),
		),
		array(  // Merchant e-mail address
				'name'            => 'receiver_email',
				'default'         => '',
				'condition_check' => array('length' => array('value' => 127, 'operator' => '<=')),
				'force_settings'  => array('length' => 127, 'lowercase' => true),
		),
		array(  // Merchant country code
				'name'            => 'residence_country',
				'default'         => '',
				'condition_check' => array('length' => array('value' => 2, 'operator' => '==')),
				'force_settings'  => array('length' => 2),
		),
		array(  // Transaction ID
				'name'            => 'txn_id',
				'default'         => '',
				'condition_check' => array('empty' => false, 'ascii' => true),
		),
	);

	/**
	 * Services properties declaration
	 */
	protected $config;
	protected $container;
	protected $dispatcher;
	protected $language;
	protected $path_helper;
	protected $php_ext;
	protected $ppde_actions;
	protected $ppde_controller_main;
	protected $ppde_controller_transactions_admin;
	protected $ppde_ipn_log;
	protected $ppde_ipn_paypal;
	protected $request;
	protected $tasks_list;

	/**
	 * Args from PayPal notify return URL
	 *
	 * @var string
	 */
	private $args_return_uri = array();
	/**
	 * @var boolean
	 */
	private $donor_is_member = false;
	/**
	 * @var array|boolean
	 */
	private $payer_data;
	/**
	 * phpBB root path
	 *
	 * @var string
	 */
	private $root_path;
	/**
	 * Data from PayPal transaction
	 *
	 * @var array
	 */
	private $transaction_data = array();
	/**
	 * PayPal URL
	 * Could be Sandbox URL ou normal PayPal URL.
	 *
	 * @var string
	 */
	private $u_paypal = '';
	/**
	 * Transaction status
	 *
	 * @var boolean
	 */
	private $verified = false;
	/**
	 * Message to parse to log_error()
	 *
	 * @var boolean
	 */
	private $error_message;

	/**
	 * Constructor
	 *
	 * @param config                         $config                             Config object
	 * @param ContainerInterface             $container                          Service container interface
	 * @param language                       $language                           Language user object
	 * @param path_helper                    $path_helper                        Path helper object
	 * @param core_actions                   $ppde_actions
	 * @param main_controller                $ppde_controller_main               Main controller object
	 * @param admin_transactions_controller  $ppde_controller_transactions_admin Admin transactions controller object
	 * @param ipn_log                        $ppde_ipn_log                       IPN log
	 * @param ipn_paypal                     $ppde_ipn_paypal                    IPN PayPal
	 * @param request                        $request                            Request object
	 * @param dispatcher_interface           $dispatcher                         Dispatcher object
	 * @param string                         $php_ext                            phpEx
	 *
	 * @access public
	 */
	public function __construct(config $config, ContainerInterface $container, language $language, path_helper $path_helper, core_actions $ppde_actions, main_controller $ppde_controller_main, admin_transactions_controller $ppde_controller_transactions_admin, ipn_log $ppde_ipn_log, ipn_paypal $ppde_ipn_paypal, request $request, dispatcher_interface $dispatcher, $php_ext)
	{
		$this->config = $config;
		$this->container = $container;
		$this->dispatcher = $dispatcher;
		$this->language = $language;
		$this->path_helper = $path_helper;
		$this->ppde_actions = $ppde_actions;
		$this->ppde_controller_main = $ppde_controller_main;
		$this->ppde_controller_transactions_admin = $ppde_controller_transactions_admin;
		$this->ppde_ipn_log = $ppde_ipn_log;
		$this->ppde_ipn_paypal = $ppde_ipn_paypal;
		$this->request = $request;
		$this->php_ext = $php_ext;

		$this->root_path = $this->path_helper->get_phpbb_root_path();
	}

	public function handle()
	{
		$this->language->add_lang('donate', 'skouat/ppde');

		// Set IPN logging
		$this->ppde_ipn_log->set_use_log_error((bool) $this->config['ppde_ipn_logging']);

		// Determine which remote connection to use to contact PayPal
		$this->ppde_ipn_paypal->is_remote_detected();

		// If no connection detected, disable IPN, log error and exit code execution
		if (!$this->ppde_controller_main->is_ipn_requirement_satisfied())
		{
			$this->config->set('ppde_ipn_enable', false);
			$this->ppde_ipn_log->log_error($this->language->lang('REQUIREMENT_NOT_SATISFIED'), true, true, E_USER_WARNING);
		}

		// Logs in the DB, PayPal verified transactions
		if ($this->validate_transaction())
		{
			$this->log_to_db();
		}

		// Do actions only if checks are validated.
		if ($this->validate_actions())
		{
			$this->do_actions();
		}

		// We stop the execution of the code because nothing need to be returned to phpBB.
		// And PayPal need it to terminate properly the IPN process.
		garbage_collection();
		exit_handler();
	}

	/**
	 * Post Data back to PayPal to validate the authenticity of the transaction.
	 *
	 * @return bool
	 * @access private
	 */
	private function validate_transaction()
	{
		// Request and populate $this->transaction_data
		array_map(array($this, 'get_post_data'), self::$paypal_vars_table);

		// Additional checks
		$this->check_account_id();

		if (!empty($this->error_message))
		{
			// If data doesn't meet the requirement, we log in file (if enabled).
			$this->ppde_ipn_log->log_error($this->language->lang('INVALID_TXN') . $this->error_message, true, false, E_USER_NOTICE, $this->get_postback_args());
			// We store error message in transaction data for later use.
			$this->transaction_data['txn_errors'] = $this->error_message;
		}

		$decode_ary = array('receiver_email', 'payer_email', 'payment_date', 'business', 'memo');
		foreach ($decode_ary as $key)
		{
			$this->transaction_data[$key] = urldecode($this->transaction_data[$key]);
		}

		// Get all variables from PayPal to build return URI
		$this->set_args_return_uri();

		// Get PayPal or Sandbox URI
		$this->u_paypal = $this->ppde_controller_main->get_paypal_uri((bool) $this->transaction_data['test_ipn']);

		// Initiate PayPal connection
		$this->ppde_ipn_paypal->set_u_paypal($this->u_paypal);
		$this->ppde_ipn_paypal->initiate_paypal_connection($this->args_return_uri, $this->transaction_data);

		if ($this->ppde_ipn_paypal->check_response_status())
		{
			$args = array_merge(array('response_status' => $this->ppde_ipn_paypal->get_response_status()), $this->get_postback_args());
			$this->ppde_ipn_log->log_error($this->language->lang('INVALID_RESPONSE_STATUS'), $this->ppde_ipn_log->is_use_log_error(), true, E_USER_NOTICE, $args);
		}

		return $this->check_response();
	}

	/**
	 * Check if Merchant ID set on the extension match with the ID stored in the transaction.
	 *
	 * @return void
	 * @access private
	 */
	private function check_account_id()
	{
		$account_value = $this->ipn_use_sandbox() ? $this->config['ppde_sandbox_address'] : $this->config['ppde_account_id'];
		if ($account_value != $this->transaction_data['receiver_id'] && $account_value != $this->transaction_data['receiver_email'])
		{
			$this->error_message .= '<br>' . $this->language->lang('INVALID_TXN_ACCOUNT_ID');
		}
	}

	/**
	 * Check if Sandbox is enabled based on config value
	 *
	 * @return bool
	 * @access private
	 */
	private function ipn_use_sandbox()
	{
		return $this->ppde_controller_main->use_ipn() && !empty($this->config['ppde_sandbox_enable']);
	}

	/**
	 * Get all args and build the return URI
	 *
	 * @return void
	 * @access private
	 */
	private function set_args_return_uri()
	{
		$values = array();
		// Add the cmd=_notify-validate for PayPal
		$this->args_return_uri = 'cmd=_notify-validate';

		// Grab the post data form and set in an array to be used in the URI to PayPal
		foreach ($this->get_postback_args() as $key => $value)
		{
			$encoded = urlencode(htmlspecialchars_decode($value));
			$values[] = $key . '=' . $encoded;
		}

		// Implode the array into a string URI
		$this->args_return_uri .= '&' . implode('&', $values);
	}

	/**
	 * Get $_POST content as is. This is used to Postback args to PayPal or for tracking errors.
	 *
	 * @return array
	 */
	private function get_postback_args()
	{
		$data_ary = array();

		foreach ($this->request->variable_names(\phpbb\request\request_interface::POST) as $key)
		{
			$data_ary[$key] = $this->request->variable($key, '', true);
		}

		return $data_ary;
	}

	/**
	 * Check response returned by PayPal et log errors if there is no valid response
	 * Set true if response is 'VERIFIED'. In other case set to false and log errors
	 *
	 * @return bool $this->verified
	 * @access private
	 */
	private function check_response()
	{
		// Prepare data to include in report
		$this->ppde_ipn_log->set_report_data($this->u_paypal, $this->ppde_ipn_paypal->get_remote_used(), $this->ppde_ipn_paypal->get_report_response(), $this->ppde_ipn_paypal->get_response_status(), $this->transaction_data);

		if ($this->txn_is_verified())
		{
			$this->verified = $this->transaction_data['confirmed'] = true;
			$this->ppde_ipn_log->log_error("DEBUG VERIFIED:\n" . $this->ppde_ipn_log->get_text_report(), $this->ppde_ipn_log->is_use_log_error());
		}
		else if ($this->txn_is_invalid())
		{
			$this->verified = $this->transaction_data['confirmed'] = false;
			$this->ppde_ipn_log->log_error("DEBUG INVALID:\n" . $this->ppde_ipn_log->get_text_report(), $this->ppde_ipn_log->is_use_log_error(), true);
		}
		else
		{
			$this->verified = $this->transaction_data['confirmed'] = false;
			$this->ppde_ipn_log->log_error("DEBUG OTHER:\n" . $this->ppde_ipn_log->get_text_report(), $this->ppde_ipn_log->is_use_log_error());
			$this->ppde_ipn_log->log_error($this->language->lang('UNEXPECTED_RESPONSE'), $this->ppde_ipn_log->is_use_log_error(), true);
		}

		return (bool) $this->verified;
	}

	/**
	 * Check if transaction is VERIFIED
	 *
	 * @return bool
	 * @access private
	 */
	private function txn_is_verified()
	{
		return $this->ppde_ipn_paypal->is_curl_strcmp('VERIFIED');
	}

	/**
	 * Check if transaction is INVALID
	 *
	 * @return bool
	 * @access private
	 */
	private function txn_is_invalid()
	{
		return $this->ppde_ipn_paypal->is_curl_strcmp('INVALID');
	}

	/**
	 * Log the transaction to the database
	 *
	 * @access private
	 */
	private function log_to_db()
	{
		// Initiate a transaction log entity
		/** @type \skouat\ppde\entity\transactions $entity */
		$entity = $this->container->get('skouat.ppde.entity.transactions');

		// The item number contains the user_id
		$this->extract_item_number_data();
		$this->validate_user_id();

		// Set username in extra_data property in $entity
		$user_ary = $this->ppde_controller_transactions_admin->ppde_operator->query_donor_user_data('user', $this->transaction_data['user_id']);
		$entity->set_username($user_ary['username']);

		// List the data to be thrown into the database
		$data = $this->build_data_ary();

		// Load data in the entity
		$this->ppde_controller_transactions_admin->set_entity_data($entity, $data);
		$entity->set_id($entity->transaction_exists());

		// Add or edit transaction data
		$this->ppde_controller_transactions_admin->add_edit_data($entity);
	}

	/**
	 * Retrieve user_id from item_number args
	 *
	 * @return void
	 * @access private
	 */
	private function extract_item_number_data()
	{
		list($this->transaction_data['user_id']) = explode('_', substr($this->transaction_data['item_number'], 4), -1);
	}

	/**
	 * Avoid the user_id to be set to 0
	 *
	 * @return void
	 * @access private
	 */
	private function validate_user_id()
	{
		if (empty($this->transaction_data['user_id']) || !is_numeric($this->transaction_data['user_id']))
		{
			$this->transaction_data['user_id'] = ANONYMOUS;
		}
	}

	/**
	 * Prepare data array() before send it to $entity
	 *
	 * @return array
	 */
	private function build_data_ary()
	{
		return array(
			'business'          => $this->transaction_data['business'],
			'confirmed'         => (bool) $this->transaction_data['confirmed'],
			'exchange_rate'     => $this->transaction_data['exchange_rate'],
			'first_name'        => $this->transaction_data['first_name'],
			'item_name'         => $this->transaction_data['item_name'],
			'item_number'       => $this->transaction_data['item_number'],
			'last_name'         => $this->transaction_data['last_name'],
			'mc_currency'       => $this->transaction_data['mc_currency'],
			'mc_gross'          => floatval($this->transaction_data['mc_gross']),
			'mc_fee'            => floatval($this->transaction_data['mc_fee']),
			'net_amount'        => $this->ppde_actions->net_amount($this->transaction_data['mc_gross'], $this->transaction_data['mc_fee']),
			'parent_txn_id'     => $this->transaction_data['parent_txn_id'],
			'payer_email'       => $this->transaction_data['payer_email'],
			'payer_id'          => $this->transaction_data['payer_id'],
			'payer_status'      => $this->transaction_data['payer_status'],
			'payment_date'      => $this->transaction_data['payment_date'],
			'payment_status'    => $this->transaction_data['payment_status'],
			'payment_type'      => $this->transaction_data['payment_type'],
			'memo'              => $this->transaction_data['memo'],
			'receiver_id'       => $this->transaction_data['receiver_id'],
			'receiver_email'    => $this->transaction_data['receiver_email'],
			'residence_country' => $this->transaction_data['residence_country'],
			'settle_amount'     => floatval($this->transaction_data['settle_amount']),
			'settle_currency'   => $this->transaction_data['settle_currency'],
			'test_ipn'          => (bool) $this->transaction_data['test_ipn'],
			'txn_errors'        => $this->transaction_data['txn_errors'],
			'txn_id'            => $this->transaction_data['txn_id'],
			'txn_type'          => $this->transaction_data['txn_type'],
			'user_id'           => (int) $this->transaction_data['user_id'],
		);
	}

	/**
	 * Validates actions if the transaction is verified
	 *
	 * @return bool
	 * @access private
	 */

	private function validate_actions()
	{
		if (!$this->verified)
		{
			return false;
		}

		$this->tasks_list['payment_completed'] = $validate[] = $this->payment_status_is_completed();
		$this->tasks_list['donor_is_member'] = $this->donor_is_member();
		$this->tasks_list['txn_errors'] = !empty($this->transaction_data['txn_errors']) && empty($this->transaction_data['txn_errors_approved']) ? true : false;
		$this->tasks_list['is_not_ipn_test'] = !$this->transaction_data['test_ipn'];

		return array_product($validate);
	}

	/**
	 * Do actions for transactions
	 *
	 * @return void
	 * @access private
	 */
	private function do_actions()
	{
		if ($this->tasks_list['payment_completed'])
		{
			$transaction_data = $this->transaction_data;

			/**
			 * Event that is triggered when a transaction has been successfully completed
			 *
			 * @event skouat.ppde.do_actions_completed_before
			 * @var array    transaction_data    Array containing transaction data
			 * @since 1.0.3
			 */
			$vars = array(
				'transaction_data',
			);
			extract($this->dispatcher->trigger_event('skouat.ppde.do_actions_completed_before', compact($vars)));

			$this->transaction_data = $transaction_data;
			unset($transaction_data);

			// Do actions whether the transaction is real or a test.
			$this->ppde_actions->set_transaction_data($this->transaction_data);
			$this->ppde_controller_transactions_admin->update_overview_stats((bool) $this->transaction_data['test_ipn']);
		}

		if ($this->tasks_list['txn_errors'])
		{
			$this->ppde_actions->notification->notify_donation_errors();
			return;
		}

		if ($this->tasks_list['is_not_ipn_test'])
		{
			$this->ppde_actions->update_raised_amount($this->transaction_data);
			$this->ppde_actions->notification->notify_admin_donation_received();
		}

		if ($this->tasks_list['donor_is_member'])
		{
			$this->update_donor_stats();
			$this->donors_group_user_add();
			$this->ppde_actions->notification->notify_donor_donation_received();
		}
	}

	/**
	 * Checks if payment_status is completed
	 *
	 * @return bool
	 * @access private
	 */
	private function payment_status_is_completed()
	{
		return $this->transaction_data['payment_status'] === 'Completed';
	}

	/**
	 * Returns if donor is member
	 *
	 * @return bool
	 * @access private
	 */
	private function donor_is_member()
	{
		$this->is_donor_is_member();
		return $this->donor_is_member && !empty($this->payer_data);
	}

	/**
	 * Checks if the donor is a member then gets payer_data values
	 *
	 * @return void
	 * @access private
	 */

	private function is_donor_is_member()
	{
		$anonymous_user = false;

		// If the user_id is not anonymous
		if ($this->transaction_data['user_id'] != ANONYMOUS)
		{
			$this->donor_is_member = $this->check_donors_status('user', $this->transaction_data['user_id']);

			if (!$this->donor_is_member)
			{
				// No results, therefore the user is anonymous...
				$anonymous_user = true;
			}
		}
		else
		{
			// The user is anonymous by default
			$anonymous_user = true;
		}

		if ($anonymous_user)
		{
			// If the user is anonymous, check their PayPal email address with all known email hashes
			// to determine if the user exists in the database with that email
			$this->donor_is_member = $this->check_donors_status('email', $this->transaction_data['payer_email']);
		}
	}

	/**
	 * Gets donor informations (user id, username, amount donated) and returns if exists
	 *
	 * @param string     $type Allowed value : 'user' or 'email'
	 * @param string|int $args If $type is set to 'user', $args must be a user id.
	 *                         If $type is set to 'email', $args must be an email address
	 *
	 * @return bool
	 * @access private
	 */
	private function check_donors_status($type, $args)
	{
		$this->payer_data = $this->ppde_controller_transactions_admin->ppde_operator->query_donor_user_data($type, $args);

		return !empty($this->payer_data);
	}

	private function update_donor_stats()
	{
		if ($this->donor_is_member)
		{
			$this->ppde_controller_transactions_admin->update_user_stats((int) $this->payer_data['user_id'], (float) $this->payer_data['user_ppde_donated_amount'] + (float) $this->ppde_actions->net_amount($this->transaction_data['mc_gross'], $this->transaction_data['mc_fee']));
		}
	}

	/**
	 * Add donor to the donors group
	 *
	 * @return void
	 * @access private
	 */
	private function donors_group_user_add()
	{
		// We add the user to the donors group
		$can_use_autogroup = $this->can_use_autogroup();
		$group_id = (int) $this->config['ppde_ipn_group_id'];
		$payer_id = (int) $this->payer_data['user_id'];
		$payer_username = $this->payer_data['username'];
		$default_group = $this->config['ppde_ipn_group_as_default'];

		/**
		 * Event to modify data before a user is added to the donors group
		 *
		 * @event skouat.ppde.donors_group_user_add_before
		 * @var bool    can_use_autogroup   Whether or not to add the user to the group
		 * @var int     group_id            The ID of the group to which the user will be added
		 * @var int     payer_id            The ID of the user who will we added to the group
		 * @var string  payer_username      The user name
		 * @var bool    default_group       Whether or not the group should be made default for the user
		 * @since 1.0.3
		 */
		$vars = array(
			'can_use_autogroup',
			'group_id',
			'payer_id',
			'payer_username',
			'default_group',
		);
		extract($this->dispatcher->trigger_event('skouat.ppde.donors_group_user_add_before', compact($vars)));

		if ($can_use_autogroup)
		{
			if (!function_exists('group_user_add'))
			{
				include($this->root_path . 'includes/functions_user.' . $this->php_ext);
			}

			// Adds the user to the donors group and set as default.
			group_user_add($group_id, array($payer_id), array($payer_username), get_group_name($group_id), $default_group);
		}
	}

	/**
	 * Checks if all required settings are meet for adding the donor to the group of donors
	 *
	 * @return bool
	 * @access private
	 */
	private function can_use_autogroup()
	{
		return
			$this->autogroup_is_enabled() &&
			$this->donor_is_member &&
			$this->payment_status_is_completed() &&
			$this->minimum_donation_raised();
	}

	/**
	 * Checks if Autogroup could be used
	 *
	 * @return bool
	 * @access private
	 */
	private function autogroup_is_enabled()
	{
		return $this->verified && $this->config['ppde_ipn_enable'] && $this->config['ppde_ipn_autogroup_enable'];
	}

	/**
	 * @return bool
	 */
	private function minimum_donation_raised()
	{
		return (float) $this->payer_data['user_ppde_donated_amount'] >= (float) $this->config['ppde_ipn_min_before_group'] ? true : true;
	}

	/**
	 * Request predefined variable from super global, then fill in the $this->transaction_data array
	 *
	 * @param array $data_ary List of data to request
	 *
	 * @return void
	 */
	private function get_post_data($data_ary = array())
	{
		// Request variables
		if (is_array($data_ary['default']))
		{
			$data_ary['value'] = $this->request->variable($data_ary['name'], (string) $data_ary['default'][0], (bool) $data_ary['default'][1]);
		}
		else
		{
			$data_ary['value'] = $this->request->variable($data_ary['name'], $data_ary['default']);
		}

		// Assign variables to $this->transaction_data
		$this->check_post_data($data_ary);
		$this->transaction_data[$data_ary['name']] = $this->set_post_data($data_ary);
	}

	/**
	 * Set PayPal Postdata.
	 *
	 * @param $data_ary
	 * @return array|string
	 */
	private function set_post_data($data_ary)
	{
		$value = $data_ary['value'];

		// Set all conditions declared for this post_data
		if (isset($data_ary['force_settings']))
		{
			$value = $this->set_post_data_func($data_ary);
		}

		return $value;
	}

	/**
	 * Check if some settings are valid.
	 *
	 * @param array $data_ary
	 *
	 * @return bool
	 * @access private
	 */
	private function check_post_data($data_ary = array())
	{
		$check = array();

		// Check all conditions declared for this post_data
		if (isset($data_ary['condition_check']))
		{
			$check = array_merge($check, $this->call_post_data_func($data_ary));
		}

		return (bool) array_product($check);
	}

	/**
	 * Check requirements for data value.
	 *
	 * @param array $data_ary
	 *
	 * @access public
	 * @return array
	 */
	public function call_post_data_func($data_ary)
	{
		$check = array();

		foreach ($data_ary['condition_check'] as $control_point => $params)
		{
			// Calling the check_post_data_function
			if (call_user_func_array(array($this, 'check_post_data_' . $control_point), array($data_ary['value'], $params)))
			{
				$check[] = true;
			}
			else
			{
				$this->error_message .= '<br>' . $this->language->lang('INVALID_TXN_' . strtoupper($control_point), $data_ary['name']);
				$check[] = false;
			}
		}
		unset($data_ary, $control_point, $params);

		return $check;
	}

	/**
	 * Check requirements for data value.
	 *
	 * @param array $data_ary
	 *
	 * @access public
	 * @return mixed
	 */
	public function set_post_data_func($data_ary)
	{
		$value = $data_ary['value'];

		foreach ($data_ary['force_settings'] as $control_point => $params)
		{
			// Calling the set_post_data_function
			$value = call_user_func_array(array($this, 'set_post_data_' . $control_point), array($data_ary['value'], $params));
		}
		unset($data_ary, $control_point, $params);

		return $value;
	}

	/**
	 * Check Post data length.
	 * Called by $this->check_post_data() method
	 *
	 * @param string $value
	 * @param array  $statement
	 *
	 * @return bool
	 * @access private
	 */
	private function check_post_data_length($value, $statement)
	{
		return $this->ppde_controller_main->compare(strlen($value), $statement['value'], $statement['operator']);
	}

	/**
	 * Check if parsed value contains only ASCII chars.
	 * Return false if it contains non ASCII chars.
	 *
	 * @param $value
	 *
	 * @return bool
	 * @access private
	 */
	private function check_post_data_ascii($value)
	{
		// We ensure that the value contains only ASCII chars...
		$pos = strspn($value, self::ASCII_RANGE);
		$len = strlen($value);

		return $pos != $len ? false : true;
	}

	/**
	 * Check Post data content based on an array list.
	 * Called by $this->check_post_data() method
	 *
	 * @param string $value
	 * @param array  $content_ary
	 *
	 * @return bool
	 * @access private
	 */
	private function check_post_data_content($value, $content_ary)
	{
		return in_array($value, $content_ary) ? true : false;
	}

	/**
	 * Check if Post data is empty.
	 * Called by $this->check_post_data() method
	 *
	 * @param string $value
	 *
	 * @return bool
	 * @access private
	 */
	private function check_post_data_empty($value)
	{
		return empty($value) ? false : true;
	}

	/**
	 * Set Post data length.
	 * Called by $this->set_post_data() method
	 *
	 * @param string  $value
	 * @param integer $length
	 *
	 * @return string
	 * @access private
	 */
	private function set_post_data_length($value, $length)
	{
		return substr($value, 0, (int) $length);
	}

	/**
	 * Set Post data to lowercase.
	 * Called by $this->set_post_data() method
	 *
	 * @param string $value
	 * @param bool   $force
	 *
	 * @return string
	 * @access private
	 */
	private function set_post_data_lowercase($value, $force = false)
	{
		return $force ? strtolower($value) : $value;
	}

	/**
	 * Set Post data to date/time format.
	 * Called by $this->set_post_data() method
	 *
	 * @param string $value
	 * @param bool   $force
	 *
	 * @return string
	 * @access private
	 */
	private function set_post_data_strtotime($value, $force = false)
	{
		return $force ? strtotime($value) : $value;
	}
}
