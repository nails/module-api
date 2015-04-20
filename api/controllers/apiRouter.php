<?php

/**
 * Routes requests to the API to the appropriate controllers
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

class ApiRouter extends Nails_Controller
{
    private $requestMethod;
    private $moduleName;
    private $className;
    private $method;
    private $params;
    private $outputValidFormats;
    private $outputFormat;
    private $outputSendHeader;

    // --------------------------------------------------------------------------

    /**
     * Constructs the router, defining the request variables
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Set defaults
        $this->outputValidFormats = array(
            'TXT',
            'JSON'
        );
        $this->outputSendHeader = true;

        // --------------------------------------------------------------------------

        //  Work out the request method
        $this->requestMethod = $this->input->server('REQUEST_METHOD');
        $this->requestMethod = $this->requestMethod ? $this->requestMethod : 'GET';

        /**
         * In order to work out the next few parts we'll analyse the URI string manually.
         * We're doing this ebcause of the optional return type at the end of the string;
         * it's easier to regex that quickly,r emove it, then split up the segments.
         */

        $uriString = uri_string();

        //  Get the format, if any
        $formatPattern = '/\.([a-z]*)$/';
        preg_match($formatPattern, $uriString, $matches);

        if (!empty($matches[1])) {

            $this->outputFormat = strtoupper($matches[1]);

            //  Remove the format from the string
            $uriString = preg_replace($formatPattern, '', $uriString);

        } else {

            $this->outputFormat = 'JSON';
        }

        //  Remove the module prefix (i.e "api/") then explode into segments
        $uriString = substr($uriString, 4);
        $uriArray  = explode('/', $uriString);

        //  Work out the moduleName, className and method
        $this->moduleName = array_key_exists(0, $uriArray) ? $uriArray[0] : null;
        $this->className  = array_key_exists(1, $uriArray) ? $uriArray[1] : $this->moduleName;
        $this->method     = array_key_exists(2, $uriArray) ? $uriArray[2] : 'index';

        //  What's left of the array are the parameters to pass to the method
        $this->params = array_slice($uriArray, 3);
    }

    // --------------------------------------------------------------------------

    /**
     * Route the call to the correct place
     * @return Void
     */
    public function index()
    {
        //  Handle OPTIONS CORS preflight requests
        if ($this->requestMethod === 'OPTIONS') {

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: X-accesstoken, content, origin, content-type');
            header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            exit;

        } else {

            /**
             * If an access token has been passed then verify it
             *
             * Passing the token via the header is preferred, but fallback to the GET
             * and POST arrays.
             */

            $accessToken = $this->input->get_request_header('X-accesstoken');

            if (!$accessToken) {

                $accessToken = $this->input->get_post('accessToken');
            }

            if ($accessToken) {

                $this->load->model('auth/user_access_token_model');

                $accessToken = $this->user_access_token_model->getByValidToken($accessToken);

                if ($accessToken) {

                    $this->user_model->setLoginData($accessToken->user_id, false);
                }
            }

            // --------------------------------------------------------------------------

            $output = array();

            if ($this->outputSetFormat($this->outputFormat)) {

                /**
                 * Look for a controller, app version first then the first one we
                 * find in the modules.
                 */
                $controllerPaths = array(
                    FCPATH . APPPATH . 'modules/api/controllers/'
                );

                $nailsModules = _NAILS_GET_MODULES();

                foreach ($nailsModules as $module) {

                    $controllerPaths[] = $module->path . 'api/controllers/';
                }

                //  Look for a valid controller
                $controllerName = ucfirst($this->className) . '.php';

                foreach ($controllerPaths as $path) {

                    $fullPath = $path . $controllerName;

                    if (is_file($fullPath)) {

                        $controllerPath = $fullPath;
                        require_once NAILS_PATH . 'module-api/api/controllers/apiController.php';
                        break;
                    }
                }

                if (!empty($controllerPath)) {

                    //  Load the file and try and execute the method
                    require_once $controllerPath;

                    $moduleName = 'Nails\\Api\\' . ucfirst($this->moduleName) . '\\' . ucfirst($this->className);

                    if (class_exists($moduleName)) {

                        if (!empty($moduleName::$requiresAuthentication) && !$this->user->isLoggedIn()) {

                            $output['status'] = 401;
                            $output['error']  = 'You must be logged in.';
                        }

                        /**
                         * If no errors and a scope is required, check the scope
                         */
                        if (empty($output) && !empty($moduleName::$requiresScope)) {

                            if (empty($accessToken->scope) || !in_array($moduleName::$requiresScope, $accessToken->scope)) {

                                $output['status'] = 401;
                                $output['error']  = '"' . $moduleName::$requiresScope . '" scope is required.';
                            }
                        }

                        /**
                         * If no errors so far, begin execution
                         */
                        if (empty($output)) {

                            //  Save a reference to $this, so that API controllers can interact with the router
                            $this->data['apiRouter'] = $this;

                            //  New instance of the controller
                            $instance = new $moduleName();

                            /**
                             * We need to look for the appropriate method; we'll look in the following order:
                             *
                             * - {requestMethod}Remap()
                             * - {requestMethod}{method}()
                             * - anyRemap()
                             * - any{method}()
                             *
                             * The second parameter is whether the method is a remap method or not.
                             */

                            $methods = array(
                                array(
                                    strtolower($this->requestMethod) . 'Remap',
                                    true
                                ),
                                array(
                                    strtolower($this->requestMethod) . ucfirst($this->method),
                                    false
                                ),
                                array(
                                    'anyRemap',
                                    true
                                ),
                                array(
                                    'any' . ucfirst($this->method),
                                    false
                                )
                            );

                            $didFindRoute = false;

                            foreach ($methods as $methodName) {

                                if (is_callable(array($instance, $methodName[0]))) {

                                    /**
                                     * If the method we're trying to call is a remap method, then the first
                                     * param should be the name of the method being called
                                     */

                                    if ($methodName[1]) {

                                        $params = array_merge(array($this->method), $this->params);

                                    } else {

                                        $params = $this->params;
                                    }

                                    $didFindRoute = true;
                                    $output       = call_user_func_array(array($instance, $methodName[0]), $params);
                                    break;

                                }
                            }

                            if (!$didFindRoute) {

                                $output['status'] = 404;
                                $output['error']  = '"' . $this->requestMethod . ': ' . $this->moduleName . '/';
                                $output['error'] .= $this->className . '/' . $this->method . '" is not a valid API route.';
                            }
                        }

                    } else {

                        $output['status'] = 500;
                        $output['error']  = '"' . $this->moduleName . '" is incorrectly configured.';
                    }

                } else {

                    $output['status'] = 404;
                    $output['error']  = '"' . $this->moduleName . '/' . $this->method . '" is not a valid API route.';
                }

            } else {

                $output['status']   = 400;
                $output['error']    = '"' . $this->outputFormat . '" is not a valid format.';
                $this->outputFormat = 'JSON';
            }

            $this->output($output);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sends $out to the browser in the desired format
     * @param  array $out The data to output to the browser
     * @return void
     */
    protected function output($out = array())
    {
        //  Set cache headers
        $this->output->set_header('Cache-Control: no-store, no-cache, must-revalidate');
        $this->output->set_header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        $this->output->set_header('Pragma: no-cache');

        //  Set access control headers
        $this->output->set_header('Access-Control-Allow-Origin: *');
        $this->output->set_header('Access-Control-Allow-Headers: X-accesstoken, content, origin, content-type');
        $this->output->set_header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');

        $serverProtocol = $this->input->server('SERVER_PROTOCOL');

        // --------------------------------------------------------------------------

        //  Send the correct status header, default to 200 OK
        if (isset($out['status'])) {

            $out['status'] = (int) $out['status'];

            switch ($out['status']) {

                case 400:

                    $headerString = '400 Bad Request';
                    break;

                case 401:

                    $headerString = '401 Unauthorized';
                    break;

                case 404:

                    $headerString = '404 Not Found';
                    break;

                case 500:

                    $headerString = '500 Internal Server Error';
                    break;

                default:

                    $headerString = '200 OK';
                    break;

            }

        } elseif (is_array($out)) {

            $out['status'] = 200;
            $headerString  = '200 OK';

        } else {

            $headerString = '200 OK';
        }

        if ($this->outputSendHeader) {

            $this->output->set_header($serverProtocol . ' ' . $headerString);
        }

        // --------------------------------------------------------------------------

        //  Output content
        switch ($this->outputFormat) {

            case 'TXT':

                $out = $this->outputTxt($out);
                break;

            case 'JSON':

                $out = $this->outputJson($out);
                break;
        }

        $this->output->set_output($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $out as a plain text string formatted as JSON (for easy reading)
     * but a plaintext contentType
     * @param  array $out The result of the API call
     * @return string
     */
    private function outputTxt($out)
    {
        $this->output->set_content_type('text/html');
        return defined('JSON_PRETTY_PRINT') ? json_encode($out, JSON_PRETTY_PRINT) : json_encode($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $out as a JSON string
     * @param  array $out The result of the API call
     * @return string
     */
    private function outputJson($out)
    {
        $this->output->set_content_type('application/json');
        return defined('JSON_PRETTY_PRINT') ? json_encode($out, JSON_PRETTY_PRINT) : json_encode($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the output format
     * @param  string $format The format to use
     * @return boolean
     */
    public function outputSetFormat($format)
    {
        if ($this->isValidFormat($format)) {

            $this->outputFormat = strtoupper($format);
            return true;
        }

        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets whether the status header shoud be sent or not
     * @param  boolean $sendHeader Whether the ehader should be sent or not
     * @return void
     */
    public function outputSendHeader($sendHeader) {

        $this->outputSendHeader = !empty($sendHeader);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the format is valid
     * @param string The format to check
     * @return boolean
     */
    private function isValidFormat($format) {

        return in_array(strtoupper($format), $this->outputValidFormats);
    }
}
