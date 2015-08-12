<?php
/**
 *
 * PayPal Donation extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2015 Skouat
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace skouat\ppde\controller;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @property object                 ppde_operator Operator object. Inherit property statement from admin_main
 * @property \phpbb\log\log         log           The phpBB log system. Inherit property statement from admin_main
 * @property \phpbb\request\request request       Request object. Inherit property statement from admin_main
 * @property \phpbb\user            user          User object. Inherit property statement from admin_main
 */
class admin_donation_pages_controller extends admin_main implements admin_donation_pages_interface
{
	protected $container;
	protected $template;
	protected $phpbb_root_path;
	protected $php_ext;

	protected $lang_local_name;

	/**
	 * Constructor
	 *
	 * @param ContainerInterface                    $container                    Service container interface
	 * @param \phpbb\log\log                        $log                          The phpBB log system
	 * @param \skouat\ppde\operators\donation_pages $ppde_operator_donation_pages Operator object
	 * @param \phpbb\request\request                $request                      Request object
	 * @param \phpbb\template\template              $template                     Template object
	 * @param \phpbb\user                           $user                         User object
	 * @param string                                $phpbb_root_path              phpBB root path
	 * @param string                                $php_ext                      phpEx
	 *
	 * @access public
	 */
	public function __construct(ContainerInterface $container, \phpbb\log\log $log, \skouat\ppde\operators\donation_pages $ppde_operator_donation_pages, \phpbb\request\request $request, \phpbb\template\template $template, \phpbb\user $user, $phpbb_root_path, $php_ext)
	{
		$this->container = $container;
		$this->log = $log;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		parent::__construct(
			$ppde_operator_donation_pages,
			'PPDE_DP_LANG',
			'page',
			'donation_page'
		);
	}

	/**
	 * Display the pages
	 *
	 * @return null
	 * @access public
	 */
	public function display_donation_pages()
	{
		// Get list of available language packs
		$langs = $this->ppde_operator->get_languages();

		// Set output vars
		foreach ($langs as $lang => $entry)
		{
			$this->assign_langs_template_vars($entry);

			// Grab all the pages from the db
			$data_ary = $this->ppde_operator->get_pages_data($entry['id']);

			foreach ($data_ary as $data)
			{
				// Do not treat the item whether language identifier does not match
				if ($data['page_lang_id'] != $entry['id'])
				{
					continue;
				}

				$this->template->assign_block_vars('ppde_langs.dp_list', array(
					'DONATION_PAGE_TITLE' => $this->user->lang[strtoupper($data['page_title'])],
					'DONATION_PAGE_LANG'  => (string) $lang,

					'U_DELETE'            => $this->u_action . '&amp;action=delete&amp;' . $this->id_prefix . '_id=' . $data['page_id'],
					'U_EDIT'              => $this->u_action . '&amp;action=edit&amp;' . $this->id_prefix . '_id=' . $data['page_id'],
				));
			}
			unset($data_ary, $data);
		}
		unset($entry, $langs, $lang);

		// Set output vars for display in the template
		$this->template->assign_vars(array(
			'U_ACTION' => $this->u_action,
		));
	}

	/**
	 * Assign language template vars to a block vars
	 * $s_select is for build options select menu
	 *
	 * @param array   $lang
	 * @param bool    $s_select
	 * @param integer $current
	 *
	 * @return null
	 * @access private
	 */
	private function assign_langs_template_vars($lang, $s_select = false, $current = 0)
	{
		$this->template->assign_block_vars('ppde_langs', array('LANG_LOCAL_NAME' => $lang['name']));

		if ($s_select)
		{
			$this->template->assign_block_vars('ppde_langs', array(
				'VALUE'      => $lang['id'],
				'S_SELECTED' => ($lang['id'] == $current) ? true : false,
			));
		}
	}

	/**
	 * Add a donation page
	 *
	 * @return null
	 * @access public
	 */
	public function add_donation_page()
	{
		// Add form key
		add_form_key('add_edit_donation_page');

		// Initiate a page donation entity
		$entity = $this->container->get('skouat.ppde.entity.donation_pages');

		// Collect the form data
		$data = array(
			'page_title'   => $this->request->variable('page_title', ''),
			'page_lang_id' => $this->request->variable('lang_id', '', true),
			'page_content' => $this->request->variable('page_content', '', true),
			'bbcode'       => !$this->request->variable('disable_bbcode', false),
			'magic_url'    => !$this->request->variable('disable_magic_url', false),
			'smilies'      => !$this->request->variable('disable_smilies', false),
		);

		// Set template vars for language select menu
		$this->create_language_options($data['page_lang_id']);

		// Process the new page
		$this->add_edit_donation_page_data($entity, $data);

		// Set output vars for display in the template
		$this->template->assign_vars(array(
			'S_ADD_DONATION_PAGE' => true,

			'U_ADD_ACTION'        => $this->u_action . '&amp;action=add',
			'U_BACK'              => $this->u_action,
		));
	}

	/**
	 * Set template var options for language select menus
	 *
	 * @param integer $current ID of the language assigned to the donation page
	 *
	 * @return null
	 * @access protected
	 */
	protected function create_language_options($current)
	{
		// Grab all available language packs
		$langs = $this->ppde_operator->get_languages();

		// Set the options list template vars
		foreach ($langs as $lang)
		{
			$this->assign_langs_template_vars($lang, true, $current);
		}
	}

	/**
	 * Process donation pages data to be added or edited
	 *
	 * @param object $entity The donation pages entity object
	 * @param array  $data   The form data to be processed
	 *
	 * @return null
	 * @access private
	 */
	private function add_edit_donation_page_data($entity, $data)
	{
		// Get form's POST actions (submit or preview)
		$this->submit = $this->request->is_set_post('submit');
		$this->preview = $this->request->is_set_post('preview');

		// Load posting language file for the BBCode editor
		$this->user->add_lang('posting');

		// Create an array to collect errors that will be output to the user
		$errors = array();

		$message_parse_options = array_merge(
			$this->get_message_parse_options($entity, $data, 'bbcode'),
			$this->get_message_parse_options($entity, $data, 'magic_url'),
			$this->get_message_parse_options($entity, $data, 'smilies')
		);

		// Set the message parse options in the entity
		foreach ($message_parse_options as $function => $enabled)
		{
			try
			{
				call_user_func(array($entity, ($enabled ? 'message_enable_' : 'message_disable_') . $function));
			}
			catch (\skouat\ppde\exception\base $e)
			{
				// Catch exceptions and add them to errors array
				$errors[] = $e->get_message($this->user);
			}
		}

		unset($message_parse_options);

		// Set the donation page's data in the entity
		$item_fields = array(
			'lang_id' => $data['page_lang_id'],
			'name'    => $data['page_title'],
			'message' => $data['page_content'],
		);
		$errors = array_merge($errors, $this->set_entity_data($entity, $item_fields));

		// Check some settings before submitting data
		$errors = array_merge($errors,
			$this->is_invalid_form('add_edit_' . $this->module_name, $this->submit_or_preview($this->submit)),
			$this->is_empty_data($entity, 'name', '', $this->submit_or_preview($this->submit)),
			$this->is_empty_data($entity, 'lang_id', 0, $this->submit_or_preview($this->submit))
		);

		// Grab predefined template vars
		$vars = $entity->get_vars(true);
		// Assign variables in a template block vars
		$this->assign_preview_template_vars($entity, $errors);
		$this->assign_predefined_block_vars($vars);

		// Submit form data
		$this->submit_data($entity, $errors);

		// Set output vars for display in the template
		$this->template->assign_vars(array(
			'S_ERROR'                        => (sizeof($errors)) ? true : false,
			'ERROR_MSG'                      => (sizeof($errors)) ? implode('<br />', $errors) : '',

			'L_DONATION_PAGES_TITLE'         => $this->user->lang[strtoupper($entity->get_name())],
			'L_DONATION_PAGES_TITLE_EXPLAIN' => $this->user->lang[strtoupper($entity->get_name()) . '_EXPLAIN'],
			'DONATION_BODY'                  => $entity->get_message_for_edit(),

			'S_BBCODE_DISABLE_CHECKED'       => !$entity->message_bbcode_enabled(),
			'S_SMILIES_DISABLE_CHECKED'      => !$entity->message_smilies_enabled(),
			'S_MAGIC_URL_DISABLE_CHECKED'    => !$entity->message_magic_url_enabled(),

			'BBCODE_STATUS'                  => $this->user->lang('BBCODE_IS_ON', '<a href="' . append_sid("{$this->phpbb_root_path}faq.{$this->php_ext}", 'mode=bbcode') . '">', '</a>'),
			'SMILIES_STATUS'                 => $this->user->lang['SMILIES_ARE_ON'],
			'IMG_STATUS'                     => $this->user->lang['IMAGES_ARE_ON'],
			'FLASH_STATUS'                   => $this->user->lang['FLASH_IS_ON'],
			'URL_STATUS'                     => $this->user->lang['URL_IS_ON'],

			'S_BBCODE_ALLOWED'               => true,
			'S_SMILIES_ALLOWED'              => true,
			'S_BBCODE_IMG'                   => true,
			'S_BBCODE_FLASH'                 => true,
			'S_LINKS_ALLOWED'                => true,
			'S_HIDDEN_FIELDS'                => '<input type="hidden" name="page_title" value="' . $entity->get_name() . '" />',
		));

		// Assigning custom bbcodes
		include_once($this->phpbb_root_path . 'includes/functions_display.' . $this->php_ext);

		display_custom_bbcodes();
	}

	/**
	 * Get parse options of the message
	 *
	 * @param object $entity The donation pages entity object
	 * @param array  $data   The form data to be processed
	 * @param string $type
	 *
	 * @return array
	 * @access private
	 */
	private function get_message_parse_options($entity, $data, $type)
	{
		return array($type => $this->submit_or_preview($this->submit, $this->preview) ? $data[$type] : (bool) call_user_func(array($entity, 'message_' . $type . '_enabled')));
	}

	/**
	 * Assign vars to the template if preview is true.
	 *
	 * @param object $entity The donation pages entity object
	 * @param        $errors
	 *
	 * @access private
	 */
	private function assign_preview_template_vars($entity, $errors)
	{
		if ($this->preview && empty($errors))
		{
			// Set output vars for display in the template
			$this->template->assign_vars(array(
				'S_PPDE_DP_PREVIEW' => $this->preview,

				'PPDE_DP_PREVIEW'   => $entity->replace_template_vars($entity->get_message_for_display()),
			));
		}
	}

	/**
	 * Assign Predefined variables to a template block_vars
	 *
	 * @param array $vars
	 *
	 * @return null
	 * @access   private
	 */
	private function assign_predefined_block_vars($vars)
	{
		for ($i = 0, $size = sizeof($vars); $i < $size; $i++)
		{
			$this->template->assign_block_vars('dp_vars', array(
					'NAME'     => $vars[$i]['name'],
					'VARIABLE' => $vars[$i]['var'],
					'EXAMPLE'  => $vars[$i]['value'])
			);
		}
	}

	/**
	 *  Submit data to the database
	 *
	 * @param object $entity The donation pages entity object
	 * @param array  $errors
	 *
	 * @return null
	 * @access private
	 */
	private function submit_data($entity, array $errors)
	{
		if ($this->can_submit_data($errors))
		{
			$this->trigger_error_data_already_exists($entity);

			// Grab the local language name
			$this->get_lang_local_name($this->ppde_operator->get_languages($entity->get_lang_id()));

			$log_action = $this->add_edit_data($entity);
			// Log and show user confirmation of the saved item and provide link back to the previous page
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_' . $this->lang_key_prefix . strtoupper($log_action), time(), array($this->user->lang(strtoupper($entity->get_name())), $this->lang_local_name));
			trigger_error($this->user->lang($this->lang_key_prefix . strtoupper($log_action), $this->lang_local_name) . adm_back_link($this->u_action));
		}
	}

	/**
	 * Get Local lang name
	 *
	 * @param array $langs
	 *
	 * @return null
	 * @access private
	 */
	private function get_lang_local_name($langs)
	{
		foreach ($langs as $lang)
		{
			$this->lang_local_name = $lang['name'];
		}
	}

	/**
	 * Edit a donation page
	 *
	 * @param int $page_id Donation page identifier
	 *
	 * @return null
	 * @access public
	 */
	public function edit_donation_page($page_id)
	{
		// Add form key
		add_form_key('add_edit_donation_page');

		// Initiate a page donation entity
		$entity = $this->container->get('skouat.ppde.entity.donation_pages')->load($page_id);

		// Collect the form data
		$data = array(
			'page_id'      => (int) $page_id,
			'page_title'   => $this->request->variable('page_title', $entity->get_name(), false),
			'page_lang_id' => $this->request->variable('page_lang_id', $entity->get_lang_id()),
			'page_content' => $this->request->variable('page_content', $entity->get_message_for_edit(), true),
			'bbcode'       => !$this->request->variable('disable_bbcode', false),
			'magic_url'    => !$this->request->variable('disable_magic_url', false),
			'smilies'      => !$this->request->variable('disable_smilies', false),
		);

		// Set template vars for language select menu
		$this->create_language_options($data['page_lang_id']);

		// Process the new page
		$this->add_edit_donation_page_data($entity, $data);

		// Set output vars for display in the template
		$this->template->assign_vars(array(
			'S_EDIT_DONATION_PAGE' => true,

			'U_EDIT_ACTION'        => $this->u_action . '&amp;action=edit&amp;' . $this->id_prefix . '_id=' . $page_id,
			'U_BACK'               => $this->u_action,
		));
	}

	/**
	 * Delete a donation page
	 *
	 * @param int $page_id The donation page identifier to delete
	 *
	 * @return null
	 * @access public
	 */
	public function delete_donation_page($page_id)
	{
		// Initiate an entity and load data
		$entity = $this->container->get('skouat.ppde.entity.donation_pages');
		$entity->load($page_id);

		// Before deletion, grab the local language name
		$this->get_lang_local_name($this->ppde_operator->get_languages($entity->get_lang_id()));

		// Delete the donation page
		$this->ppde_operator->delete_page($page_id);

		// Log the action
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG' . $this->lang_key_prefix . 'DELETED', time(), array($this->user->lang(strtoupper($entity->get_name())), $this->lang_local_name));

		// If AJAX was used, show user a result message
		$this->ajax_delete_result_message();
	}
}
