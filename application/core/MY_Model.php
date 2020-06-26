<?php

/**
 * A base model with a series of CRUD functions (powered by CI's query builder),
 * validation-in-model support, event callbacks and more.
 *
 * @link http://github.com/jamierumbelow/codeigniter-base-model
 * @copyright Copyright (c) 2012, Jamie Rumbelow <http://jamierumbelow.net>
 * 
 * 
 */
class MY_Model extends CI_Model {
    /* --------------------------------------------------------------
     * VARIABLES
     * ------------------------------------------------------------ */

    /**
     * This model's default database table. Automatically
     * guessed by pluralising the model name.
     */
    protected $_table;

    /**
     * The database connection object. Will be set to the default
     * connection. This allows individual models to use different DBs
     * without overwriting CI's global $this->db connection.
     */
    public $_database;

    /**
     * This model's default primary key or unique identifier.
     * Used by the get(), update() and delete() functions.
     */
    protected $primary_key = 'id';

    /**
     * Support for soft deletes and this model's 'deleted' key
     */
    protected $soft_delete = FALSE;
    protected $soft_delete_key = 'deleted';
    protected $_temporary_with_deleted = FALSE;
    protected $_temporary_only_deleted = FALSE;

    /**
     * The various callbacks available to the model. Each are
     * simple lists of method names (methods will be run on $this).
     */
    protected $before_insert = array();
    protected $after_insert = array();
    protected $before_update = array();
    protected $after_update = array();
    protected $before_get = array();
    protected $after_get = array();
    protected $before_delete = array();
    protected $after_delete = array();
    protected $callback_parameters = array();
    protected $before_table_create = array();
    protected $after_table_create = array();

    /**
     * Protected, non-modifiable attributes
     */
    protected $protected_attributes = array();

    /**
     * Relationship arrays. Use flat strings for defaults or string
     * => array to customise the class name and primary key
     */
    protected $belongs_to = array();
    protected $has_many = array();
    protected $_with = array();

    /**
     * An array of validation rules. This needs to be the same format
     * as validation rules passed to the Form_validation library.
     */
    protected $validate = array();
    protected $index_keys = array();

    /**
     * Optionally skip the validation. Used in conjunction with
     * skip_validation() to skip data validation for any future calls.
     */
    protected $skip_validation = FALSE;

    /**
     * By default we return our results as objects. If we need to override
     * this, we can, or, we could use the `as_array()` and `as_object()` scopes.
     */
    protected $return_type = 'object';
    protected $_temporary_return_type = NULL;

    /* --------------------------------------------------------------
     * SSI CONFIGUARTION
     * ------------------------------------------------------------ */
    protected $ssi_limit = 10;
    protected $ssi_ofset = 0;
    protected $columns = 0;
    protected $dirTableDataIndexes;
    protected $dirTableDataFields;

    /* --------------------------------------------------------------
     * GENERIC METHODS
     * ------------------------------------------------------------ */

    /**
     * Initialise the model, tie into the CodeIgniter superobject and
     * try our best to guess the table name.
     */
    public function __construct() {
        parent::__construct();
		$this->load->dbforge();

        $this->load->helper('inflector');

        $this->_fetch_table();

        $this->_database = $this->db;

        array_unshift($this->before_insert, 'protect_attributes');
        array_unshift($this->before_update, 'protect_attributes');

        $this->_temporary_return_type = $this->return_type;
		$this->dirTableDataIndexes = FCPATH.'data/tables/'.$this->_table.'/indexes/';
		$this->dirTableDataFields = FCPATH.'data/tables/'.$this->_table.'/fields/';

        $this->table_create();
        // $this->verifyIndexTable();
		// if (!file_exists($this->dirTableDataFields)) {
		// $this->getFieldsData();	
		// }
		
    }

    /* --------------------------------------------------------------
     * CRUD INTERFACE
     * ------------------------------------------------------------ */

    /**
     * Fetch a single record based on the primary key. Returns an object.
     */
    public function get($primary_value) {
		$data[$this->primary_key] = $primary_value;
        return $this->get_by($data);
    }

    /**
     * Fetch a single record based on an arbitrary WHERE call. Can be
     * any valid value to $this->_database->where().
     */
    public function get_by() {
        $where = func_get_args();

        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE) {
            $this->_database->where($this->soft_delete_key, (bool) $this->_temporary_only_deleted);
        }
		

        $this->_set_where($where);

        $this->trigger('before_get');

        $row = $this->_database->get($this->_table)
                ->{$this->_return_type()}();
        $this->_temporary_return_type = $this->return_type;

        $row = $this->trigger('after_get', $row);

        $this->_with = array();
        return $row;
    }

    /**
     * Fetch an array of records based on an array of primary values.
     */
    public function get_many($values) {
        $this->_database->where_in($this->primary_key, $values);

        return $this->get_all();
    }

    /**
     * Fetch an array of records based on an arbitrary WHERE call.
     */
    public function get_many_by() {
        $where = func_get_args();

        $this->_set_where($where);

        return $this->get_all();
    }

    /**
     * Fetch all the records in the table. Can be used as a generic call
     * to $this->_database->get() with scoped methods.
     */
    public function get_all() {
        $this->trigger('before_get');

        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE) {
            $this->_database->where($this->soft_delete_key, (bool) $this->_temporary_only_deleted);
        }

        // $this->config_ssi($this->columns);

        $result = $this->_database->get($this->_table)
                ->{$this->_return_type(1)}();
        $this->_temporary_return_type = $this->return_type;

        foreach ($result as $key => &$row) {
            $row = $this->trigger('after_get', $row, ($key == count($result) - 1));
        }

        $this->_with = array();
        return $result;
    }

    /**
     * Insert a new row into the table. $data should be an associative array
     * of data to be inserted. Returns newly created ID.
     */
    public function insert($data, $skip_validation = FALSE) {
        if ($skip_validation === FALSE) {
            $data = $this->validate($data);
        }

        if ($data !== FALSE) {
            if (!isset($data['created_user_id'])) {
                $data['created_user_id'] = '0e51f946-8555-47bf-9b33-adb8ede90459';
            }
            if (!isset($data['modified_user_id'])) {
                $data['modified_user_id'] = '0e51f946-8555-47bf-9b33-adb8ede90459';
            }
            if (!isset($data['assigned_user_id'])) {
                $data['assigned_user_id'] = '0e51f946-8555-47bf-9b33-adb8ede90459';
            }
            if (empty($data['created_user_id'])) {
                $data['created_user_id'] = '0e51f946-8555-47bf-9b33-adb8ede90459';
            }
            if (empty($data['modified_user_id'])) {
                $data['modified_user_id'] = '0e51f946-8555-47bf-9b33-adb8ede90459';
            }
            if (empty($data['assigned_user_id'])) {
                $data['assigned_user_id'] = '0e51f946-8555-47bf-9b33-adb8ede90459';
            }

            $data = $this->trigger('before_insert', $data);
			// print_r($data);
            $this->_database->insert($this->_table, $data);
            $insert_id = $data[$this->primary_key];

            $this->trigger('after_insert', $insert_id);

            return $insert_id;
        } else {
            return FALSE;
        }
    }

    /**
     * Insert multiple rows into the table. Returns an array of multiple IDs.
     */
    public function insert_many($data, $skip_validation = FALSE) {
        $ids = array();

        foreach ($data as $key => $row) {
            $ids[] = $this->insert($row, $skip_validation, ($key == count($data) - 1));
        }

        return $ids;
    }

    /**
     * Updated a record based on the primary value.
     */
    public function update($primary_value, $data, $skip_validation = FALSE) {
        $data = $this->trigger('before_update', $data);

        if ($skip_validation === FALSE) {
            $data = $this->validate($data);
        }

        if ($data !== FALSE) {
            $result = $this->_database->where($this->primary_key, $primary_value)
                    ->set($data)
                    ->update($this->_table);

            $this->trigger('after_update', array($data, $result));

            return $result;
        } else {
            return FALSE;
        }
    }

    /**
     * Update many records, based on an array of primary values.
     */
    public function update_many($primary_values, $data, $skip_validation = FALSE) {
        $data = $this->trigger('before_update', $data);

        if ($skip_validation === FALSE) {
            $data = $this->validate($data);
        }

        if ($data !== FALSE) {
            $result = $this->_database->where_in($this->primary_key, $primary_values)
                    ->set($data)
                    ->update($this->_table);

            $this->trigger('after_update', array($data, $result));

            return $result;
        } else {
            return FALSE;
        }
    }

    /**
     * Updated a record based on an arbitrary WHERE clause.
     */
    public function update_by() {
        $args = func_get_args();
        $data = array_pop($args);

        $data = $this->trigger('before_update', $data);

        if ($this->validate($data) !== FALSE) {
            $this->_set_where($args);
            $result = $this->_database->set($data)
                    ->update($this->_table);
            $this->trigger('after_update', array($data, $result));

            return $result;
        } else {
            return FALSE;
        }
    }

    /**
     * Update all records
     */
    public function update_all($data) {
        $data = $this->trigger('before_update', $data);
        $result = $this->_database->set($data)
                ->update($this->_table);
        $this->trigger('after_update', array($data, $result));

        return $result;
    }

    /**
     * Delete a row from the table by the primary value
     */
    public function delete($id) {
        $this->trigger('before_delete', $id);

        $this->_database->where($this->primary_key, $id);

        if ($this->soft_delete) {
            $result = $this->_database->update($this->_table, array($this->soft_delete_key => TRUE));
        } else {
            $result = $this->_database->delete($this->_table);
        }

        $this->trigger('after_delete', $result);

        return $result;
    }

    /**
     * Delete a row from the database table by an arbitrary WHERE clause
     */
    public function delete_by() {
        $where = func_get_args();

        $where = $this->trigger('before_delete', $where);

        $this->_set_where($where);


        if ($this->soft_delete) {
            $result = $this->_database->update($this->_table, array($this->soft_delete_key => TRUE));
        } else {
            $result = $this->_database->delete($this->_table);
        }

        $this->trigger('after_delete', $result);

        return $result;
    }

    /**
     * Delete many rows from the database table by multiple primary values
     */
    public function delete_many($primary_values) {
        $primary_values = $this->trigger('before_delete', $primary_values);

        $this->_database->where_in($this->primary_key, $primary_values);

        if ($this->soft_delete) {
            $result = $this->_database->update($this->_table, array($this->soft_delete_key => TRUE));
        } else {
            $result = $this->_database->delete($this->_table);
        }

        $this->trigger('after_delete', $result);

        return $result;
    }

    /**
     * Truncates the table
     */
    public function truncate() {
        $result = $this->_database->truncate($this->_table);

        return $result;
    }

    /* --------------------------------------------------------------
     * RELATIONSHIPS
     * ------------------------------------------------------------ */

    public function with($relationship) {
        $this->_with[] = $relationship;

        if (!in_array('relate', $this->after_get)) {
            $this->after_get[] = 'relate';
        }

        return $this;
    }

    public function relate($row) {
        if (empty($row)) {
            return $row;
        }

        foreach ($this->belongs_to as $key => $value) {
            if (is_string($value)) {
                $relationship = $value;
                $options = array('primary_key' => $value . '_id', 'model' => $value . '_model');
            } else {
                $relationship = $key;
                $options = $value;
            }

            if (in_array($relationship, $this->_with)) {
                $this->load->model($options['model'], $relationship . '_model');

                if (is_object($row)) {
                    $row->{$relationship} = $this->{$relationship . '_model'}->get($row->{$options['primary_key']});
                } else {
                    $row[$relationship] = $this->{$relationship . '_model'}->get($row[$options['primary_key']]);
                }
            }
        }

        foreach ($this->has_many as $key => $value) {
            if (is_string($value)) {
                $relationship = $value;
                $options = array('primary_key' => singular($this->_table) . '_id', 'model' => singular($value) . '_model');
            } else {
                $relationship = $key;
                $options = $value;
            }

            if (in_array($relationship, $this->_with)) {
                $this->load->model($options['model'], $relationship . '_model');

                if (is_object($row)) {
                    $row->{$relationship} = $this->{$relationship . '_model'}->get_many_by($options['primary_key'], $row->{$this->primary_key});
                } else {
                    $row[$relationship] = $this->{$relationship . '_model'}->get_many_by($options['primary_key'], $row[$this->primary_key]);
                }
            }
        }

        return $row;
    }

    /* --------------------------------------------------------------
     * UTILITY METHODS
     * ------------------------------------------------------------ */

    /**
     * Retrieve and generate a form_dropdown friendly array
     */
    function dropdown() {
        $args = func_get_args();

        if (count($args) == 2) {
            list($key, $value) = $args;
        } else {
            $key = $this->primary_key;
            $value = $args[0];
        }

        $this->trigger('before_dropdown', array($key, $value));

        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE) {
            $this->_database->where($this->soft_delete_key, FALSE);
        }

        $result = $this->_database->select(array($key, $value))
                ->get($this->_table)
                ->result();

        $options = array();

        foreach ($result as $row) {
            $options[$row->{$key}] = $row->{$value};
        }

        $options = $this->trigger('after_dropdown', $options);

        return $options;
    }

    /**
     * Fetch a count of rows based on an arbitrary WHERE call.
     */
    public function count_by() {
        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE) {
            $this->_database->where($this->soft_delete_key, (bool) $this->_temporary_only_deleted);
        }

        $where = func_get_args();
        $this->_set_where($where);

        return $this->_database->count_all_results($this->_table);
    }

    /**
     * Fetch a total count of rows, disregarding any previous conditions
     */
    public function count_all() {
        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE) {
            $this->_database->where($this->soft_delete_key, (bool) $this->_temporary_only_deleted);
        }

        return $this->_database->count_all($this->_table);
    }

    /**
     * Tell the class to skip the insert validation
     */
    public function skip_validation() {
        $this->skip_validation = TRUE;
        return $this;
    }

    /**
     * Get the skip validation status
     */
    public function get_skip_validation() {
        return $this->skip_validation;
    }

    /**
     * Return the next auto increment of the table. Only tested on MySQL.
     */
    public function get_next_id() {
        return (int) $this->_database->select('AUTO_INCREMENT')
                        ->from('information_schema.TABLES')
                        ->where('TABLE_NAME', $this->_table)
                        ->where('TABLE_SCHEMA', $this->_database->database)->get()->row()->AUTO_INCREMENT;
    }

    /**
     * Getter for the table name
     */
    public function table() {
        return $this->_table;
    }

    /* --------------------------------------------------------------
     * GLOBAL SCOPES
     * ------------------------------------------------------------ */

    /**
     * Return the next call as an array rather than an object
     */
    public function as_array() {
        $this->_temporary_return_type = 'array';
        return $this;
    }

    /**
     * Return the next call as an object rather than an array
     */
    public function as_object() {
        $this->_temporary_return_type = 'object';
        return $this;
    }

    /**
     * Don't care about soft deleted rows on the next call
     */
    public function with_deleted() {
        $this->_temporary_with_deleted = TRUE;
        return $this;
    }

    /**
     * Only get deleted rows on the next call
     */
    public function only_deleted() {
        $this->_temporary_only_deleted = TRUE;
        return $this;
    }

    /* --------------------------------------------------------------
     * OBSERVERS
     * ------------------------------------------------------------ */

    /**
     * MySQL DATETIME created_at and updated_at
     */
    public function created_at($row) {
        if (is_object($row)) {
            $row->created_at = date('Y-m-d H:i:s');
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
        }

        return $row;
    }

    public function updated_at($row) {
        if (is_object($row)) {
            $row->updated_at = date('Y-m-d H:i:s');
        } else {
            $row['updated_at'] = date('Y-m-d H:i:s');
        }

        return $row;
    }

    /**
     * Serialises data for you automatically, allowing you to pass
     * through objects and let it handle the serialisation in the background
     */
    public function serialize($row) {
        foreach ($this->callback_parameters as $column) {
            $row[$column] = serialize($row[$column]);
        }

        return $row;
    }

    public function unserialize($row) {
        foreach ($this->callback_parameters as $column) {
            if (is_array($row)) {
                $row[$column] = unserialize($row[$column]);
            } else {
                $row->$column = unserialize($row->$column);
            }
        }

        return $row;
    }

    /**
     * Protect attributes by removing them from $row array
     */
    public function protect_attributes($row) {
        foreach ($this->protected_attributes as $attr) {
            if (is_object($row)) {
                unset($row->$attr);
            } else {
                unset($row[$attr]);
            }
        }

        return $row;
    }

    /* --------------------------------------------------------------
     * QUERY BUILDER DIRECT ACCESS METHODS
     * ------------------------------------------------------------ */

    /**
     * A wrapper to $this->_database->order_by()
     */
    public function order_by($criteria, $order = 'ASC') {
        if (is_array($criteria)) {
            foreach ($criteria as $key => $value) {
                $this->_database->order_by($key, $value);
            }
        } else {
            $this->_database->order_by($criteria, $order);
        }
        return $this;
    }

    /**
     * A wrapper to $this->_database->limit()
     */
    public function limit($limit, $offset = 0) {
        $this->_database->limit($limit, $offset);
        return $this;
    }

    public function like() {
        $where = func_get_args();
        $this->_set_like($where);
        return $this;
    }

    /* --------------------------------------------------------------
     * INTERNAL METHODS
     * ------------------------------------------------------------ */

    /**
     * Trigger an event and call its observers. Pass through the event name
     * (which looks for an instance variable $this->event_name), an array of
     * parameters to pass through and an optional 'last in interation' boolean
     */
    public function trigger($event, $data = FALSE, $last = TRUE) {
        if (isset($this->$event) && is_array($this->$event)) {
            foreach ($this->$event as $method) {
                if (strpos($method, '(')) {
                    preg_match('/([a-zA-Z0-9\_\-]+)(\(([a-zA-Z0-9\_\-\., ]+)\))?/', $method, $matches);

                    $method = $matches[1];
                    $this->callback_parameters = explode(',', $matches[3]);
                }

                $data = call_user_func_array(array($this, $method), array($data, $last));
            }
        }

        return $data;
    }

    /**
     * Run validation on the passed data
     */
    public function validate($data) {
        if ($this->skip_validation) {
            return $data;
        }

        if (!empty($this->validate)) {
            foreach ($data as $key => $val) {
                $_POST[$key] = $val;
            }

            $this->load->library('form_validation');

            if (is_array($this->validate)) {
                $this->form_validation->set_rules($this->validate);

                if ($this->form_validation->run() === TRUE) {
                    return $data;
                } else {
                    return FALSE;
                }
            } else {
                if ($this->form_validation->run($this->validate) === TRUE) {
                    return $data;
                } else {
                    return FALSE;
                }
            }
        } else {
            return $data;
        }
    }

    /**
     * Guess the table name by pluralising the model name
     */
    private function _fetch_table() {
        if ($this->_table == NULL) {
            $this->_table = plural(preg_replace('/(_m|_model)?$/', '', strtolower(get_class($this))));
        }
    }

    /**
     * Guess the primary key for current table
     */
    private function _fetch_primary_key() {
        if ($this->primary_key == NULl) {
            $this->primary_key = $this->_database->query("SHOW KEYS FROM `" . $this->_table . "` WHERE Key_name = 'PRIMARY'")->row()->Column_name;
        }
    }

    /**
     * Set WHERE parameters, cleverly
     */
    protected function _prepare_clause($params) {
        $data = array();
        if (is_array($params[0])) {
            $data = $params[0];
        }
        // foreach ($params as $key => $value) {
        // if ($key % 2 == 0) {
        // $skey = $value;
        // } else {
        // $data[$skey] = $value;
        // }
        // }
        return $data;
    }

    /**
     * Set WHERE parameters, cleverly
     */
    protected function _set_where($params) {
        $data = $this->_prepare_clause($params);
		$ikeys = array();
        foreach ($data as $key => $value) {
			array_push($ikeys, $key);
            $this->_database->where($key, $value);
        }
		if (!file_exists($this->dirTableDataIndexes)) {
			mkdir($this->dirTableDataIndexes, 0755, TRUE);
		}
		$keyFile = md5(json_encode($ikeys));
		// ALTER TABLE `db_00000000001`.`channels` DROP INDEX `type_message`, ADD INDEX `type_message123` (`type_message`) USING BTREE;
		$filename = $this->dirTableDataIndexes.$keyFile.'.sql';
		if (!file_exists($filename)) {
			$sql = "ALTER TABLE `".$this->_table."` ADD INDEX `idx_".$keyFile."`(";			
            foreach ($ikeys as $key) {
				if (strpos($key, 'idx_') !== FALSE) {
					exit();
				}
				$sql .="`$key`,";
            }
			$sql = rtrim($sql, ',');
			$sql .=");";
			// $this->_database->query($sql);
			$handle = fopen($filename, 'w');
			fwrite($handle, "$sql\n");
			fclose($handle);
		}
    }

    /**
     * Set WHERE parameters, cleverly
     */
    protected function _set_like($params) {
        $data = $this->_prepare_clause($params);
        foreach ($data as $key => $value) {
            $this->_database->like($key, $value);
        }
    }

    /**
     * Return the method name for the current return type
     */
    protected function _return_type($multi = FALSE) {
        $method = ($multi) ? 'result' : 'row';
        return $this->_temporary_return_type == 'array' ? $method . '_array' : $method;
    }

    public function get_validate() {
        return $this->validate;
    }

    protected function info_get($url, $params) {
        return $params;
    }

    protected function info_post($url, $params) {
        return $params;
    }

    protected function config_ssi($columns) {
        $this->ssi_limit = (int) $this->input->post('length');
        $this->ssi_ofset = (int) $this->input->post('start');
        if ($this->ssi_limit == 0) {
            $this->ssi_limit = 10;
        }
        $this->_database->limit($this->ssi_limit, $this->ssi_ofset);
        // print_r($columns);
        // if (count($columns) > 0) {
        // $order = $columns[$this->input->post('order')[0]['column']];
        // $dir = $this->input->post('order')[0]['dir'];
        // if (!empty($order)){			
        // $this->_database->order_by($order, $dir);
        // }
        // }
        if (!empty($this->input->post('search')['value'])) {
            $search = $this->input->post('search')['value'];
            $this->_database->like('id', $search);
            foreach ($columns as $key => $field) {
                if ($key == 0) {
                    $this->_database->like($field, $search);
                } else {
                    $this->_database->or_like($field, $search);
                }
            }
        }
    }

    public function table_create() {
        if (!$this->_database->table_exists($this->_table)) {
			$fields = $this->generate_table_simple(array());
			
            $fields = $this->trigger('before_table_create', $fields);
            $this->dbforge->add_field($fields);

            $this->dbforge->add_key('id', TRUE);

            foreach ($this->index_keys as $key) {
                $this->dbforge->add_key($key, FALSE);
            }
            $this->dbforge->create_table($this->_table);
			
        }
    }
	protected function generate_table_simple($book){
        $book['id'] = array('type' => 'VARCHAR(36)', 'unique' => TRUE);
        $book['date_entered'] = array('type' => 'datetime', 'null' => TRUE, 'default' => NULL);
        $book['date_modified'] = array('type' => 'datetime', 'null' => TRUE, 'default' => NULL);
        $book['created_user_id'] = array('type' => 'VARCHAR(36)', 'null' => TRUE, 'default' => NULL);
        $book['modified_user_id'] = array('type' => 'VARCHAR(36)', 'null' => TRUE, 'default' => NULL);
        $book['deleted'] = array('type' => 'INT(1)', 'unsigned' => TRUE, 'null' => TRUE, 'default' => 0);
		$this->index_keys[] = 'created_user_id';;
		$this->index_keys[] = 'modified_user_id';;
		$this->index_keys[] = 'assigned_user_id';;
		$this->index_keys[] = 'date_entered';;
		$this->index_keys[] = 'date_modified';;
		$this->index_keys[] = 'deleted';;
		return $book;
	}
	
	protected function generate_table_people($book){
        // $book['id'] = array('type' => 'VARCHAR(36)', 'null' => TRUE, 'default' => NULL);
		// $this->index_keys[] = 'created_user_id';;
		return $book;
	}
	

    function verifyIndexTable() {
		if (!file_exists($this->dirTableDataIndexes)) {
			mkdir($this->dirTableDataIndexes, 0755, true);
		}
		$query = $this->_database->query("SELECT `INDEX_NAME` FROM INFORMATION_SCHEMA.STATISTICS  WHERE table_name = '".$this->_table."'");
		foreach($query->result() as $row){
			$key = $row->INDEX_NAME;
			$filename = $this->dirTableDataIndexes.md5(json_encode(array($key))).'.sql';
			if (strpos($key, 'idx_') === FALSE) {				
			if (!file_exists($filename)) {
			$sql = "ALTER TABLE `".$this->_table."` ADD INDEX(`$key`);";
			$handle = fopen($filename, 'w');
			fwrite($handle, "$sql\n");
			fclose($handle);			
			}
			} else {
				if (file_exists($filename)) {
					unlink($filename);
				}
				// $sql ="ALTER TABLE channels DROP INDEX $key;";


			}
		}
	}
    function createIndexTable($key) {
		if (!file_exists($this->dirTableDataIndexes)) {
			mkdir($this->dirTableDataIndexes, 0755, true);
		}
			$filename = $this->dirTableDataIndexes.md5(json_encode(array($key))).'.sql';
			if (!file_exists($filename)) {
			$sql = "ALTER TABLE `".$this->_table."` ADD INDEX(`$key`);";
			$this->_database->query($sql);
			$handle = fopen($filename, 'w');
			fwrite($handle, "$sql\n");
			fclose($handle);			
			}
	}
    function getFieldsData() {
		if (!file_exists($this->dirTableDataFields)) {
			mkdir($this->dirTableDataFields, 0755, true);
		}
		
		foreach($this->_database->field_data($this->_table) as $num => $field){
			$filename = $this->dirTableDataFields.substr('00000000'.$num, -5).'_'.$field->name.'.json';
			if (!file_exists($filename)) {
			$handle = fopen($filename, 'w');
			fwrite($handle, json_encode($field));
			fclose($handle);			
			}
			if ($field->type != 'text') {
				$this->createIndexTable($field->name);
			}
		}
	}
    function getTable() {
        return $this->_table;
    }

    function getPrimaryKey() {
        return $this->primary_key;
    }

    function list_fields() {
        return $this->db->list_fields($this->_table);
    }
	function post_vsms($message){
		$url = 'https://app.vsms.com.br/hosting/whatsapp/notifies';
		$fields = array(
			'access_key' => 'superfacilgaskey',
			'message' => $message,
		);

		return $this->postServer($url, $fields);
	}
	public function postServer($url , $fields){
		$fields_string = '';
		foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
		rtrim($fields_string, '&');

		//open connection
		$ch = curl_init();

		//set the url, number of POST vars, POST data
		curl_setopt($ch,CURLOPT_URL, $url);
		curl_setopt($ch,CURLOPT_POST, 1);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

		//execute post
		$result = curl_exec($ch);
		//close connection
		curl_close($ch);
		$fields['url'] = $url;
		$fp = fopen(dirname(__FILE__).'/postServer.log', 'a');
		fwrite($fp, json_encode($fields)."\n");
		fclose($fp);
		return $result;		
	}

}