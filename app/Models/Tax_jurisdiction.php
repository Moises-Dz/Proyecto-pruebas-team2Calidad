<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use stdClass;

/**
 * Tax Jurisdiction class
 */

class Tax_jurisdiction extends Model
{
	/**
	 *  Determines if it exists in the table
	 */
	public function exists(int $jurisdiction_id): bool
	{
		$builder = $this->db->table('tax_jurisdictions');
		$builder->where('jurisdiction_id', $jurisdiction_id);

		return ($builder->get()->getNumRows() == 1);	//TODO: ===
	}

	/**
	 *  Gets total of rows
	 */
	public function get_total_rows(): int
	{
		$builder = $this->db->table('tax_jurisdictions');
		$builder->where('deleted', 0);

		return $builder->countAllResults();
	}

	/***
	 * Gets information about the particular record
	 */
	public function get_info(int $jurisdiction_id): object
	{
		$builder = $this->db->table('tax_jurisdictions');
		$builder->where('jurisdiction_id', $jurisdiction_id);
		$builder->where('deleted', 0);
		$query = $builder->get();

		if($query->getNumRows() == 1)	//TODO: ===
		{
			return $query->getRow();
		}
		else	//TODO: this else is not needed.  Just put everything below it without an else.
		{
			//Get empty base parent object
			$tax_jurisdiction_obj = new stdClass();

			//Get all the fields from the table
			foreach($this->db->getFieldNames('tax_jurisdictions') as $field)
			{
				$tax_jurisdiction_obj->$field = '';
			}
			return $tax_jurisdiction_obj;
		}
	}

	/**
	 *  Returns all rows from the table
	 */
	public function get_all(int $rows = 0, int $limit_from = 0, bool $no_deleted = TRUE): ResultInterface
	{
		$builder = $this->db->table('tax_jurisdictions');

		if($no_deleted == TRUE)
		{
			$builder->where('deleted', 0);
		}

		$builder->orderBy('jurisdiction_name', 'asc');

		if($rows > 0)
		{
			$builder->limit($rows, $limit_from);
		}

		return $builder->get();
	}

	/**
	 *  Returns multiple rows
	 */
	public function get_multiple_info(array $jurisdiction_ids): ResultInterface
	{
		$builder = $this->db->table('tax_jurisdictions');
		$builder->whereIn('jurisdiction_id', $jurisdiction_ids);
		$builder->orderBy('jurisdiction_name', 'asc');

		return $builder->get();
	}

	/**
	 *  Inserts or updates a row
	 */
	public function save(array &$jurisdiction_data, bool $jurisdiction_id = FALSE): bool
	{
		$builder = $this->db->table('tax_jurisdictions');
		if(!$jurisdiction_id || !$this->exists($jurisdiction_id))
		{
			if($builder->insert($jurisdiction_data))	//TODO: Replace this with simply a return of the result of insert()... see update() below.
			{
				$jurisdiction_data['jurisdiction_id'] = $this->db->insertID();
				return TRUE;
			}

			return FALSE;
		}

		$builder->where('jurisdiction_id', $jurisdiction_id);

		return $builder->update($jurisdiction_data);
	}

	/**
	 * Saves changes to the tax jurisdictions table
	 */
	public function save_jurisdictions(array $array_save): bool
	{
		$this->db->transStart();

		$not_to_delete = [];

		foreach($array_save as $key => $value)
		{
			// save or update
			$tax_jurisdiction_data = [
				'jurisdiction_name' => $value['jurisdiction_name'],
				'tax_group' => $value['tax_group'],
				'tax_type' => $value['tax_type'],
				'reporting_authority' => $value['reporting_authority'],
				'tax_group_sequence' => $value['tax_group_sequence'],
				'cascade_sequence' => $value['cascade_sequence'],
				'deleted' => '0'];

			$this->save($tax_jurisdiction_data, $value['jurisdiction_id']);

			if($value['jurisdiction_id'] == -1)		//TODO: replace -1 with a constant. Also === ?.  Also replace this with ternary notation.
			{
				$not_to_delete[] = $tax_jurisdiction_data['jurisdiction_id'];
			}
			else
			{
				$not_to_delete[] = $value['jurisdiction_id'];
			}
		}

		// all entries not available in post will be deleted now
		$deleted_tax_jurisdictions = $this->get_all()->getResultArray();

		foreach($deleted_tax_jurisdictions as $key => $tax_jurisdiction_data)
		{
			if(!in_array($tax_jurisdiction_data['jurisdiction_id'], $not_to_delete))
			{
				$this->delete($tax_jurisdiction_data['jurisdiction_id']);
			}
		}

		$this->db->transComplete();
		return $this->db->transStatus();
	}

	/**
	 * Soft deletes a specific tax jurisdiction
	 */
	public function delete(int $jurisdiction_id = null, bool $purge = false): bool
	{
		$builder = $this->db->table('tax_jurisdictions');
		$builder->where('jurisdiction_id', $jurisdiction_id);

		return $builder->update(['deleted' => 1]);
	}

	/**
	 * Soft deletes a list of rows
	 */
	public function delete_list(array $jurisdiction_ids): bool
	{
		$builder = $this->db->table('tax_jurisdictions');
		$builder->whereIn('jurisdiction_id', $jurisdiction_ids);

		return $builder->update(['deleted' => 1]);
 	}

	/**
	 * Gets rows
	 */
	public function get_found_rows(string $search): ResultInterface
	{
		return $this->search($search, 0, 0, 'jurisdiction_name', 'asc', TRUE);
	}

	/**
	 *  Perform a search for a set of rows
	 */
	public function search(string $search, int $rows = 0, int $limit_from = 0, string $sort = 'jurisdiction_name', string $order = 'asc', bool $count_only = FALSE): ResultInterface
	{
		$builder = $this->db->table('tax_jurisdictions AS tax_jurisdictions');

		// get_found_rows case
		if($count_only == TRUE)	//TODO: Replace this with just count_only: `if($count_only)`
		{
			$builder->select('COUNT(tax_jurisdictions.jurisdiction_id) as count');
		}

		$builder->groupStart();
			$builder->like('jurisdiction_name', $search);
			$builder->orLike('reporting_authority', $search);
		$builder->groupEnd();
		$builder->where('deleted', 0);

		// get_found_rows case
		if($count_only == TRUE)	//TODO: ===
		{
			return $builder->get()->getRow()->count;
		}

		$builder->orderBy($sort, $order);

		if($rows > 0)
		{
			$builder->limit($rows, $limit_from);
		}

		return $builder->get();
	}

	public function get_empty_row(): array
	{
		return [
			'0' => [
				'jurisdiction_id' => -1,	//TODO: Replace -1 with a constant
				'jurisdiction_name' => '',
				'tax_group' => '',
				'tax_type' => '1',
				'reporting_authority' => '',
				'tax_group_sequence' => '',
				'cascade_sequence' => '',
				'deleted' => ''
			]
		];
	}
}
?>