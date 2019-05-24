<?php
/**
* stdObject Class
*  
* Usage:
*   $obj = new stdObject();
*   $obj->name = "Nick";
*   $obj->surname = "Doe";
*   $obj->age = 20;
*   $obj->adresse = null;
*   $obj->getInfo = function($stdObject) { // $stdObject referred to this object (stdObject).
*       echo $stdObject->name . " " . $stdObject->surname . " have " . $stdObject->age . " yrs old. And live in " . $stdObject->adresse;
*   };
*   $func = "setAge";
*   $obj->{$func} = function($stdObject, $age) { // $age is the first parameter passed when calling this method.
*       $stdObject->age = $age;
*   };
*   $obj->setAge(24); // Parameter value 24 is passing to the $age argument in method 'setAge()'.
*   // Create dynamic method. Here i'm generating getter and setter dynimically Beware: Method name are case sensitive.
*   foreach ($obj as $func_name => $value) {
*       if (!$value instanceOf Closure) {
*           $obj->{"set" . ucfirst($func_name)} = function($stdObject, $value) use ($func_name) {  // Note: you can also use keyword 'use' to bind parent variables.
*               $stdObject->{$func_name} = $value;
*           };
*           $obj->{"get" . ucfirst($func_name)} = function($stdObject) use ($func_name) {  // Note: you can also use keyword 'use' to bind parent variables.
*               return $stdObject->{$func_name};
*           };
*       }
*   }
*   $obj->setName("John");
*   $obj->setAdresse("Boston");
*   $obj->getInfo();
*/
class stdObject
{
	public function __construct(array $arguments = array())
	{
		if (!empty($arguments)) {
			foreach ($arguments as $property => $argument) {
				$this->{$property} = $argument;
			}
		}
	}

	public function __call($method, $arguments)
	{
		$arguments = array_merge(array("stdObject" => $this), $arguments); // Note: method argument 0 will always referred to the main class ($this).
		if (isset($this->{$method}) && is_callable($this->{$method})) {
			return call_user_func_array($this->{$method}, $arguments);
		} else {
			throw new Exception("Fatal error: Call to undefined method stdObject::{$method}()");
		}
	}
}
