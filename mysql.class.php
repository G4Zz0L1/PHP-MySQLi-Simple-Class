<?php
class DB
{
   /**
    * @var <str> The mode to return results (MYSQLI_ASSOC, MYSQLI_NUM, or MYSQLI_BOTH)
    */
   private $fetch_mode = MYSQLI_ASSOC;

   /**
    * @var <str> The number of rows affected by the last query
    */
   private $mysqli_affected_rows = "";

   /**
    * @var <bln> The flag for activate query logging -> activate it in the construct
    */
   private $mysqli_debug = false;

   /**
    * @var <str> The last inserted id (only after insert)
    */
   private $mysqli_last_id = "";

   /**
    * @var <str> The result of the last query executed
    */
   private $mysqli_last_info = "";

   /**
    * @var <str> The last query executed
    */
   private $mysqli_last_query = "";

   /**
    * @var <str> The file in which we record the query
    */
   private $mysqli_log_file = "";

   /**
    * @var <bln> The state of the transaction, if active or not
    */
   private $mysqli_transaction_status = NULL;

   /**
    * @desc     Creates the MySQLi object for usage.
    *
    * @param <arr> $db Required connection params.
    */
   public function __construct($db)
   {
      $this->mysqli_debug = false;
      $this->mysqli_log_file = 'config/query.log';
      if (file_exists($this->mysqli_log_file))
      {
         if (!is_writable($this->mysqli_log_file))
         {
            $temp_log = file_get_contents($this->mysqli_log_file);
            if (unlink($this->mysqli_log_file))
            {
               file_put_contents($this->mysqli_log_file, $temp_log);
               chmod($this->mysqli_log_file, 0777);
            }
            else
            {
               $this->mysqli_log_file = 'config/query_new.log';
            }
         }
      }
      $mysqli_new_file = str_replace(".log", "_" . date("Y-m-d_H-i-s") . ".log", $this->mysqli_log_file);
      if (file_exists($this->mysqli_log_file) && filesize($this->mysqli_log_file) > 1000000)
      {
         rename($this->mysqli_log_file, $mysqli_new_file);
      }
      $temp = array('default_host' => 'host', 'default_user' => 'user', 'default_pw' => 'pass', '' => 'table', 'default_port' => 'port');
      foreach ($temp as $key => $value)
      {
         if (!isset($db[$value]) || strlen($db[$value]) < 1)
         {
            $db[$value] = ini_get("mysqli." . $key);
         }
      }
      $this->mysqli = new mysqli($db['host'], $db['user'], $db['pass'], $db['table'], $db['port']);
      $this->mysqli->set_charset("utf8");
      if ($this->mysqli->connect_errno)
      {
         if ($this->mysqli_debug)
         {
            $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") construct\nCONNECTION FAILED\n" . $this->mysqli->connect_error . " - ERRORE " . $this->mysqli->connect_errno . "\n\n\n\n";
            @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
         }
         printf("<b>Connection failed:</b> %s - Error %s\n", $this->mysqli->connect_error, $this->mysqli->connect_errno);
         exit;
      }
   }

   /**
    * @desc Automatically close the connection when finished with this object.
    */
   public function __destruct()
   {
      $this->mysqli->close();
   }

   /**
    * @desc Commit the transaction
    * @return <bln>
    */
   public function commit()
   {
      if ($this->mysqli_debug)
      {
         $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") commit\nTRANSACTION COMMIT\n\n\n\n";
         @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
      }
      $this->mysqli_transaction_status = false;
      $res = $this->mysqli->commit();
      if (!$res)
      {
         $this->mysqli->rollback();
      }
      $this->mysqli->autocommit(true);
      return $res;
   }

   /**
    * @desc Escapes the parameters (either scalar or array)
    */
   public function escape($params = null)
   {
      if (!is_null($params))
      {
         if (is_array($params))
         {
            foreach ($params as $key => $value)
            {
               $params[$key] = $this->escape($value);
            }
            return $params;
         }
         else
         {
            return $this->mysqli->real_escape_string(filter_var(trim($params), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
         }
      }
      else
      {
         return null;
      }
   }

   /**
    * @desc Get the results
    *
    * @param  <mixed>   $field Select a single field if string, multiple field if is an array of string, a single row if numeric, multiple row if is a numeric array or select all if blank
    * @return <mixed>
    */
   public function get($field = null)
   {
      if (func_num_args() > 1)
      {
         $field = array();
         foreach (func_get_args() as $value)
         {
            $field[] = $value;
         }
      }
      $count = 0;
      $type = "";
      $data = array();
      if (is_array($field))
      {
         if (count($field) == 0)
         {
            // empty -> fetch all
            $type = "all";
         }
         else
         {
            if (count(array_filter($field, 'is_null')) > 0)
            {
               // null -> fetch all
               $type = "all";
            }
            else
            {
               if (count(array_filter($field, 'is_array')) > 0)
               {
                  // multidimensional array not supported -> fetch all
                  $type = "all";
               }
               else
               {
                  if (count(array_filter($field, 'is_numeric')) == count($field))
                  {
                     // numeric array -> fetch rows
                     $type = "num_arr";
                  }
                  else
                  {
                     // string array -> fetch columns
                     $type = "str_arr";
                  }
               }
            }
         }
      }
      else
      {
         if (strlen($field) == 0)
         {
            // empty -> fetch all
            $type = "all";
         }
         else
         {
            if (is_null($field))
            {
               // null -> fetch all
               $type = "all";
            }
            else
            {
               if (is_numeric($field))
               {
                  // numeric single -> fetch row
                  $type = "num";
                  $data = "";
               }
               else
               {
                  // string single -> fetch column
                  $type = "str";
               }
            }
         }
      }
      while ($row = $this->result->fetch_array($this->fetch_mode))
      {
         switch ($type)
         {
            case 'all':
               // Grab all the data
               $data[$count] = $row;
               break;
            case 'str':
               // Select the specific column
               if (in_array($field, array_keys($row)))
               {
                  if ($this->mysqli->affected_rows > 1)
                  {
                     $data[$count][$field] = $row[$field];
                  }
                  else
                  {
                     $data = $row[$field];
                  }
               }
               break;
            case 'num':
               // Select the specific row
               if ($count == $field)
               {
                  $data = $row;
               }
               break;
            case 'str_arr':
               // Select the selected columns
               foreach ($field as $value)
               {
                  if (in_array($value, array_keys($row)))
                  {
                     if ($this->mysqli->affected_rows > 1)
                     {
                        $data[$count][$value] = $row[$value];
                     }
                     else
                     {
                        $data[$value] = $row[$value];
                     }
                  }
               }
               break;
            case 'num_arr':
               // Select the selected rows
               foreach ($field as $value)
               {
                  if ($count == $value)
                  {
                     if ($this->mysqli->affected_rows > 1)
                     {
                        $data[$count] = $row;
                     }
                     else
                     {
                        $data = $row;
                     }
                  }
               }
               break;
            default:
               // Grab all the data
               $data[$count] = $row;
               break;
         }
         $count++;
      }
      // Make sure to close the result Set
      $this->result->close();
      return $data;
   }

   /**
    * @desc Returns number of affected rows
    */
   public function get_affected_rows()
   {
      if ($this->mysqli_affected_rows != "")
      {
         return $this->mysqli_affected_rows;
      }
      else
      {
         return false;
      }
   }

   /**
    * @desc Returns the automatically generated insert ID This MUST come after an insert Query.
    */
   public function get_id()
   {
      if ($this->mysqli_last_id != "")
      {
         return $this->mysqli_last_id;
      }
      else
      {
         return false;
      }
   }

   /**
    * @desc Returns the info of the last query
    */
   public function get_last_info()
   {
      if ($this->mysqli_last_info != "")
      {
         return $this->mysqli_last_info;
      }
      else
      {
         return false;
      }
   }

   /**
    * @desc Simple preparation to clean the SQL query to execute
    *
    * @param  <str>          SQL statement
    * @return <mixed|null>
    */
   public function query($SQL)
   {
      $file_path = "";
      foreach (array_reverse(debug_backtrace()) as $file)
      {
         $file_path .= $file['file'] . " -> ";
      }
      $file_path = rtrim($file_path, " -> ");
      $this->SQL = filter_var(trim($SQL), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
      $arr = explode(' ', trim($this->SQL));
      $this->mysqli_last_query = $this->SQL;
      $this->result = $this->mysqli->query($this->SQL);
      if ($this->result == true)
      {
         $this->mysqli_last_id = $this->mysqli->insert_id;
         $this->mysqli_last_info = $this->mysqli->info;
         $this->mysqli_affected_rows = $this->mysqli->affected_rows;
         if ($this->mysqli_debug || stripos($arr[0], "select") === FALSE)
         {
            $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") query\n" . $file_path . "\n" . $this->SQL . "\n\n";
            @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
         }
         return true;
      }
      else
      {
         $this->mysqli_last_id = false;
         $this->mysqli_last_info = false;
         $this->mysqli_affected_rows = false;
         $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") query\n" . $file_path . "\nPROBLEM WITH QUERY: " . $this->SQL . "\n" . $this->mysqli->error . " ERRORE " . $this->mysqli->errno . "\n\n";
         @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
         printf("<b>Problem with SQL:</b><br>\n%s<br>\n%s Errore %s<br>\n", $this->SQL, $this->mysqli->error, $this->mysqli->errno);
         exit;
      }
   }

   /**
    * @desc Rollback the transaction
    * @return <bln>
    */
   public function rollback()
   {
      if ($this->mysqli_debug)
      {
         $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") rollback\nTRANSACTION ROLLBACK\n\n\n\n";
         @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
      }
      $this->mysqli_transaction_status = false;
      $res = $this->mysqli->rollback();
      $this->mysqli->autocommit(true);
      return $res;
   }

   /**
    * @desc Optionally set the return mode.
    *
    * @param <int> $type The mode: 1 for MYSQLI_NUM, 2 for MYSQLI_BOTH, default is MYSQLI_ASSOC
    */
   public function setFetchMode($type)
   {
      if (is_numeric($type))
      {
         switch ($type)
         {
            case 1:
               $this->fetch_mode = MYSQLI_NUM;
               break;
            case 2:
               $this->fetch_mode = MYSQLI_BOTH;
               break;
            default:
               $this->fetch_mode = MYSQLI_ASSOC;
               break;
         }
         return true;
      }
      else
      {
         return false;
      }
   }

   /**
    * @desc Set the autocommit value
    * @param  <bln>   $value The value of the transaction, leave blank will return the current state
    * @return <bol>
    */
   public function transaction($value)
   {
      if ($value == null || !is_bool($value))
      {
         return $this->mysqli_transaction_status;
      }
      else
      {
         if ($value)
         {
            if ($this->mysqli_debug)
            {
               $write = "\n\n" . date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") transaction\nTRANSACTION BEGIN\n\n\n\n";
               @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
            }
            $this->mysqli_transaction_status = true;
            return $this->mysqli->autocommit(false);
         }
         else
         {
            if ($this->mysqli_debug)
            {
               $write = "\n\n" . date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") transaction\nTRANSACTION END\n\n\n\n";
               @file_put_contents($this->mysqli_log_file, $write, FILE_APPEND);
            }
            $this->mysqli_transaction_status = false;
            return $this->mysqli->autocommit(true);
         }
      }
   }
}