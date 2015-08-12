<?php
/**
 *
 * PayPal Donation extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2015 Skouat
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace skouat\ppde\operators;

/**
 * @property  \phpbb\db\driver\driver_interface                         db        Database connection
 * @property  \Symfony\Component\DependencyInjection\ContainerInterface container Service container interface
 */
abstract class main
{
	/**
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct()
	{
	}

	/**
	 * Add a data
	 *
	 * @param object $entity Data entity with new data to insert
	 * @param string $run_before_insert
	 *
	 * @return mixed
	 * @access public
	 */
	public function add_data($entity, $run_before_insert = '')
	{
		// Insert the data to the database
		$entity->insert($run_before_insert);

		// Get the newly inserted identifier
		$id = $entity->get_id();

		// Reload the data to return a fresh currency entity
		return $entity->load($id);
	}

	/**
	 * Get data from the database
	 *
	 * @param string $sql
	 *
	 * @return array
	 * @access public
	 */
	public function get_data($sql)
	{
		$entities = array();
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			// Import each currency page row into an entity
			$entities[] = $this->container->get('skouat.ppde.entity.currency')->import($row);
		}
		$this->db->sql_freeresult($result);

		// Return all page entities
		return $entities;
	}
}
