<?php
/**
* EZ_MySqli extends php's mysqli api to provide ease of use and simplicity to some of the original
* extentions features.  
* The BIGGEST increase in ease of use is with prepared/parametarized queries.
* Prepared queries separate the query from the data, which makes sql-injection a thing of the
* past.  Any time you are sending user-submitted data of any kind (get, post, form data, etc...),
* prepared queries are the secure way to do it.
*
* Any function which takes a 'replacements' variable will replace question marks in the query or
* where clause with the contents of 'replacements' automatically. ('replacements' can always be
* either array or object)  If you do not wish for a query to be prepared, DO NOT SET THE 
* 'replacements' variable, and a normal query will be used instead.
*
* The "insert()" and "update()" functions form prepared queries automatically, and this CANNOT be
* overridden!
* It should be noted, however, that the 'update()' function can take replacements for the WHERE
* clause as well as using prepared queries on the data being updated.
* 
* If you need to do a prepared query the normal way, instead of using this libraries convience
* methods, you are in luck, as NONE OF THE default methods (besides the query() method) have been
* overriden!...so you can still run prepare() the normal way, and manually bind the parameters and
* all that jazz.
* License is creative commons, but give me (Eric Lien) credit please.
*/


class EZ_MySqli extends mysqli{
  var $default_fetch_mode = 'assoc';
	var $last_query = null;
	var $last_result = null;
	//can't write to $this->insert_id, because it's declaired private in mysqli
	protected $last_insert_id = null;
	//can't write to $this->affected_rows, because it's declaired private in mysqli
	protected $last_affected_rows = null;
	
	protected $statementmgr = null;
		
	/**
	* Attempts a connection to the database
	* inputs: 
	* database: database name
	* server: host to connect to
	* user: username to connect with
	* pw: password to use
	* returns: EZ_MySqli object
	*/
	function __construct($database=DATABASE, $server=DB_SERVER, $user=DB_USER, $pw=DB_PASSWORD){
	  if(!isset($server) || !isset($user) || !isset($pw) || !isset($database)) return;
	  else{
		  parent::__construct($server, $user, $pw, $database);
		}
	}

  /**
  * Set default mode with which to fetch query results
  * inputs: 
  * mode: 'assoc' or 'object'
  */
	function setDefaultFetchMode($mode='assoc'){
	  $mode = strtolower($mode);
	  if($mode != 'assoc' && $mode != 'object') $mode = 'assoc';
	  $this->default_fetch_mode = $mode;
	}
	
  /**
  * Gets number of rows affected by the last query
  */
	function affected_rows(){
	  return $this->last_affected_rows;		
	}

	/**
	* returns last auto incremented value
	*/
	function insert_id(){
	  return $this->last_insert_id;
	}

	/**
	* run a query, or prepared query
	* inputs:
	* query: the query you wish to run
	* replacements (array or object): if set, a prepared query will be run, binding the contents
	* of "replacements" with related "hooks" or the EQUAL NUMBER of question marks as non-hooks in the query.
	* also, php will give you the same error if the query is malformed that doesn't make sense if you
	* use a prepared query...be warned!
	* result_mode: read the mysqli documentation on the query() method for what this does
	* returns:
	* mysqli_result object if a normal query, or a PreparedStatementHelper object if a prepared query
	* is run
	*/
	function query($query, $replacements=null, $result_mode=MYSQLI_STORE_RESULT){
	  if((is_array($replacements) or is_object($replacements)) and !empty($replacements)){
	    return $this->prepared_query($query, $replacements);
	  }else{
		  $this->last_result = parent::query($query, $result_mode) or 
		  die("The attemped query failed because: ".$this->error."<br />Query: $query");
		  $this->last_query = $query;
		  $this->last_affected_rows = $this->affected_rows;
		  $this->last_insert_id = $this->insert_id;
		}
		return $this->last_result;
	}
	
	/**
	* runs a prepared query
	* inputs:
	* query: the query you wish to run as a prepared query
	* replacements (array or object): if set the contents will be bound to the "?"'s in the query
	* returns:
	* PreparedStatementHelper object
	*/
	function prepared_query($query, $replacements=null){
	  if((!is_array($replacements) and !is_object($replacements)) or empty($replacements)) $replacements = null;
	  //replace hooks in query, put parameters in order to bind
	  $replacements = $this->preparse_prepared_query($query, $replacements);
	  
	  if($query != $this->last_query){ //check to see if it is a new query, or same as last
	    $this->free_result();
	    unset($this->last_result, $this->statementmgr);
	    $this->last_result =& $this->prepare($query) or die("The attemped query failed because: ".$this->error."<br />Query: $query");
	    $this->statementmgr =& new StatementMGR($this->last_result, $replacements);
	  }else{ //if it is the same, we only have to bind the params and execute again
	    $this->statementmgr->run_stmt($replacements);
	  }
	  $this->last_query = $query;
	  $this->last_affected_rows = $this->statementmgr->affected_rows();
 	  $this->last_insert_id = $this->statementmgr->insert_id();
 	  return $this->statementmgr;
	}
	
	/**
	* parses query, replacing hooks with ?'s, and sorts hooks and parameters to correspond with
	* their positions in the query.
	*/
	function preparse_prepared_query(&$query, $replacements){
	  if(!$replacements) return;
	  if(is_object($replacements)) $replacements = (array) $replacements;
	  $params = array();
	  //loop over all "hooks", find them in the query, and replace them with @'s,
	  //associate values to replace hooks by with the hooks position in the query
	  $last_param_at = 0;
	  //since replacing hooks with @ changes string length, we must do hooks and regular params separately :(
	  foreach($replacements as $k=>$v){
	    if(strpos($k,":") === false){
	      continue;
	    }else{
	      //find and index all occurances of $k, and associate $v with those occurances
	      while(true){
	        //find first instance of $k in the $query
	        $k_idx = strpos($query, $k);
	        if($k_idx === false) break; //break if $k is no longer found
	        $params[$k_idx] = $v; //save $v to an array, indexed in order of where $k is found
	        $query = preg_replace('/'.preg_quote($k,'/').'/', '@', $query, 1); //replace 1 occurance of $k in $query with a @
	      }
	    }
	  }
	  //find regular params, and record where they occur, replace ?'s with @'s
	  foreach($replacements as $k=>$v){
	    if(strpos($k,":") !== false){
	      continue;
	    }else{
	      //find first instance of $k in the $query
	      $q_idx = strpos($query, '?');
	      $params[$q_idx] = $v; //save $v to an array, indexed in order of where ? is found
	      $query = preg_replace('/'.preg_quote('?','/').'/', '@', $query, 1); //replace 1 occurance of ? in $query with a @
	    }
	  }
	  //change all @'s back to ?'s
	  $query = str_replace('@','?',$query);
	  //sort parameters to be in order of where they occur in the query
    ksort($params);
    return $params;
	}
	
	/**
	* free up memory used by query results
	*/
	function free_result(){
	  if(isset($this->statementmgr)) $this->statementmgr->free_result();
	}

	/**
	* returns table names in connected database as an array
	*/
	function getTables(){
		$result = $this->query('SHOW TABLES');
		while( $row = $result->fetch_row())
			$tables[] = $row[0];
		return $tables;
	}

	/**
	* gets field names of a table as an array
	* inputs:
	* table: name of table
	* returns:
	* field names of table in an array
	*/
	function getFields($table){
		$q="SELECT * FROM `$table` LIMIT 1";
		$r = $this->getRow($q);
		return array_keys((array) $r);
	}

	/**
	* Inserts an array or object into a table using a prepared query
	* inputs:
	* table: name of table to insert into
	* data: associative array or object to insert into the table (field names must match properties
	* /keys!)
	* returns:
	* the insert_id if there is an auto-increment field, or true if not on success, false otherwise
	*/
	function insert($table, $data=null){
	  if(!$data) return false;
	  if(is_object($data)) $data = (array) $data;
	  $first=true;
		foreach($data as $fieldname=>$value){
			if(!$first){
				$fields.=', ';
				$values.=', ';
		  }
			$first=false;
			$hook = ":\\$fieldname/:";
			$fields.="`$fieldname`";
			$values.=$hook;
			$params[$hook] = $value;
		}
		if(!empty($fields) && !empty($values)){
		  $q="INSERT INTO `$table` ($fields) VALUES ($values)";
		  $result = $this->prepared_query($q, $params);		  
		}
		if($result){
		  $id = $this->insert_id();
			unset($result);
			$this->free_result();
			if($id) return $id;
			else return true;
		}
		else return false;
	}

	/**
	* Updates a table with an array or object using a prepared query
	* inputs:
	* table: name of table to update
	* data: associative array or object to update the table with
	* (field names must match properties/keys!)
	* where_clause: the where clause used to find rows to update
	* replacements: bind ?'s in where clause with data in this array/object
	* returns:
	* the number of rows affected by the query, or false if there is an error
	*/
	function update($table, $data=null, $where_clause, $replacements=null){
	  if(is_object($data)) $data = (array) $data;
	  $where_clause = str_replace('WHERE','',str_replace('where','',$where_clause));
	  if(!empty($where_clause)) $where_clause = "WHERE ".$where_clause;
	  if(!empty($table) && !empty($where_clause) && !empty($data)){
			$first=true;
			foreach($data as $fieldname=>$value){
				if(!$first)
					$terms.=', ';
				$first=false;
				$hook = ":\\$fieldname/:";
				$terms.="`$fieldname` = $hook";
				$params[$hook] = $value;
			}
			if((is_array($replacements) or is_object($replacements)) and !empty($replacements))
	      $replacements = array_merge($params, $replacements);
	    else
	      $replacements = $params;
			if(!empty($terms)){
			  $q="UPDATE `$table` SET $terms $where_clause";
			  $this->prepared_query($q, $replacements);
			}
		}else return false;
		$affected_rows = $this->affected_rows();
		$this->free_result();
		return $affected_rows;
	}
	
	/**
	* Delete rows from a table
	* inputs:
	* table: table to delete from
	* where_clause: where clause selecting what to delete
	* replacements: bind ?'s in where clause with data in this array/object
	* returns:
	* rows affected by this query
	*/	
	public function delete($table, $where_clause, $replacements=null){
	  $where_clause = str_replace('WHERE','',str_replace('where','',$where_clause));
	  if(!empty($where_clause)) $where_clause = "WHERE ".$where_clause;
	  $q="DELETE FROM `$table` $where_clause";
	  if((!is_array($replacements) and !is_object($replacements)) or empty($replacements))
      $replacements = null;
	  if(!empty($table) && !empty($where_clause))	
			$this->query($q, $replacements);
		$this->free_result();
    return $this->affected_rows();
	}
	
	/**
	* Gets the first row from a result set and returns it as either an assoc array or an object
	* inputs:
	* query: query to run to fetch rows from the database
	* replacements: bind ?'s in query with data in this array/object
	* fetch_mode: 'assoc' or 'object' how to fetch results
	* returns: query results as an array or object (only first row), false on error
	*/
	function getOne($query=null, $replacements=null, $fetch_mode=null){
		if(!$fetch_mode) $fetch_mode = $this->default_fetch_mode;
		if($fetch_mode != 'object') $fetch_mode = 'assoc';
		$func = "fetch_$fetch_mode";
		if($query){
		  if($replacements)
		    $result = $this->prepared_query($query, $replacements);
		  else
		    $result = $this->query($query);
		  if(is_object($result)) $one = $result->$func();
		  else return $result;
		  unset($result);
		  $this->free_result();
	    return $one;
		}return false;
	}
	
	/**
	* alias of getOne()
	*/
  function getRow($query=null, $replacements=null, $mode=null){
    return $this->getOne($query, $replacements, $mode);
  }	

  /**
	* Gets the result set and returns the rows as either an assoc arrays or an objects
	* inputs:
	* query: query to run to fetch rows from the database
	* replacements: bind ?'s in query with data in this array/object
	* fetch_mode: 'assoc' or 'object' how to fetch results
	* returns: query results as an arrays or objects, false on error
	*/
	function getAll($query=null, $replacements=null, $fetch_mode=null){
	  $rows = array();
	  if(!$fetch_mode) $fetch_mode = $this->default_fetch_mode;
		if($fetch_mode != 'object') $fetch_mode = 'assoc';
		$func = "fetch_$fetch_mode";
		if($query){
		  if($replacements){
		    $func.='s';
		    $result = $this->prepared_query($query, $replacements);
		    if(is_object($result)) $rows = $result->$func();
		    else return $result;
		  }else{
		    $result = $this->query($query);
		    if(is_object($result)){
			    while($row=$result->$func())
			      $rows[]=$row;
		    }else return $result;
		  }
	  }
	  $this->free_result();
		return $rows;
	}

  /**
  * Gets a column of data as an array
  * inputs:
  * colname: name of column/field to get data from
  * query: query to run to get data
  * returns:
  * column data as an array
  */
	function getCol($colname, $query){
	  $tmp = array();
		if($rows = $this->getAll($query, null, 'assoc'))
			foreach($rows as $r)
				$tmp[] = $r[$colname];
		return $tmp;
	}
	
	/**
	* Gets a row of data as an object (alias of getOne with fetch_mode=object)
	*/
	function getObject($query=null, $replacements=null){
	  return $this->getOne($query, $replacements, "object");
	}
	/**
	* Gets rows of data as objects (alias of getAll with fetch_mode=object)
	*/
	function getObjects($query=null, $replacements=null){
	  return $this->getAll($query, $replacements, "object");
	}
	
	/**
	* Makes a string query-safe (use a prepared query instead! It's safer!, and now it's pretty easy)
	* inputs:
	* string: string to make safe
	* returns:
	* a "query-safe" string
	*/
	function steralize($string){
		return $this->real_escape_string(addslashes($string));
	}
	
	/**
	* alias of steralize
	*/
	function clean($string){
	  return $this->steralize($string);
	}
	
	/**
	* frees up memory used by PreparedStatementHelper by deleting the variables
	* and setting them to null
	*/
	function closeStatementMGR(){
	  unset($this->statementmgr);
	  $this->statementmgr = null;
	}
	
	/**
	* frees up all result memory/objects used by this class
	* You may wish to run this if you do a query or prepared_query that you know probably took
	* up a large amount of memory.
	*/
	function reset(){
	  @$this->free_result();
	  $this->closeStatementMGR();
	  unset($this->last_result);
	  $this->last_result = null;
	}
	
	function __destruct(){
	  $this->reset();
	  $this->close();	  
	}    
}


/**
* The way php does prepared/parametarized queries is aweful and rediculously poorly thought out
* This class is meant to do all the dirty work for you so you don't have to think, it just WORKS
* as regular queries do. There are probably some bugs with this, most of this is just
* user-submitted examples I hacked together into a working solution from php.net...
* hopefully it works good enough to encourage use of prepared/parametarized queries.
* I'm biting my tongue on this, but I cann't get this to break yet!
*/

class StatementMGR{
  var $stmt;
  protected $orig_query;
  protected $query;
  protected $params;
  protected $param_indexes;
  protected $typestring;
  protected $fieldnames;
  protected $assoc;
  
  
  /**
  * Constructor: executes prepared statement, binding parameters
  * inputs:
  * stmt: mysqli_stmt object that was returned by mysqli->prepare()
  * replace: array or object of parameters in the query that will be bound
  */
  function __construct(mysqli_stmt $stmt, $replace=null){
    $this->stmt = $stmt;
    $this->run_stmt($replace);
  }
  
  /**
  * 
  */
  function run_stmt($replace=null){
    if($replace and (is_array($replace) or is_object($replace))){
      if(is_object($replace)) $replace = (array) $replace;
      $this->params = $replace;
      $this->bind_params();
    }
    $this->execute();
  }
  
  /**
  * Binds parameters to the prepared statement
  * (I know this seems terrible, but I tried and can't find a better way to do this...)
  */
  function bind_params(){
    array_unshift($this->params, $this->getPreparedTypeString($this->params));
    //I'd be really really happy if anyone could explain why this works, but 
    //commenting out the while loop and just using $arr doesn't
    $arr =& $this->params;
    $count = 0;
    while($this->params[$count]){
      $params[$count] = &$arr[$count]; 
      $count++;
    }    
    call_user_func_array(array($this->stmt, 'bind_param'), $params);
	}
	
	/**
	* Gets the "type string" from an array or object containing data that will be bound
	* inputs:
	* saParams: array or object containing data
	* returns:
	* string of types of data to be bound
	*/
	function getPreparedTypeString($saParams){
    $sRetval = '';
    //if not an array/object, or empty.. return empty string
    if ((!is_array($saParams) and !is_object($saParams)) or empty($saParams))
      return $sRetval;
    
    //iterate the elements and figure out what they are, and append to result 
    foreach ($saParams as $Param){
      if (is_int($Param) && $Param < 2147483647) $sRetval .= 'i';
      else if(is_double($Param) or is_float($Param) or (is_int($Param) and $Param >= 2147483647)) $sRetval .= 'd';
      else if (is_string($Param)) $sRetval .= 's';
      else $sRetval .= 'b';
    }
    $this->typestring = $sRetval;
    return $sRetval;
  }
  
  function execute(){
    $this->free_result();
    return $this->stmt->execute();
  }
  
  function store_result(){
    return $this->stmt->store_result();
  }
  
  function free_result(){
    return $this->stmt->free_result();
  }
  
  function result_metadata(){
    return $this->stmt->result_metadata();
  }
  
  /**
  * Trys to bind result data to an array of fields
  * (I know it's ugly and scary, but you try making it work better, I dare you)
  */
  function bind_result(){
    unset($this->fieldnames, $this->assoc, $arr, $fieldnames);
    $this->store_result(); //buffers result data, essential if long blob is returned...
    $meta = $this->result_metadata();
    if($meta){
      $count = 1; //start the count from 1. First value has to be a reference to stmt.
      while($field = $meta->fetch_field()){
        $fieldnames[$count] = &$arr[$field->name]; //load the fieldnames into an array.. 
        $count++;
      }
      call_user_func_array(array($this->stmt, "bind_result"), $fieldnames);
    }else{
      $fieldnames = false;
      $arr = false;
    }
    $this->fieldnames =& $fieldnames;
    $this->assoc =& $arr;
    return $arr;
  }
  
  function fetch(){
    return $this->stmt->fetch();
  }
  
  /**
  * fetches one result as an object
  */
  function fetch_object(){
    $this->bind_result();
    $obj = $this->assoc;
    if(!$this->fetch()) return false;
    else return (object) $obj;
  }
  
  /**
  * fetches all results, returning them as objects
  */
  function fetch_objects(){
    $this->bind_result();
    $arr = $this->assoc;
    $copy = create_function('$a', 'return $a;');
    $results = array();
    while($this->fetch())
      $results[] = (object) array_map($copy, $arr);
    return $results;
  }
  
  /**
  * fetches one result as an array
  */
  function fetch_array(){
    $this->bind_result(); 
    $arr = $this->assoc;
    if(!$this->fetch()) return false;
    else return $arr;
  }
  
  /**
  * alias of fetch_array
  */
  function fetch_assoc(){
    return $this->fetch_array();
  }
  
  /**
  * fetch results as arrays
  */
  function fetch_arrays(){
    $this->bind_result();
    $arr = $this->assoc;
    $copy = create_function('$a', 'return $a;');
    $results = array();
    while($this->fetch()){
      $results[] = array_map($copy, $arr);
    }
    return $results;
  }
  
  /**
  * alias of fetch_arrays
  */
  function fetch_assocs(){
    return $this->fetch_arrays();
  }
  
  /**
  * gets number of rows affected by last query
  */
  function affected_rows(){
    return $this->stmt->affected_rows;
  }
  
  /**
  * gets the insert id generated by the last insert
  */
  function insert_id(){
    return $this->stmt->insert_id;
  }
  
  /**
  * destructor: frees up memory before destroying object
  */
  function __destruct(){
    @$this->stmt->free_result();
    $this->stmt->close();
  }
  
}

?>
