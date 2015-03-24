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
        $this->outputValidFormats = array('JSON');
        $this->outputSendHeader   = true;

        // --------------------------------------------------------------------------

        //  Work out the request method
        $this->requestMethod = $this->input->server('REQUEST_METHOD');
        $this->requestMethod = $this->requestMethod ? $this->requestMethod : 'GET';

        //  Work out the moduleName and method
        $this->moduleName = $this->uri->segment(2);
        $this->className  = $this->uri->segment(3);
        $this->method     = $this->uri->segment(4) ? $this->uri->segment(4) : 'index';

        //  Work out the format requested
        $formatPattern = '/\.([a-zA-Z]+)$/';
        preg_match($formatPattern, uri_string(), $matches);

        if (!empty($matches[1])) {

            $this->outputFormat = $matches[1];

        } else {

            $this->outputFormat = 'json';
        }

        //  Remove the format from the other strings, in case it got caught up
        $this->moduleName = preg_replace($formatPattern, '', $this->moduleName);
        $this->className  = preg_replace($formatPattern, '', $this->className);
        $this->method     = preg_replace($formatPattern, '', $this->method);

        //  Work out the parameters
        $pattern  = '#^api';
        $pattern .= $this->moduleName ? '/' . preg_quote($this->moduleName, '#') : '';
        $pattern .= $this->className ? '/' . preg_quote($this->className, '#') : '';
        $pattern .= $this->method ? '/' . preg_quote($this->method, '#') : '';
        $pattern .= '/(.*)';
        $pattern .= '(\.' . $this->outputFormat . ')?';
        $pattern .= '$#';

        if (preg_match($pattern, uri_string())) {

            preg_match_all($pattern, uri_string(), $matches);

            if (!empty($matches[1][0])) {

                $this->params = explode('/', $matches[1][0]);

            } else {

                $this->params = array();
            }

        } else {

            $this->params = array();
        }

        //  Remove the format from the params, it might be caught up in there too
        foreach ($this->params as &$param) {

            $param = preg_replace($formatPattern, '', $param);
        }
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

            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Headers: X-token, X-udid, content, origin, content-type' );
            header( 'Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS' );
            exit;
        }

        // --------------------------------------------------------------------------

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

                $moduleName = 'App\\Api\\' . ucfirst($this->moduleName) . '\\' . ucfirst($this->className);

                if (class_exists($moduleName)) {

                    if (!empty($moduleName::$requiresAuthentication) && !$this->user->isLoggedIn()) {

                        $output['status'] = 401;
                        $output['error']  = 'You must be logged in.';

                    } else {

                        //  Save a reference to $this, so that API controllers can interact with the router
                        $this->data['apiRouter'] = $this;

                        //  New instance of the controller
                        $instance = new $moduleName();

                        /**
                         * We need to look for the appropriate method; we'll look int he following order:
                         *
                         * - {requestMethod}Remap()
                         * - {requestMethod}{method}()
                         * - anyRemap()
                         * - any{method}()
                         */

                        $methods = array(
                            strtolower($this->requestMethod) . 'Remap',
                            strtolower($this->requestMethod) . ucfirst($this->method),
                            'anyRemap',
                            'any' . ucfirst($this->method)
                        );

                        $didFindRoute = false;

                        foreach ($methods as $methodName) {

                            if (is_callable(array($instance, $methodName))) {

                                $didFindRoute = true;
                                $output       = call_user_func_array(array($instance, $methodName), $this->params);
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

            $output['status'] = 400;
            $output['error']  = '"' . $this->outputFormat . '" is not a valid format.';
            $this->outputFormat     = 'JSON';
        }

        $this->output($output);
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
        switch (strtoupper($this->outputFormat)) {

            case 'JSON':

                $out = $this->outputJson($out);
                break;
        }

        $this->output->set_output($out);
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

            $this->outputFormat = $format;
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
