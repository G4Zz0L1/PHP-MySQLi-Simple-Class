<?php
class DB
{
   /**
    * @var <obj> The local object (internal value, don't edit)
    */
   private $mysqli = NULL;

   /**
    * @var <str> The number of rows affected by the last query (internal value, don't edit)
    */
   private $mysqli_affected_rows = "";

   /**
    * @var <bln> The flag for activate query logging, activate here
    */
   private $mysqli_debug = false;

   /**
    * @var <str> The mode to return results (MYSQLI_ASSOC, MYSQLI_NUM, or MYSQLI_BOTH)
    */
   private $mysqli_fetch_mode = MYSQLI_ASSOC;

   /**
    * @var <str> The last inserted id (only after insert) (internal value, don't edit)
    */
   private $mysqli_last_id = "";

   /**
    * @var <str> The result of the last query executed (internal value, don't edit)
    */
   private $mysqli_last_info = "";

   /**
    * @var <str> The last query executed (internal value, don't edit)
    */
   private $mysqli_last_query = "";

   /**
    * @var <str> The absolute path that will contain the file in which we record the queries/errors (slash terminated)
    */
   private $mysqli_log_file = "";

   /**
    * @var <bln> True for group logs into folder, false otherwise
    */
   private $mysqli_log_file_group = false;

   /**
    * @var <bln> If debug active -> true for quietly logs error only to file, false for print/debug to screen too
    */
   private $mysqli_log_silent = true;

   /**
    * @var <bln> The state of the transaction, if active or not (internal value, don't edit)
    */
   private $mysqli_transaction_status = NULL;

   /**
    * @desc     Creates the MySQLi object for usage.
    *
    * @param <arr> $db Required connection params.
    */
   public function __construct($db)
   {
      $temp = array('default_host' => 'host', 'default_user' => 'user', 'default_pw' => 'pass', '' => 'table', 'default_port' => 'port', 'default_table' => 'table');
      foreach ($temp as $key => $value)
      {
         if (!isset($db[$value]) || strlen(trim($db[$value])) == 0)
         {
            $db[$value] = (strlen(trim(ini_get("mysqli." . $key))) > 0) ? ini_get("mysqli." . $key) : "";
         }
      }
      $this->mysqli = new mysqli($db['host'], $db['user'], $db['pass'], $db['table'], $db['port']);
      $this->mysqli->set_charset("utf8");
      if ($this->mysqli->connect_errno)
      {
         $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") construct\nCONNECTION FAILED\n" . $this->mysqli->connect_error . " - ERRORE " . $this->mysqli->connect_errno;
         $this->error_handling($write);
      }
   }

   /**
    * @desc Automatically close the connection when finished with this object.
    */
   public function __destruct()
   {
      if (!$this->mysqli->connect_errno)
      {
         $this->mysqli->close();
      }
   }

   /**
    * @desc Commit the transaction
    * @return <bln>
    */
   public function commit()
   {
      $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") commit\nTRANSACTION COMMIT";
      $this->error_handling($write);
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
    * @desc Print error and logs into file
    * @param  $string The   string to write
    * @param  $bln    Force the method to write to log to file and quit
    * @return null
    */
   public function error_handling($error_msg = "", $force_write = false)
   {
      if (strlen(trim($error_msg)) == 0)
      {
         return;
      }
      if ($force_write && strlen(trim($error_msg)) > 0)
      {
         $old_debug = $this->mysqli_debug;
         $this->mysqli_debug = true;
         $old_log_silent = $this->mysqli_log_silent;
         $this->mysqli_log_silent = true;
      }
      if (!$this->mysqli_log_silent)
      {
         ini_set('display_errors', 1);
         ini_set('display_startup_errors', 1);
         error_reporting(-1);
         echo "<br><pre>";
      }
      if ($this->mysqli_debug)
      {
         if (strlen(trim($this->mysqli_log_file)) == 0)
         {
            $this->mysqli_log_file = __DIR__ . "/";
         }
         $stop = 0;
         while (strcasecmp($this->mysqli_log_file, __DIR__ . "/") != 0 && !is_writable($this->mysqli_log_file) && $stop < 10)
         {
            $stop++;
            if (!$this->mysqli_log_silent)
            {
               echo "Cartella " . $this->mysqli_log_file . " non scrivibile,";
            }
            $temp_dir = explode("/", $this->mysqli_log_file);
            $temp_dir = array_map('trim', $temp_dir);
            $temp_dir = array_diff($temp_dir, array(''));
            array_splice($temp_dir, -1, 1);
            $this->mysqli_log_file = implode("/", $temp_dir);
            if (!$this->mysqli_log_silent)
            {
               echo " fallback su " . $this->mysqli_log_file . "\n\n";
            }
         }
         if (!is_writable($this->mysqli_log_file))
         {
            if (!$this->mysqli_log_silent)
            {
               echo "Impossibile creare il file di log, gli errori verranno stampati solo a schermo!\n\n";
            }
            $this->mysqli_debug = false;
         }
         else if ($this->mysqli_log_file_group)
         {
            $umask = umask(0);
            mkdir($this->mysqli_log_file . "logs", 0777);
            umask($umask);
            $this->mysqli_log_file = $this->mysqli_log_file . "logs/";
         }
         if (!is_file($this->mysqli_log_file))
         {
         $this->mysqli_log_file .= 'query.log';
         }
         if (file_exists($this->mysqli_log_file))
         {
            chmod($this->mysqli_log_file, 0777);
            if (!is_writable($this->mysqli_log_file))
            {
               $temp_log = @file_get_contents($this->mysqli_log_file);
               if (!unlink($this->mysqli_log_file))
               {
                  $this->mysqli_log_file = str_ireplace("query.log", "query_new.log", $this->mysqli_log_file);
               }
               file_put_contents($this->mysqli_log_file, $temp_log);
            }
            if (filesize($this->mysqli_log_file) > 1000000)
            {
               $mysqli_new_file = str_replace(".log", "_" . date("Y-m-d_H-i-s") . ".log", $this->mysqli_log_file);
               rename($this->mysqli_log_file, $mysqli_new_file);
            }
         }
         else
         {
            touch($this->mysqli_log_file);
         }
      }
      if ($force_write && strlen(trim($error_msg)) > 0)
      {
         file_put_contents($this->mysqli_log_file, $error_msg . "\n\n", FILE_APPEND);
         $this->mysqli_debug = $old_debug;
         $this->mysqli_log_silent = $old_log_silent;
         return;
      }
      if ($this->mysqli_debug && !$this->mysqli_log_silent)
      {
         debug_print_backtrace();
         echo "\n";
      }
      $debugarray = debug_backtrace();
      if (strlen(trim($error_msg)) > 0)
      {
         if ($this->mysqli_debug)
         {
            file_put_contents($this->mysqli_log_file, $error_msg . "\n", FILE_APPEND);
         }
         if (!$this->mysqli_log_silent)
         {
            echo $error_msg . "\n\n";
         }
      }
      for (($this->mysqli_debug) ? $i = 0 : $i = 1; $i < count($debugarray); $i++)
      {
         $error_string = "Errore alla riga " . $debugarray[$i]['line'] . " del file " . $debugarray[$i]['file'] . " (" . $debugarray[$i]['function'] . ")";
         if (!$this->mysqli_log_silent)
         {
            echo $error_string . "\n";
         }
         if ($this->mysqli_debug)
         {
            file_put_contents($this->mysqli_log_file, $error_string . "\n", FILE_APPEND);
         }
      }
      if ($this->mysqli_debug)
      {
         file_put_contents($this->mysqli_log_file, "\n", FILE_APPEND);
      }
      if (!$this->mysqli_log_silent)
      {
         echo "</pre>";
         exit();
      }
   }

   /**
    * @desc Escapes the parameters (either scalar or array)
    *
    * @param  <mixed>   $params The variable to escape
    * @return <mixed>
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
            return $this->mysqli->real_escape_string(trim($params));
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
      while ($row = $this->result->fetch_array($this->mysqli_fetch_mode))
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
    * @desc Returns the last query executed
    */
   public function get_last_query()
   {
      if ($this->mysqli_last_query != "")
      {
         return $this->mysqli_last_query;
      }
      else
      {
         return false;
      }
   }

   /**
    * @desc Simple preparation to execute the SQL query
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
      $this->SQL = trim($SQL);
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
            $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") query\n" . $file_path . "\n" . $this->SQL;
            $this->error_handling($write, true);
         }
         return true;
      }
      else
      {
         $this->mysqli_last_id = false;
         $this->mysqli_last_info = false;
         $this->mysqli_affected_rows = false;
         $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") query\n" . $file_path . "\nPROBLEM WITH QUERY: " . $this->SQL . "\n" . $this->mysqli->error . " ERRORE " . $this->mysqli->errno;
         $this->error_handling($write);
      }
   }

   /**
    * @desc Rollback the transaction
    * @return <bln>
    */
   public function rollback()
   {
      $write = date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") rollback\nTRANSACTION ROLLBACK";
      $this->error_handling($write);
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
               $this->mysqli_fetch_mode = MYSQLI_NUM;
               break;
            case 2:
               $this->mysqli_fetch_mode = MYSQLI_BOTH;
               break;
            default:
               $this->mysqli_fetch_mode = MYSQLI_ASSOC;
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
            $write = "\n\n" . date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") transaction\nTRANSACTION BEGIN";
            $this->error_handling($write);
            $this->mysqli_transaction_status = true;
            return $this->mysqli->autocommit(false);
         }
         else
         {
            $write = "\n\n" . date("d-m-Y H:i:s") . " (" . $_SERVER['REQUEST_URI'] . ") transaction\nTRANSACTION END";
            $this->error_handling($write);
            $this->mysqli_transaction_status = false;
            return $this->mysqli->autocommit(true);
         }
      }
   }
}