<?php

/**
 *
 * @author Emiliano Noli <noliemiliano@gmail.com>
 * @package GTE_Renapi_Api
 */

namespace RestEnApi;

class Renapi
{
    private $service_name;
    private $requested_method = null;
    private $functions = array();
    private $function_names = array();
    private $parameters = array();
    private $parameters_received_from_request_uri = array();
    private $parameters_from_uri = false;
    private $json_error = true;
    public $model = "";
    private $sendResponse;

    /**
     * Instancia Renapi.
     * Setea el nombre y los actions y models dispoible.
     *
     * @param json $config
     * @param string $name
     */
    public function __construct($config, $name = "Renapi api server")
    {

        $this->service_name = $name;
        $this->baseUri = $config->uri;
        $this->sendResponse = array($this, 'sendResponseFunction');
        $this->setRequestedModelAndFunction();
        $this->setActions($config);
    }

    /**
     * inicia el servicio y ejecuta
     *
     * @return void
     */
    public function start()
    {
        if ($this->posibleInjection()) {
            print RenapiError::injectionError();
        }

        if (!isset($_SERVER['REQUEST_METHOD'])) {
            print RenapiError::genericError("No se encontro ningun metodo en la llamada (GET/POST/PUT/DELETE)");
            die();
        }
        if (in_array($this->requested_function, $this->function_names)) {
            $function = $this->functions[$this->requested_function];

            if ($this->requested_method != $function->method()) {
                print RenapiError::methodCallError($function, $this->requested_method);
                die();
            }
            $parameters = $function->parameters();

            if ($this->getHeader('Content-Type') == "application/json") {
                $this->prepareJsonParameter($function);
            } else {
                $this->prepareParameters($function);
            }
        } else {
            print RenapiError::invalidFunctionError($this->requested_function);
            die();
        }
        $this->parameters["sendResponse"] = $this->sendResponse;
        call_user_func_array($this->requested_function, $this->parameters);
    }
    /**
     * Callable from user defined functions. User defined function last paramater
     *  if $die == true, stops script execution
     * @param [object] $json
     * @param [bool] $die
     * @return void
     */
    public function sendResponseFunction($json, $die = true)
    {
        print json_encode($json);
        if ($die) {
            die();
        }

    }

    /**
     * Gets the requested function from RQUEST_URI
     *
     * @return void
     */
    private function setRequestedModelAndFunction()
    {
        $script_name = str_replace('.php', '/', $_SERVER['SCRIPT_NAME']);
        $this->requested_method = $_SERVER['REQUEST_METHOD'];
        $method = strtolower($this->requested_method);

        $rURI = (isset($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : $_SERVER['REQUEST_URI']);
        // $rURI .= (isset($_SERVER['REDIRECT_QUERY_STRING']) ? $_SERVER['REDIRECT_QUERY_STRING'] : "");

        $arr = explode($script_name, $rURI);
        if (count($arr) > 1) {
            //Modificar para volver a usar el home -> asi no está funcionando.
            //uri= http://ip/api/model/function/getParameter
            //$arr[0] = model, $arr[1] = function, $arr[2] = parameter (GET/DELETE Only)

            $arr = explode('/', $arr[1]);
            $this->model = "api";
            $fname = "describe";
            if (count($arr) > 0) {
                $this->model = $arr[0] == "" ? "api" : $arr[0];
            }
            if (count($arr) > 1) {
                $fname = $arr[1] == "" ? "describe" : $arr[1];
            }
            if (count($arr) > 2) {
                $this->parameters_received_from_request_uri = explode('/', $arr[2]);
                $this->parameters_from_uri = true;
            }
        } else {
            $fname = "describe";
        }
        $this->requested_function = $fname;
    }

    /**
     * Search for an specific header and returns its value or false.
     *
     * @param [string] $h
     * @return string
     */
    private function getHeader($h)
    {
        /** apache_request_headers alias - native PHP fucntion */
        foreach (getallheaders() as $header => $value) {
            if ($header == $h) {
                return $value;
            }

        }
        return false;
    }
    /**
     * Imprime el error en el formato especificado para el servicio (Plano o Json) y corta la ejecucion.
     * @param string $messaje
     * @param mixed $codigo
     * @return void
     */
    public function error()
    {
        if ($this->json_error) {
            $error = array("error" => true, "code" => $codigo, "description" => $messaje);
            print json_encode($error);
        } else {
            print $messaje;
        }
        die();
    }

    /**
     * imprime pantalla con informacion de los metodos
     * deprecated
     * @return void
     */
    private function printHome()
    {
        include "home.php";
    }
    /**
     * Si el header Content-Type:application/json está presente, toma el json del body y lo envia a la función definida.
     * @param RenapiFunction $function
     * @return array
     */

    public function prepareJsonParameter($function)
    {
        $entityBody = file_get_contents('php://input');
        if ($entityBody == "") {
            $this->parameters[] = '';
        } elseif ($this->validateParameter($entityBody, 'json')) {
            $this->parameters[] = $entityBody;
        } else {
            print RenapiError::invalidParameterTypeError($name, $type);
            die();
        }
    }
    /**
     * Setea el valor de cada parametro obtenido por GET o POST
     * Si hay parametros demas no los tiene en cuenta, si faltan parametros devuelve error.
     * @param RenapiFunction $function
     * @return array
     */
    public function prepareParameters($function)
    {

        // Methods PUT, PATCH y DELETE tambien vienen por el body
        if (in_array($this->requested_method, ["PUT", "DELETE"])) {
            /**
             *  Revisar, ya que si el body es un form data, no estoy tomandolo bien.
             */
            $bodyContent = file_get_contents('php://input');
            $param_received = 0;
        } else {
            $param_received = (count($_REQUEST) == 0 ? count($this->parameters_received_from_request_uri) : count($_REQUEST));
            $_PARAMS = (count($_REQUEST) == 0 ? $this->parameters_received_from_request_uri : $_REQUEST);
        }

        $function_param_count = count($function->parameters());
        if ($param_received < $function_param_count) {
            print RenapiError::paramaterCountError($function->name(), $function_param_count, $param_received, $_PARAMS);
            die();
        }
        if ($this->parameters_from_uri) {
            /** Parameters from REQUEST_URI */
            $this->validateParameterByKey($function->parameters(), $_PARAMS);
            $this->validateParameterByKey($function->optionalParameters(), $_PARAMS);
        } else {
            /** Parameters from GET/POST */
            foreach ($_PARAMS as $name => $value) {
                if (!array_key_exists($name, $function->parameters()) && !array_key_exists($name, $function->optionalParameters())) {
                    print RenapiError::invalidParameterError($name, $function->name());
                    die();
                }
            }
            //Valido que los parametros tengan el formato correspondiente.
            $this->validateParameterByName($function->parameters(), $_PARAMS);
            // Si no llegaron todos los parametros.
            // Valido nuevamente, por si llego la cantidad necesaria pero con nombres distintos.
            $param_added = count($this->parameters);
            if ($param_added < $function_param_count) {
                print RenapiError::paramaterCountError($function->name(), $function_param_count, $param_added, $this->parameters);
                die();
            }
            // Ingreso los parametros Opcionales si los hubiese.
            $this->validateParameterByName($function->optionalParameters(), $_PARAMS);
        }
    }
    /**
     * Valida los paramentros revibidos por GET/POST con los valores definidos en la función, segun el nombre del parametro.
     * @param array $functionParams
     * @param array $requestValues
     */
    private function validateParameterByName($functionParams, $requestValues)
    {
        foreach ($functionParams as $name => $type) {
            if (isset($requestValues[$name])) {
                $value = $requestValues[$name];
                if ($this->validateParameter($value, $type)) {
                    $this->parameters[$name] = $value;
                } else {
                    print RenapiError::invalidParameterTypeError($name, $type);
                    die();
                }
            }
        }
    }
    /**
     * Valida los paramentros revibidos por GET/POST con los valores definidos en la función, segun el nombre del parametro.
     * @param array $functionParams
     * @param array $requestValues
     */
    private function validateParameterByKey($functionParams, $requestValues)
    {
        $i = 0;
        foreach ($functionParams as $name => $type) {
            $value = $requestValues[$i];
            if ($this->validateParameter($value, $type)) {
                $this->parameters[$i] = $value;
            } else {
                print RenapiError::invalidParameterTypeError($name, $type);
                die();
            }
            $i++;
        }

    }

    /**
     *  Valida que el parametro tenga el tipo especificado para la funcion. Int/String/Array/bool
     * @param string $value
     * @param string $type
     * @return boolean
     */
    public function validateParameter($value, $type)
    {
        /** Si el parametro llega por query string, en $type llega el type, sino llega un objeto {name:"", type:""} */
        if (is_object($type)) {$type = $type->type;}
        $ret = true;
        switch (strtolower($type)) {
            case "array":$ret = is_array($value);
                break;
            case "int":$ret = filter_var($value, FILTER_VALIDATE_INT);
                break;
            case "integer":$ret = filter_var($value, FILTER_VALIDATE_INT);
                break;
            case "numeric":$ret = is_numeric($value);
                break;
            case "long":$ret = filter_var($value, FILTER_VALIDATE_LONG);
                break;
            case "double":$ret = is_double($value);
                break;
            case "float":$ret = filter_var($value, FILTER_VALIDATE_FLOAT);
                break;
            case "string":$ret = is_string($value);
                break;
            case "bool":$ret = filter_var($value, FILTER_VALIDATE_BOOL);
                break;
            case "email":$ret = filter_var($value, FILTER_VALIDATE_EMAIL);
                break;
            case "ip":$ret = filter_var($value, FILTER_VALIDATE_IP);
                break;
            case "mac":$ret = filter_var($value, FILTER_VALIDATE_MAC);
                break;
            case "url":$ret = filter_var($value, FILTER_VALIDATE_URL);
                break;
            case "json":json_decode($value);
                $ret = (json_last_error() === JSON_ERROR_NONE ? true : false);
                break;
            default:$ret = false;
                break;
        }
        return $ret;
    }
    /**
     * Recibe el config.json con los models y actions definidas y registra las funciones al server.
     *
     * @param [object] $config
     * @return void
     */
    private function setActions($config)
    {
        // $parameters = null;
        // $description = "This function returns json with api describe methods";
        // $returnDescription = "Json";
        // $function = new RenapiFunction("describe", "GET",$parameters,$returnDescription,$description);
        // $server->registerFunction($function);
        foreach ($config->models as $model) {

            if ($this->model == $model->name) {
                foreach ($model->actions as $action) {

                    $parameters = isset($action->params) ? $action->params : null;
                    $description = isset($action->description) ? $action->description : null;
                    $returnDescription = isset($action->returnDescription) ? $action->returnDescription : null;
                    $function = new RenapiFunction($action->name, $action->method, $parameters, $description, $returnDescription);
                    $this->registerFunction($function);
                }
            }
        }
    }
    /**
     * Registra una funcion en la api, si esta mal configurada devuelve el error correspondiente.
     * @param RenapiFunction $function
     */
    public function registerFunction($function)
    {
        if ($function->isValid() == false) {
            die($function->message());
        } else {
            $this->functions[$function->name()] = $function;
            $this->function_names[] = $function->name();
        }
    }
    /**
     * Detects posible query injection
     * It's too old, i'm not sure about this
     *
     * @return void
     */
    private function posibleInjection()
    {
        foreach ($_REQUEST as $key => $value) {
            if ($value != "") {
                if (strpos($value, ";") > -1) {
                    return true;
                }

                if (strpos($value, "--") > -1) {
                    return true;
                }

                if (strpos($value, "//") > -1) {
                    return true;
                }

                if (strpos($value, "');") > -1) {
                    return true;
                }

                if (strpos($value, '");') > -1) {
                    return true;
                }

                if (strpos($value, ");") > -1) {
                    return true;
                }

                if (strpos($value, ")") > -1) {
                    return true;
                }

                if (strpos($value, "(") > -1) {
                    return true;
                }

                if (strpos($value, "/*") > -1) {
                    return true;
                }

                if (strpos($value, "*/") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "xp_") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "call ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), " table") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), " column") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), " field") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "drop ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "truncate ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "delete ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "update ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "create ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "alert") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "insert ") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "insert into") > -1) {
                    return true;
                }

                if (strpos(strtolower($value), "select ") > -1) {
                    return true;
                }

                if (strpos($value, "*") > -1) {
                    return true;
                }

            }
        }
        return false;
    }
}

/**
 * @author Emiliano Noli <noliemiliano@gmail.com>
 * @package GTE_Renapi_Api
 */
class RenapiFunction
{
    /**
     * Nombre del metodo.
     * @var string
     */
    private $name;
    /**
     * Descripcion del metodo.
     * @var string
     */
    public $description;
    /**
     * Descripcion de la devolucion del metodo.
     * @var string
     */
    public $return_description;
    private $isValid = true;
    private $message = "";
    /**
     * metodo * 0-GET | 1-POST | 2-PUT | 3-DELETE | 4-PATCH
     * @var string
     */
    private $method;
    private $valid_method = array("GET", "POST", "PUT", "DELETE", "PATCH");
    /**
     * array que define los parametros y sus tipos array($parameter_name => $parameter_tipo);
     * @var array
     */
    private $parameters = array();
    /**
     * array que define los parametros opcionales y sus tipos array($parameter_name => $parameter_tipo);
     * @var array
     */
    private $optionalParameters = array();

/**
 * @param string $name
 * @param string $method default GET
 * @param array $parameters array($parameter_name => $parameter_tipo, n => n);
 * @param string $return_description -> tipo de retorno. (text,json, etc)
 * @param string $description
 */
    public function __construct($name, $method = "GET", $parameters = null, $return_description = null, $description = null)
    {
        $this->name($name);
        $this->method($method);
        $this->parameters($parameters);
        $this->return_description = $return_description;
        $this->description = $description;
    }
    /**
     * Obtiene o establece el nombre del metodo.
     * @param string $name
     * @return
     */
    public function name($name = null)
    {
        if (is_null($name)) {return $this->name;}
        if (!is_string($name) || is_int(substr($name, 0, 1)) || $name == "") {
            $this->setMessageAndValueState(false, "Invalid function name");
        } else { $this->name = $name;}
    }
    /**
     * Devuelve ó establece los parametros y sus tipos.
     *
     * Ej: array($parameter_name => $parameter_tipo, n => n);
     * @param array $parameters
     */
    public function parameters($parameters = null)
    {
        if (is_null($parameters)) {return $this->parameters;}
        if (!is_array($parameters)) {
            $this->setMessageAndValueState(false, "Invalid parameters types");
        }
        $this->parameters = $parameters;
    }
    /**
     * Establece los parametros opcionales y sus tipos.
     * Ej: array($parameter_name => $parameter_tipo, n => n);
     * @param array $parameters
     */
    public function optionalParameters($parameters = null)
    {
        if (is_null($parameters)) {return $this->optionalParameters;}
        if (!is_array($parameters)) {
            $this->setMessageAndValueState(false, "Invalid optional parameters types");
        }
        $this->optionalParameters = $parameters;
    }
    /**
     * Obtiene o establece el metodo por el cual tomara los parametros.
     * 0-GET | 1-POST | 2-PUT | 3-DELETE | 4-PATCH
     * @param mixed $method
     * @return type
     */
    public function method($method = null)
    {
        if (is_null($method)) {return $this->method;}
        if (is_int($method)) {$this->method = $this->valid_method[$method];}
        if (in_array(strtoupper($method), $this->valid_method)) {
            $this->method = strtoupper($method);
        }
    }
    /**
     * Obtiene los Http Verbs validos
     * 0-GET | 1-POST | 2-PUT | 3-DELETE | 4-PATCH
     * @return array
     */
    public function validMethods()
    {
        return $this->valid_method;
    }
    /**
     * Devuleve mensaje de error si lo hubiera.
     * @return string
     */
    public function message()
    {
        return "Funcion: {$this->name}<br/>{$this->message}";
    }
    /**
     * Indica si la funcion es valida o tiene algun error.
     * @return bool
     */
    public function isValid()
    {return $this->isValid;}

    private function setMessageAndValueState($valid, $message)
    {
        if ($this->isValid == true) {$this->isValid = $valid;}
        $this->message .= "{$message}<br/>";
        // die($this->message);
    }
}

/**
 * @author Emiliano Noli <noliemiliano@gmail.com>
 * @package GTE_Renapi_Api
 */
class RenapiError
{

    public static function invalidFunctionError($name)
    {
        $code = 0;
        $message = "Function '{$name}' does not exists.";
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }
    public static function methodCallError($function, $called_method)
    {
        $method = $function->method();
        $name = $function->name();
        $code = 1;
        $message = "Function '{$name}' can only be called by {$method} and was called by {$called_method}.";
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }
    public static function paramaterCountError($fname, $function_parameters_count, $received_parameters, $parameters)
    {
        $code = 2;
        $message = "The function {$fname} expects {$function_parameters_count} parameters but received {$received_parameters}.";
        if ($received_parameters > 0) {
            $message .= " Parameters received:  ";
            foreach ($parameters as $name => $type) {
                if ($name != "function") {$message .= " - " . $name;}
            }
        }
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }

    public static function invalidParameterError($parameter_name, $function_name)
    {
        $code = 3;
        $message = "{$parameter_name} is not a valid parameter for {$function_name}.";
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }
    public static function invalidParameterTypeError($name, $type)
    {
        /** Si el parametro llega por query string, en $type llega el type, sino llega un objeto (name:"", type:"") */
        if (is_object($type)) {
            $name = $type->name;
            $type = $type->type;
        }
        $code = 4;
        $message = "Parameter {$name} has an invalid type. Expected type: {$type}";
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }

    public static function injectionError()
    {
        $code = 5;
        $message = "Received values may be potentially dangerous to the system.";
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }

    public static function genericError($message = "Undefined error")
    {
        $code = 6;
        $error = array("error" => true, "code" => $code, "description" => $message);
        return json_encode($error);
    }
}
